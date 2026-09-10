#!/usr/bin/env node
import { Server } from '@modelcontextprotocol/sdk/server/index.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import { CallToolRequestSchema, ListToolsRequestSchema } from '@modelcontextprotocol/sdk/types.js';
import { readFileSync, realpathSync } from 'node:fs';
import { execFile } from 'node:child_process';
import { platform } from 'node:os';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const PKG_VERSION = JSON.parse(
  readFileSync(join(dirname(fileURLToPath(import.meta.url)), 'package.json'), 'utf8')
).version;

const API_BASE = (process.env.REMOTIFY_URL  || 'https://relay.remotify.run').replace(/\/$/, '');
const PRESET   =  process.env.REMOTIFY_KEY  || null;
// NaN from a typo'd value ("5s", "off") would turn the wait loops into hot spins.
function envInt(name, fallback) {
  const n = parseInt(process.env[name] ?? '', 10);
  return Number.isFinite(n) && n >= 0 ? n : fallback;
}
const CHUNK_MS      = envInt('REMOTIFY_CHUNK_MS',      5000); // per-call blocking budget before returning [pending]
const MAX_CHUNKS    = envInt('REMOTIFY_MAX_CHUNKS',      24); // ceiling on UNPRODUCTIVE waits (in-flight time is refunded), not total time
const POLL_MS       = envInt('REMOTIFY_POLL_MS',        500);
// Per-fetch network timeouts. Without them a stalled TCP connection freezes a
// whole tool call indefinitely (the exact "stuck on a tool call" symptom). The
// result poll must exceed the relay's own long-poll window (LONGPOLL_MS,
// default 15s) plus slack; quick calls (status/push/session) return at once.
const RESULT_TIMEOUT_MS = envInt('REMOTIFY_RESULT_TIMEOUT_MS', 30000);
const QUICK_TIMEOUT_MS  = envInt('REMOTIFY_QUICK_TIMEOUT_MS',  10000);
// Absolute ceiling on time a command may sit "in flight" on the remote before
// the MCP stops waiting. The runner's interrupt handler covers Ctrl+C, but a
// hard kill (kill -9 / crash / reboot) leaves the relay's phase marker stuck;
// without this the tool would loop [in-flight] forever. 0 disables. Default
// 30 min comfortably covers mongodump / restic / rsync / big installs.
const MAX_INFLIGHT_MS   = envInt('REMOTIFY_MAX_INFLIGHT_MS', 1800000);
// A listener heartbeat NEWER than the in-flight start proves the in-flight
// command's executor is gone: an executing (or supervised-prompt-waiting)
// listener never polls /cmd, so any poll after pickup means the executor died
// and no result is coming. Genuine runs keep heartbeat_age >= inflight_age, so
// the difference never goes positive; the grace only absorbs mtime jitter.
// Lets a wedge clear in seconds instead of waiting out MAX_INFLIGHT_MS.
const STALE_GRACE_S     = envInt('REMOTIFY_STALE_GRACE_S', 30);
// Operator-visibility side channels. Instructions inside a tool result only
// work when the model obeys them, and models demonstrably do not: they relay
// the connect one-liners between tool calls (which hosts collapse) or not at
// all, then keep polling for a listener nobody knows how to start. This MCP
// runs on the operator's own machine, so it can put the lines in front of
// them itself: a desktop notification (both lines) and the clipboard (the
// supervised line, ready to paste). Fired on session mint and on every
// response whose only exit is the operator pasting a line, throttled per key.
// Best-effort and silent: a headless box without notify-send/xclip loses
// nothing. REMOTIFY_NOTIFY=0 / REMOTIFY_CLIPBOARD=0 opt out.
const NOTIFY_ON    = (process.env.REMOTIFY_NOTIFY    ?? '1') !== '0';
const CLIPBOARD_ON = (process.env.REMOTIFY_CLIPBOARD ?? '1') !== '0';
const NOTIFY_THROTTLE_MS = envInt('REMOTIFY_NOTIFY_THROTTLE_MS', 60000);

let session = null;
// Resumable state for a single in-flight command. Shared across chained tool
// calls so the LLM can loop remote_exec with the same args and the server
// transparently continues where the previous chunk left off, without
// re-queueing the command on the relay.
let inFlight = null; // { command, key, phase: 'pickup' | 'result', chunks }
// Set when a session was just renewed (preset 410 on cold start, or in-flight
// 410 mid-session). Surfaced into the next pendingMessage so the LLM cannot
// miss the key change - stderr-only logging proved invisible in Claude Code.
let renewNotice = null; // { oldKey, newKey } | null
// One-shot notice prepended to the next remote_exec response after an
// automatic unwedge (stale in-flight cleared), so the LLM always learns that
// recovery happened and that the dead command's output is lost.
let recoveryNotice = null; // string | null
let connectHintPendingFor = null; // session key | null

const sleep = (ms) => new Promise(r => setTimeout(r, ms));

// fetch with an AbortSignal timeout so a stalled connection can never hang a
// tool call forever. AbortSignal.timeout is available on Node 18+.
function fetchT(url, timeoutMs, init) {
  return fetch(url, { ...init, signal: AbortSignal.timeout(timeoutMs) });
}

async function fetchJSON(url, init) {
  let r;
  try { r = await fetchT(url, QUICK_TIMEOUT_MS, init); }
  catch (e) { throw unreachable(e); }
  if (r.status === 426) throw await movedError(r);
  // nginx answers a per-IP burst of session mints with 503 (limit_req).
  if (r.status === 503 || r.status === 429) {
    throw new Error(msg('relay_rate_limited', { api_base: API_BASE, status: r.status }));
  }
  if (!r.ok) {
    const err = new Error(`${init?.method || 'GET'} ${url} -> HTTP ${r.status}`);
    err.status = r.status;
    throw err;
  }
  try { return await r.json(); }
  catch {
    throw new Error(
      `The relay at ${API_BASE} answered HTTP ${r.status} but not JSON (a proxy or captive portal in the way?). ` +
      'Nothing was queued. Check REMOTIFY_URL.');
  }
}

function unreachable(e) {
  const timedOut = e?.name === 'TimeoutError' || e?.name === 'AbortError';
  return new Error(msg('relay_unreachable', {
    api_base: API_BASE,
    detail: timedOut ? `timed out after ${QUICK_TIMEOUT_MS}ms` : String(e?.message || e),
  }));
}

// A retired hostname answers with 426 plus {endpoint, action} (see php/app/index.php).
async function movedError(r) {
  let body = {};
  try { body = await r.json(); } catch { /* fall back to the generic wording */ }
  return new Error(msg('endpoint_moved', {
    api_base: API_BASE,
    endpoint: typeof body.endpoint === 'string' ? body.endpoint : '(not stated)',
    action:   typeof body.action   === 'string' ? body.action
                                                : 'update remotify-mcp or point REMOTIFY_URL at the new endpoint',
  }));
}

// Catch a non-session payload here instead of as a TypeError deep in the state machine.
function assertSessionShape(s) {
  const u = s?.urls;
  const ok = s && typeof s.key === 'string' && u
    && typeof u.cmd === 'string' && typeof u.result === 'string'
    && typeof u.api === 'string' && typeof u.runner === 'string';
  if (!ok) {
    throw new Error(
      `The relay at ${API_BASE} returned a session payload without the expected key/urls fields; ` +
      'nothing was queued. Check REMOTIFY_URL.');
  }
  return s;
}

// ---------------------------------------------------------------------------
// Agent-facing message templates.
//
// Every runtime string remote_exec returns to the LLM is rendered through
// msg(key, params) from the table below. The relay may serve replacements for
// any subset of keys on the session payload (`mcp_messages.templates`, see
// php/app/mcp-messages.json): wording iterations then deploy with the relay
// instead of requiring an npm re-publish, and even stale cached clients pick
// them up on their next session. The table here is the FALLBACK for older
// relays and offline failure modes - keep it working stand-alone.
//
// Deliberately NOT relay-served: tool names, schemas, and descriptions. Hosts
// and users grant approval based on those, and descriptions that mutate
// outside a versioned release are the documented "rug pull" pattern that MCP
// clients increasingly detect and flag. Descriptions change via npm releases.
//
// Placeholders are {snake_case} words; unknown placeholders render literally.
//
// php/app/mcp-messages.json is the source of truth; this table is a verbatim
// copy of it (test/server.test.js fails when the two drift).
const DEFAULT_MESSAGES = {
  session_info_intro:
    "[connect-the-remote] Session {key} on the relay. Nothing runs on the remote until a listener is connected, and the ONLY way to connect one is the user pasting ONE of these two lines on the remote shell:\n\n{runner_lines}\nThe supervised line asks the user y/N before every command. The auto line runs every command immediately without asking, for unattended hosts.\n\nShow BOTH lines to the user verbatim and unabridged, in a fenced code block, and make them the final message of your turn: hosts collapse text written between tool calls, so that is the only place the user reliably sees them. Do not wait for a listener before showing them. Session details follow.\n\n",
  relay_unreachable:
    "[relay-unreachable] Could not reach the remotify relay at {api_base} ({detail}). Nothing was queued and nothing ran. Check that the machine running this MCP server can reach that URL, or set REMOTIFY_URL to the relay you actually use. Do NOT fall back to ssh or any other transport.",
  relay_rate_limited:
    "[relay-busy] The relay at {api_base} answered HTTP {status} to a session request: it rate-limits session creation per address and this machine just exceeded that rate (a test run or several MCP restarts in a row). Nothing was queued and nothing ran. Wait about a minute, then call the tool again with the EXACT SAME arguments. Do NOT fall back to ssh or any other transport.",
  endpoint_moved:
    "[endpoint-moved] The relay at {api_base} no longer serves this API; it moved to {endpoint}. Nothing was sent and nothing can run. STOP and tell the user to {action}. Do not retry and do not fall back to ssh.",
  pickup_unreachable:
    "[pending] The command is queued but the relay stopped answering status probes (network or timeout), so pickup cannot be confirmed. Call remote_exec again with the EXACT SAME arguments to retry; do not re-word the command and do not fall back to ssh.",
  runner_lines:
    "  supervised (asks y/N for each command): curl -fsSL '{runner_url}' | bash\n  auto (runs every command immediately):  curl -fsSL '{runner_url}?mode=auto' | bash\n",
  pending_alive:
    "[pending] Remote listener is online (last seen {seen_ago}s ago) but has not picked up the command yet. Call remote_exec again with the EXACT SAME arguments. Do NOT ask the user anything; do NOT narrate progress between retries; do NOT try a different command. Surface a failure to the user only after about 10 consecutive [pending] responses.",
  pending_first:
    "[pending] Remote listener not connected. The command is queued on the relay and runs as soon as a listener connects. STOP: do not call remote_exec or any other tool again in this turn. End your turn with a short message that gives the user BOTH lines below, verbatim and unabridged, in a fenced code block, and asks them to paste ONE on the remote shell:\n\n{runner_lines}\nWhy end the turn: chat hosts collapse text written between tool calls (Claude Code shows it as a one-line summary), so the lines only reach the user when they are the last thing you output. When the user replies that the listener is running, call remote_exec again with the EXACT SAME arguments to collect the output; the command will not run twice.",
  pending_repeat:
    "[pending] Still no listener. The previous response told you to stop; the command stays queued and nothing can run until the user pastes ONE of these on the remote shell:\n\n{runner_lines}\nEND YOUR TURN NOW with BOTH lines verbatim in a fenced code block. Do not call any tool again until the user says the listener is running; then call remote_exec with the EXACT SAME arguments.",
  pending_tail:
    " Never work around the relay: do NOT fall back to ssh, scp, or any other transport, and do NOT try to start the listener yourself. The user chose remotify so that no agent gets shell access; the only way to run anything is the listener the user pastes.",
  renewed:
    "[session-renewed] Previous session {old_key} expired on the relay; minted new session {new_key}. Any listener that was running on {old_key} is now disconnected (it will exit on its next poll with \"session expired or unknown key\"). The runner one-liners below are for the new key: end your turn with BOTH lines verbatim in a fenced code block so the user can reconnect, and continue when they confirm.\n\n",
  unknown_result:
    "[unknown-result] The session expired while \"{command}\" was already running on the remote, so its output was lost and it may or may not have finished. A new session was minted. End your turn telling the user, with BOTH reconnect lines verbatim in a fenced code block:\n\n{runner_lines}\nDecide whether re-running the command is safe BEFORE issuing it again; the server will not silently re-run it for you.",
  recovered_notice:
    "[recovered] A stale command (likely from a previous session) was stuck in-flight on the relay for {elapsed}s {reason}. Its state was cleared automatically; its output is lost. Proceeding with your command.\n\n",
  recovered_reason_stale:
    "even though the listener was already polling for new work",
  recovered_reason_ceiling:
    "(past the {ceiling_min} min in-flight ceiling)",
  busy_reset_failed:
    "[busy] A stale in-flight command is wedging the session and the automatic clear did not go through (relay unreachable?). Call remote_exec again with the EXACT SAME arguments to retry the recovery.",
  busy_common:
    "[busy] A previous command is still executing on the remote ({elapsed}s elapsed). Do NOT issue a different command; its result would be misattributed to the new one. If you know the previous command, call remote_exec with ITS exact arguments first to drain its result, then issue this one. ",
  busy_alive_suffix:
    "If you do not know it (e.g. it was issued by an earlier session), keep calling remote_exec with THIS command's arguments: the stale state clears automatically as soon as the listener reconnects{ceiling_clause}.",
  busy_alive_ceiling_clause:
    " or after the {ceiling_min} min in-flight ceiling",
  busy_dead_suffix:
    "If you do not know it, note that the remote listener is NOT currently polling {heartbeat_phrase}, so this will NOT clear by itself{ceiling_clause}. End your turn telling the user that a leftover command is holding the session and that, IF nothing important is still running on the remote, they should reconnect the listener by pasting ONE of these on the remote shell (reconnecting clears the stale state automatically); give BOTH lines verbatim in a fenced code block:\n\n{runner_lines}\nWhen the user confirms, keep calling remote_exec with THIS command's arguments. Do NOT fall back to ssh or any other transport.",
  busy_dead_heartbeat_seen:
    "(last heartbeat {seen_ago}s ago)",
  busy_dead_heartbeat_none:
    "(no heartbeat recorded)",
  busy_dead_ceiling_clause:
    " before the {ceiling_min} min in-flight ceiling",
  probe_unreachable:
    "[pending] The relay did not answer the status probe that runs before a command is queued (HTTP error or timeout), so it is unknown whether a previous command is still executing on the remote. Nothing was queued and nothing ran: pushing blind could overwrite a running command and hand you its output as if it were yours. Call remote_exec again with the EXACT SAME arguments to retry; do not fall back to ssh or any other transport.",
  push_unreachable:
    "[pending] Could not reach the relay to queue the command (network/timeout). Call remote_exec again with the EXACT SAME arguments to retry.",
  giveup_no_listener:
    "Gave up after {chunks} chunks ({secs}s) with no listener. The queued command has been dropped. End your turn telling the user the remote is not connected, with BOTH lines below verbatim in a fenced code block so they can paste ONE on the remote shell. Do NOT fall back to ssh or any other transport.\n\n{runner_lines}",
  resumed_label:
    "[resumed] This output was already waiting on the relay when the command was (re-)issued; it belongs to the most recent run (likely the one you just re-issued after an interruption). If it is not what you expected for this command, issue the command again.\n\n",
  absorbed_marker:
    "[recovered] The relay was holding a stale recovery marker from a previous session (now absorbed): {marker}. Call remote_exec again with the EXACT SAME arguments to run your command.",
  giveup_inflight_ceiling:
    "Command has been in flight on the remote for ~{mins} min with no result. The listener likely died mid-command (crash / kill -9 / reboot); its phase never cleared. {cleared_clause}Re-check the remote state and re-issue only if it is safe to run again.",
  giveup_inflight_cleared:
    "The wedged relay state was cleared automatically so new commands can run. ",
  inflight_executing:
    "[in-flight] Remote listener is actively executing the command ({elapsed}s elapsed). Call remote_exec again with the EXACT SAME arguments. Long operations like mongodump, restic, rsync, large package installs, or slow service restarts routinely take many minutes; do NOT give up just because there is no result yet, and do NOT ask the user to confirm. The relay will return the output the moment the command finishes.",
  pending_picked_up:
    "[pending] Listener picked up the command but it is still running on the remote. Call remote_exec again with the EXACT SAME arguments; do NOT ask the user anything. Stop after about 10 consecutive [pending] responses.",
  giveup_no_output:
    "Command was picked up but produced no output within {secs}s. It may still be running on the remote.",
  reset_done:
    "Session {key} reset: any queued command/result was dropped and the in-flight marker cleared. The key and a connected listener keep working; issue remote_exec normally now.",
  reset_unconfirmed:
    "Reset attempted but the relay did not confirm it; state may be unchanged. Retry, or call remote_session_reset with {\"rotate\": true} for a fresh session.",
  rotate_summary:
    "New session {new_key} minted. End your turn giving the user BOTH lines verbatim in a fenced code block so they can connect the remote listener (they paste ONE):\n\n{runner_lines}{preset_note}",
  rotate_preset_note:
    "\nNote: REMOTIFY_KEY still points at a now-dead key, so an MCP restart will mint ANOTHER new session; tell the user to update or unset it when convenient.\n",
};

// Templates served by the relay on the session payload; null = use built-ins.
let relayMessages = null;

// Adopt (or drop) relay-served templates from a session payload. Anything
// that is not a plain {templates: {key: string}} shape is ignored wholesale;
// per-key validation happens in msg() so one bad key can't poison the rest.
function adoptRelayMessages(payload) {
  const m = payload?.mcp_messages;
  relayMessages = (m && typeof m === 'object' && m.templates && typeof m.templates === 'object')
    ? m.templates : null;
}

function msg(key, params = {}) {
  const served = relayMessages?.[key];
  const tpl = typeof served === 'string' ? served : DEFAULT_MESSAGES[key];
  return tpl.replace(/\{(\w+)\}/g, (whole, name) =>
    Object.prototype.hasOwnProperty.call(params, name) ? String(params[name]) : whole);
}

// Mint a brand-new session on the relay. Used on cold start (no PRESET) and
// as a recovery path when a preset or cached key has expired server-side.
async function mintSession() {
  const s = assertSessionShape(await fetchJSON(`${API_BASE}/api/session`, { method: 'POST' }));
  adoptRelayMessages(s);
  const { supervised, auto } = quickstartLines(s);
  void notifyOperator(s, 'new session');
  process.stderr.write(
    `\nremotify: new session ${s.key}\n` +
    `  on remote (supervised): ${supervised}\n` +
    `  on remote (auto):       ${auto}\n\n`
  );
  return s;
}

async function ensureSession() {
  if (session) return session;
  let oldKey = null;
  if (PRESET) {
    try {
      session = assertSessionShape(await fetchJSON(`${API_BASE}/api/session/${PRESET}`));
      adoptRelayMessages(session);
      connectHintPendingFor = session.key;
      return session;
    } catch (e) {
      // Pinned key is gone server-side (expired TTL, purged, or never
      // existed). Fall back to minting a fresh session so the agent can
      // keep working - the old listener is dead either way.
      if (e.status !== 410 && e.status !== 404) throw e;
      oldKey = PRESET;
      process.stderr.write(
        `\nremotify: REMOTIFY_KEY=${PRESET} is not active on the relay; minting a new session\n\n`
      );
    }
  }
  session = await mintSession();
  connectHintPendingFor = session.key;
  // Either preset just 410'd, or remoteExec nulled the cache after an
  // in-flight 410 (renewNotice was pre-staged with newKey=null). Stamp the
  // new key so the next pendingMessage can surface the change to the LLM.
  if (oldKey)                                renewNotice = { oldKey, newKey: session.key };
  else if (renewNotice && !renewNotice.newKey) renewNotice.newKey = session.key;
  return session;
}

// Fetches the relay's per-session status, returning a discriminated result:
//   'gone'   -> session 410'd; trigger renewal.
//   'legacy' -> 404: an OLD relay without the status endpoint. Only THIS means
//               "assume no status probe" - a transient failure must not.
//   'error'  -> transient failure / timeout: caller should retry, NOT treat as
//               legacy (mis-treating a 502 as legacy silently disables the
//               busy-guard and can cause a stale queued command to run later).
//   object   -> the parsed status.
//
// Older relays only return cmd_queued / result_queued. Newer ones (PHP commit
// 19c9f5a onward) also expose cmd_in_flight and listener_seen_seconds_ago.
async function fetchStatus(key) {
  try {
    const r = await fetchT(`${API_BASE}/api/session/${key}/status`, QUICK_TIMEOUT_MS);
    if (r.status === 410) return 'gone';
    if (r.status === 404) return 'legacy';
    if (!r.ok) return 'error';
    return await r.json();
  } catch { return 'error'; }
}

// True when fetchStatus returned an actual status object (not a symbolic string).
const isStatus = (s) => s !== null && typeof s === 'object';

// mayHaveRun marks that the command had already been delivered to the remote
// (so re-pushing it after renewal risks a second execution).
function sessionGone(mayHaveRun = false) {
  const err = new Error('session expired on relay');
  err.sessionGone = true;
  err.mayHaveRun = mayHaveRun;
  return err;
}

async function dropQueuedCmd(cmdUrl) {
  try { await fetchT(cmdUrl, QUICK_TIMEOUT_MS, { method: 'DELETE' }); } catch { /* best-effort */ }
}

// Clear a wedged session's transient state on the relay (queued cmd/result +
// in-flight marker) while keeping the key and any connected listener alive.
// Primary path is the relay's POST /api/session/{key}/reset endpoint; a legacy
// relay without it (404) gets the manual equivalent: drop the hot cmd, then
// push and immediately drain a marker result (a result push flips the phase
// back to idle server-side). Returns true when the state is known to be clear.
async function resetRelayState(s) {
  try {
    const r = await fetchT(`${s.urls.api}/reset`, QUICK_TIMEOUT_MS, { method: 'POST' });
    if (r.ok) return true;
    if (r.status === 410) throw sessionGone(false);
    if (r.status !== 404) return false; // transient relay trouble; retry later
  } catch (e) {
    if (e.sessionGone) throw e;
    return false;
  }
  // Legacy relay without the reset endpoint.
  try {
    await fetchT(s.urls.cmd, QUICK_TIMEOUT_MS, { method: 'DELETE' });
    await fetchT(s.urls.result, QUICK_TIMEOUT_MS, {
      method: 'POST',
      headers: { 'Content-Type': 'application/octet-stream' },
      body: '[remotify: stale in-flight state cleared by the mcp client]',
    });
    await fetchT(`${s.urls.result}?nowait=1`, QUICK_TIMEOUT_MS); // drain the marker
    return true;
  } catch { return false; }
}

function consumeRenewNotice(s) {
  if (!renewNotice || renewNotice.newKey !== s.key) return '';
  const { oldKey, newKey } = renewNotice;
  renewNotice = null;
  return msg('renewed', { old_key: oldKey, new_key: newKey });
}

// --- Operator side channels (desktop notification + clipboard) -------------

function run(cmd, args, input) {
  return new Promise((resolve) => {
    let child;
    try {
      child = execFile(cmd, args, { timeout: 5000, windowsHide: true }, (err) => resolve(!err));
    } catch { resolve(false); return; }
    child.on('error', () => resolve(false));
    if (input !== undefined) {
      // An unhandled async EPIPE from a helper that never read stdin kills the process.
      child.stdin.on('error', () => { /* ignore: the callback already resolves */ });
      try { child.stdin.end(input); } catch { /* ignore */ }
    }
  });
}

// Try each candidate in order until one succeeds. Each entry: [cmd, args, stdin].
async function firstThatWorks(candidates) {
  for (const [cmd, args, input] of candidates) if (await run(cmd, args, input)) return true;
  return false;
}

function notifyDesktop(title, body) {
  const os = platform();
  if (os === 'darwin') {
    const esc = (t) => t.replace(/\\/g, '\\\\').replace(/"/g, '\\"');
    return run('osascript', ['-e', `display notification "${esc(body)}" with title "${esc(title)}"`]);
  }
  if (os === 'win32') {
    return run('msg', ['*', '/TIME:120', `${title}\n${body}`]);
  }
  return firstThatWorks([
    ['notify-send', ['-u', 'critical', '-a', 'remotify', title, body]],
    ['kdialog', ['--passivepopup', `${title}\n${body}`, '120']],
  ]);
}

function copyClipboard(text) {
  const os = platform();
  if (os === 'darwin') return run('pbcopy', [], text);
  if (os === 'win32')  return run('clip', [], text);
  return firstThatWorks([
    ['wl-copy', [], text],
    ['xclip', ['-selection', 'clipboard'], text],
    ['xsel', ['--clipboard', '--input'], text],
  ]);
}

// Default effect; tests swap it via __test.setNotifier so no real desktop
// calls happen and the trigger points can be asserted.
let notifier = async (s, why) => {
  const { supervised, auto } = quickstartLines(s);
  const jobs = [];
  if (NOTIFY_ON) {
    jobs.push(notifyDesktop(
      `remotify: connect the remote (${why})`,
      `Paste ONE on the remote shell. Supervised line is on your clipboard.\n\n` +
      `supervised (y/N per command):\n${supervised}\n\n` +
      `auto (unattended):\n${auto}`,
    ));
  }
  if (CLIPBOARD_ON) jobs.push(copyClipboard(supervised));
  await Promise.all(jobs);
};

const lastNotified = new Map(); // key -> ms timestamp
// Fire the operator side channels for session `s` unless the same key was
// notified within NOTIFY_THROTTLE_MS. Never throws, never blocks the caller
// for more than the child timeouts, which is why callers do not await it.
async function notifyOperator(s, why) {
  if (!NOTIFY_ON && !CLIPBOARD_ON) return;
  const now = Date.now();
  const last = lastNotified.get(s.key) ?? 0;
  if (now - last < NOTIFY_THROTTLE_MS) return;
  lastNotified.set(s.key, now);
  try { await notifier(s, why); } catch { /* best-effort */ }
}

function quickstartLines(s) {
  return {
    supervised: s.remote_quickstart      ?? `curl -fsSL '${s.urls.runner}' | bash`,
    auto:       s.remote_quickstart_auto ?? `curl -fsSL '${s.urls.runner}?mode=auto' | bash`,
  };
}

// The two paste one-liners that connect a remote listener to session `s`.
// Included on EVERY response whose only exit is the user pasting one of them
// (no-listener pendings, dead-listener busy wedges, the no-listener give-up):
// a hint shown only once is a hint an agent mid-orchestration can miss, and
// then the user is never told how to connect at all.
function runnerLines(s) {
  return msg('runner_lines', { runner_url: s.urls.runner });
}

// `listenerSeenAgo` (when known) splits "no listener at all" from
// "listener alive, just hasn't picked up yet". A fresh heartbeat (< 30s)
// means the runner is online and longpoll will deliver any moment, so we
// silently nudge the LLM to retry. No heartbeat means nothing can happen
// until the user pastes a one-liner, and the ONLY reliable way to put text
// in front of the user is the final message of the agent's turn: hosts
// collapse text written between tool calls (Claude Code renders it as a
// one-line "summarized" stub), which is exactly how agents that "relayed
// the one-liners and kept polling" left the operator with nothing to paste.
// So the no-listener variants tell the agent to stop and end its turn.
async function pendingMessage(s, firstTime, listenerSeenAgo) {
  const listenerAlive = typeof listenerSeenAgo === 'number' && listenerSeenAgo < 30;
  let header;
  if (listenerAlive)  header = msg('pending_alive',  { seen_ago: listenerSeenAgo });
  else if (firstTime) header = msg('pending_first',  { runner_lines: runnerLines(s) });
  else                header = msg('pending_repeat', { runner_lines: runnerLines(s) });
  if (!listenerAlive) void notifyOperator(s, 'no listener');
  return consumeRenewNotice(s) + header + msg('pending_tail');
}

// Hosts issue tool calls in parallel; the single-flight session state queues here.
let toolChain = Promise.resolve();
function serialized(fn) {
  const run = toolChain.then(fn, fn);
  toolChain = run.then(() => {}, () => {});
  return run;
}

const remoteExec   = (command) => serialized(() => remoteExecRetry(command));
const sessionReset = (rotate)  => serialized(() => sessionResetOnce(rotate));
const sessionInfo  = ()        => serialized(() => ensureSession());

async function remoteExecRetry(command) {
  // One transparent retry if the relay tells us the session is gone. This is
  // what lets the MCP survive a TTL expiry mid-session: we null out the stale
  // cache, ensureSession mints a fresh key, and the command gets re-pushed --
  // BUT only when the command provably never left the queue. If it may have
  // already run on the remote (mayHaveRun), re-pushing could execute a
  // destructive command twice, so we surface the unknown fate instead.
  for (let attempt = 0; attempt < 2; attempt++) {
    try {
      const r = await remoteExecOnce(command);
      // Surface a one-shot auto-recovery notice on whatever this call returns
      // ([pending], [in-flight], or a final result) so it cannot get lost.
      if (recoveryNotice) { r.text = recoveryNotice + r.text; recoveryNotice = null; }
      return r;
    } catch (e) {
      if (e.sessionGone && attempt === 0) {
        // Stage the notice with the dying key now; ensureSession will fill
        // newKey after the mint. Falls back to PRESET if session was nulled
        // before we cached anything (cold start with stale preset).
        renewNotice = { oldKey: session?.key ?? PRESET ?? '(unknown)', newKey: null };
        session = null;
        inFlight = null;
        if (e.mayHaveRun) {
          // The command had already been delivered to the remote when the
          // session expired; re-pushing could run a destructive command twice.
          const s = await ensureSession();
          return { pending: false, text:
            consumeRenewNotice(s) + msg('unknown_result', { command, runner_lines: runnerLines(s) }) };
        }
        continue; // command never left the queue -> safe to re-push on the new key
      }
      throw e;
    }
  }
}

async function remoteExecOnce(command) {
  const s = await ensureSession();

  const isNewCmd = !inFlight || inFlight.command !== command || inFlight.key !== s.key;
  let showConnectHint = false; // see the pickup loop below
  if (isNewCmd) {
    let preStatus = await fetchStatus(s.key);
    // An 'error' would slip the busy-guard below; 'legacy' (404) may fall through.
    if (preStatus === 'error') preStatus = await fetchStatus(s.key);
    if (preStatus === 'error') return { pending: true, text: msg('probe_unreachable') };
    if (preStatus === 'gone') { inFlight = null; throw sessionGone(false); }
    const listenerAlive = isStatus(preStatus)
      && typeof preStatus.listener_seen_seconds_ago === 'number'
      && preStatus.listener_seen_seconds_ago < 30;

    // Busy-guard: a prior command is still executing on the remote. Pushing now
    // would overwrite the queue and misattribute its result to this call. Older
    // relays (no cmd_in_flight field) skip this guard, matching prior behavior.
    let justRecovered = false;
    if (isStatus(preStatus) && preStatus.cmd_in_flight) {
      const elapsed = preStatus.cmd_in_flight_seconds_ago ?? 0;
      const seenAgo = preStatus.listener_seen_seconds_ago;
      // Auto-recovery from a wedged in-flight marker (typically left by a DEAD
      // prior session - the exact state that used to require a manual unwedge).
      // Provably stale: a listener polled for NEW work after the command was
      // handed out (heartbeat newer than the in-flight start), so its executor
      // is gone and no result is coming; newer relays self-heal this on the
      // poll itself, the MCP-side check covers older relays. Presumably stale:
      // in flight past the absolute ceiling (listener died, never reconnected).
      const provablyStale = typeof seenAgo === 'number' && (elapsed - seenAgo) > STALE_GRACE_S;
      const pastCap = MAX_INFLIGHT_MS > 0 && elapsed * 1000 >= MAX_INFLIGHT_MS;
      if ((provablyStale || pastCap) && await resetRelayState(s)) {
        recoveryNotice = msg('recovered_notice', {
          elapsed,
          reason: provablyStale
            ? msg('recovered_reason_stale')
            : msg('recovered_reason_ceiling', { ceiling_min: Math.round(MAX_INFLIGHT_MS / 60000) }),
        });
        justRecovered = true; // state is clean now; fall through to the push
      } else if (provablyStale || pastCap) {
        return { pending: true, text: msg('busy_reset_failed') };
      } else {
        // A fresh heartbeat means a listener is connected and polling: the
        // wedge (if it is one) clears by itself via the relay's self-heal, so
        // the agent only needs to keep retrying. A stale/absent heartbeat
        // means NOTHING clears until the user reconnects the runner - hand
        // the agent the one-liners so the user actually learns how. Whether
        // the old command is a dead session's leftover or a genuine long run
        // is the user's call (they can see the remote), so the message
        // delegates that decision instead of guessing.
        const busyListenerAlive = typeof seenAgo === 'number' && seenAgo < 30;
        if (!busyListenerAlive) void notifyOperator(s, 'listener gone');
        const ceilingMin = Math.round(MAX_INFLIGHT_MS / 60000);
        const suffix = busyListenerAlive
          ? msg('busy_alive_suffix', {
              ceiling_clause: MAX_INFLIGHT_MS > 0 ? msg('busy_alive_ceiling_clause', { ceiling_min: ceilingMin }) : '',
            })
          : msg('busy_dead_suffix', {
              heartbeat_phrase: typeof seenAgo === 'number'
                ? msg('busy_dead_heartbeat_seen', { seen_ago: seenAgo })
                : msg('busy_dead_heartbeat_none'),
              ceiling_clause: MAX_INFLIGHT_MS > 0 ? msg('busy_dead_ceiling_clause', { ceiling_min: ceilingMin }) : '',
              runner_lines: runnerLines(s),
            });
        return { pending: true, text: msg('busy_common', { elapsed }) + suffix };
      }
    }

    // Recovery: a result is already queued and we hold NO in-flight context
    // (cold start, or the MCP restarted while a command ran). Adopt it and
    // RETURN it below instead of discarding it and re-pushing -- re-pushing
    // would execute a possibly-destructive command a second time and lose this
    // output. Only when there is no prior inFlight; a deliberate command switch
    // (different inFlight) falls through to push, and the relay drops the stale
    // result on that push. Skipped right after an auto-recovery: preStatus
    // predates the reset, so a result_queued it reports was just dropped.
    if (!justRecovered && !inFlight && isStatus(preStatus) && preStatus.result_queued) {
      inFlight = { command, key: s.key, phase: 'result', chunks: 0, resumed: true };
    } else {
      if (inFlight) await dropQueuedCmd(s.urls.cmd);
      let push;
      try {
        push = await fetchT(s.urls.cmd, QUICK_TIMEOUT_MS, {
          method: 'POST',
          headers: { 'Content-Type': 'application/octet-stream' },
          body: command,
        });
      } catch {
        // Network/timeout on the push: the command was almost certainly not
        // queued. A same-args retry is safe -- the busy-guard above catches the
        // rare case where it actually did queue and get picked up.
        inFlight = null;
        return { pending: true, text: msg('push_unreachable') };
      }
      if (push.status === 410) { inFlight = null; throw sessionGone(false); }
      if (push.status === 426) { inFlight = null; throw await movedError(push); }
      if (!push.ok)            { inFlight = null; throw new Error(`push failed: HTTP ${push.status}`); }
      inFlight = { command, key: s.key, phase: 'pickup', chunks: 0 };
      if (connectHintPendingFor === s.key) {
        connectHintPendingFor = null;
        showConnectHint = !listenerAlive;
      }
    }
  }
  inFlight.chunks += 1;

  const deadline = Date.now() + CHUNK_MS;

  // Phase 1: wait for the listener to accept the queued command.
  if (inFlight.phase === 'pickup') {
    let lastStatus = null;
    let sawStatus = false; // the relay answered a probe at least once this chunk
    while (Date.now() < deadline) {
      lastStatus = await fetchStatus(s.key);
      if (lastStatus === 'gone')   { inFlight = null; throw sessionGone(false); }
      if (lastStatus === 'error')  { await sleep(POLL_MS); continue; }     // transient: retry, do NOT flip phase
      sawStatus = true;
      if (lastStatus === 'legacy') { inFlight.phase = 'result'; break; }  // old relay w/o status probe
      if (!lastStatus.cmd_queued)  { inFlight.phase = 'result'; break; }
      // Nothing can pick it up until the user pastes a line, so answer now.
      if (showConnectHint) break;
      await sleep(POLL_MS);
    }
    if (inFlight.phase === 'pickup') {
      const firstTime = inFlight.chunks === 1;
      if (inFlight.chunks >= MAX_CHUNKS) {
        await dropQueuedCmd(s.urls.cmd);
        const hung = inFlight;
        inFlight = null;
        void notifyOperator(s, 'no listener');
        throw new Error(msg('giveup_no_listener', {
          chunks: hung.chunks,
          secs: Math.round(hung.chunks * CHUNK_MS / 1000),
          runner_lines: runnerLines(s),
        }));
      }
      // No probe got through this chunk, so nothing here implicates the listener.
      if (!sawStatus) return { pending: true, text: msg('pickup_unreachable') };
      const seenAgo = isStatus(lastStatus) && typeof lastStatus.listener_seen_seconds_ago === 'number'
        ? lastStatus.listener_seen_seconds_ago
        : null;
      return { pending: true, text: await pendingMessage(s, firstTime, seenAgo) };
    }
  }

  // Phase 2: wait for the result.
  const resumedLabel = inFlight.resumed ? msg('resumed_label') : '';
  while (Date.now() < deadline) {
    let res;
    try { res = await fetchT(s.urls.result, RESULT_TIMEOUT_MS); }
    catch { return inflightOrPending(s); }   // timeout/stall: treat as retryable, don't drop state
    if (res.status === 200) {
      let body;
      try { body = await res.text(); }
      catch {
        inFlight = null;
        throw new Error(
          'The result was being delivered when the connection to the relay stalled, so this output is lost. ' +
          'The command itself already ran on the remote; decide whether re-running it is safe before issuing it again.');
      }
      // An ADOPTED "waiting result" that turns out to be a recovery marker
      // ('[remotify: ...', queued by the relay's self-heal or by a runner's
      // interrupt trap) belongs to a dead prior session, not to this command.
      // Absorb it and ask for a same-args retry instead of surfacing it as
      // this command's [resumed] output.
      if (inFlight.resumed && /^\[remotify: /.test(body)) {
        inFlight = null;
        return { pending: true, text: msg('absorbed_marker', { marker: body.trim() }) };
      }
      inFlight = null;
      return { pending: false, text: consumeRenewNotice(s) + resumedLabel + body };
    }
    if (res.status === 204) { await sleep(POLL_MS); continue; }
    if (res.status === 410) { inFlight = null; throw sessionGone(true); } // cmd was consumed before expiry
    inFlight = null;
    throw new Error(`fetch result: HTTP ${res.status}`);
  }
  return inflightOrPending(s);
}

// Chunk budget exhausted (or a result fetch stalled). MAX_CHUNKS bounds only
// *unproductive* waits, not legitimate in-flight time: ask the relay whether the
// command is still executing and, if so, refund this chunk -- unless it has been
// in flight past the absolute ceiling, which means the listener likely died.
async function inflightOrPending(s) {
  const status = await fetchStatus(s.key);
  if (status === 'gone') { inFlight = null; throw sessionGone(true); }
  if (isStatus(status) && status.cmd_in_flight) {
    const secs = typeof status.cmd_in_flight_seconds_ago === 'number' ? status.cmd_in_flight_seconds_ago : 0;
    if (MAX_INFLIGHT_MS > 0 && secs * 1000 >= MAX_INFLIGHT_MS) {
      inFlight = null;
      // Clear the wedged relay state NOW so the next command starts clean
      // instead of tripping the busy-guard on this dead command's marker.
      let cleared = false;
      try { cleared = await resetRelayState(s); } catch { /* session gone; next call renews */ }
      throw new Error(msg('giveup_inflight_ceiling', {
        mins: Math.round(secs / 60),
        cleared_clause: cleared ? msg('giveup_inflight_cleared') : '',
      }));
    }
    if (inFlight) inFlight.chunks = Math.max(0, inFlight.chunks - 1);
    const elapsed = typeof status.cmd_in_flight_seconds_ago === 'number'
      ? status.cmd_in_flight_seconds_ago
      : Math.round((inFlight ? inFlight.chunks : 0) * CHUNK_MS / 1000);
    return { pending: true, text: msg('inflight_executing', { elapsed }) };
  }
  if (inFlight && inFlight.chunks >= MAX_CHUNKS) {
    const hung = inFlight;
    inFlight = null;
    throw new Error(msg('giveup_no_output', { secs: hung.chunks * CHUNK_MS / 1000 }));
  }
  return { pending: true, text: msg('pending_picked_up') };
}

// remote_session_reset tool. In place (rotate=false): clear wedged relay state
// while keeping the key and any connected listener. Rotate: purge the session
// server-side and mint a fresh key (the old listener exits on its next poll
// with 410, so the user must re-paste the new one-liner on the remote).
async function sessionResetOnce(rotate) {
  if (!rotate) {
    const s = await ensureSession();
    inFlight = null;
    let ok = false;
    try { ok = await resetRelayState(s); }
    catch (e) {
      if (!e.sessionGone) throw e;
      // The key is already dead server-side; nothing left to clear. Renew so
      // the caller walks away with a working session instead of an error.
      session = null;
      renewNotice = { oldKey: s.key, newKey: null };
      const ns = await ensureSession();
      return consumeRenewNotice(ns) + rotateSummary(ns);
    }
    return ok ? msg('reset_done', { key: s.key }) : msg('reset_unconfirmed');
  }
  const oldKey = session?.key ?? PRESET;
  if (oldKey) {
    try { await fetchT(`${API_BASE}/api/session/${oldKey}`, QUICK_TIMEOUT_MS, { method: 'DELETE' }); }
    catch { /* best-effort; the TTL reaps it eventually */ }
  }
  session = null;
  inFlight = null;
  renewNotice = { oldKey: oldKey ?? '(none)', newKey: null };
  const s = await ensureSession();
  return consumeRenewNotice(s) + rotateSummary(s);
}

function rotateSummary(s) {
  return msg('rotate_summary', {
    new_key: s.key,
    runner_lines: runnerLines(s),
    preset_note: PRESET ? msg('rotate_preset_note') : '',
  });
}

const server = new Server({ name: 'remotify', version: PKG_VERSION }, { capabilities: { tools: {} } });

server.setRequestHandler(ListToolsRequestSchema, async () => ({
  tools: [
    {
      name: 'remote_exec',
      description:
        "Execute a shell command on the PROJECT'S REMOTE SERVER via the remotify relay (the deployed/production/staging box, NOT the developer's local machine). " +
        "Examples: 'check disk usage', 'restart the service', 'tail the app log', 'query the prod DB', 'is port 443 open in prod', 'what version is running in staging'. " +
        "Chain this tool freely for iterative work - log analysis with grep/awk/sed/head/tail, narrowing a search, cross-checking outputs, diagnosing slow services with ps/vmstat/netstat/iostat. Each call saves a manual copy-paste roundtrip; only surface the meaningful result or synthesis to the user. " +
        "In supervised mode the operator approves each command on the remote via y/N prompt, so no extra confirmation is needed from the assistant. " +
        "Returns combined stdout+stderr as plain text on success." +
        "\n\nHARD RULE: the user connects the remote by pasting a listener one-liner; that is the ONLY execution path. NEVER fall back to ssh, scp, or any other transport, and NEVER try to start the listener yourself. The user chose remotify precisely so that no agent gets shell access." +
        "\n\nPending handling: the response text may start with one of these markers." +
        "\n  '[pending]' WITH paste one-liners = no remote listener is connected; nothing can run until the user pastes one. STOP: end your turn immediately with a short message that shows BOTH one-liners (supervised and auto) verbatim in a fenced code block. Do not call any tool after that message: hosts collapse text written between tool calls, so the one-liners only reach the user as the final message of the turn. The command stays queued; when the user replies that the listener is running, call remote_exec again with the EXACT SAME arguments to collect the output. It will not execute twice." +
        "\n  '[pending]' WITHOUT one-liners = the listener is online but the relay cannot confirm pickup yet. Call remote_exec again with the EXACT SAME arguments; loop silently, do not ask the user anything, and give up only after about 10 consecutive [pending] responses." +
        "\n  '[in-flight]' = the command IS already being executed on the remote (cmd was picked up, no output yet). In that case:" +
        "\n    1. Do NOT give up. Long operations (mongodump, restic, rsync, big installs, slow restarts) routinely take many minutes." +
        "\n    2. Call remote_exec again with the EXACT SAME arguments; the relay will return the output the moment the command finishes." +
        "\n    3. Do NOT tell the user the listener disconnected; do NOT ask them to re-paste the listener; do NOT switch to a different command." +
        "\n    4. There is no LLM-side retry cap on '[in-flight]'; keep waiting until the result lands or the relay returns something else." +
        "\n  '[busy]' = you tried to issue a NEW command while a previous one is still executing on the remote. Each session is single-flight (one command at a time). In that case:" +
        "\n    1. If you know the previous command, call remote_exec with ITS exact arguments first to drain its result, then issue the new one." +
        "\n    2. If you do NOT know it (e.g. it was issued by an earlier session), keep calling remote_exec with the NEW command's arguments: stale state left by a dead session is cleared automatically (see '[recovered]')." +
        "\n    3. If the [busy] response contains paste one-liners, the listener is disconnected and the wedge can ONLY clear once the user reconnects it: end your turn with BOTH one-liners verbatim, exactly as for a no-listener [pending]." +
        "\n  '[recovered]' = wedged state left by a dead session/listener was cleared automatically. Read the rest of the message: either the command already proceeded, or you are asked to call remote_exec again with the EXACT SAME arguments." +
        "\n  '[resumed]' = the returned output was already waiting on the relay and belongs to the most recent run (e.g. you re-issued a command after an interruption). Use it if it matches what you expected; if not, issue the command again." +
        "\n  '[unknown-result]' = the session expired while the command was already running on the remote. Its output was lost and it may or may not have completed. A new session was minted; end your turn with BOTH reconnect one-liners verbatim, then decide whether re-running is safe once the user is back. The server will NOT silently re-run it." +
        "\n  '[session-renewed]' = the session key changed; the one-liners that follow are for the NEW key. Treat it like a no-listener [pending]: end your turn with both lines verbatim.",
      inputSchema: {
        type: 'object',
        properties: {
          command: { type: 'string', description: 'Shell command to execute on the remote machine' },
        },
        required: ['command'],
      },
    },
    {
      name: 'remote_session_info',
      description: 'Return the current relay session: key, URLs, and the exact one-liners to paste on the remote machine to start the listener (remote_quickstart = supervised, y/N per command; remote_quickstart_auto = unattended). When you show them to the user, show BOTH verbatim in a fenced code block and make them the final message of your turn.',
      inputSchema: { type: 'object', properties: {} },
    },
    {
      name: 'remote_session_reset',
      description:
        'Recover a wedged remotify session. Default (no arguments): clear stuck relay state in place - drops any queued command/result and the in-flight marker; the session key and a connected listener keep working. ' +
        'With {"rotate": true}: abandon the current session and mint a brand-new key - the old key dies, the remote listener disconnects, and the user must paste the NEW runner one-liner (returned by this tool) on the remote. ' +
        'Normally NOT needed: remote_exec auto-recovers from stale state by itself. Use it only when remote_exec stays wedged (repeated [busy] for a command you never issued) despite same-args retries, or when the user explicitly asks to reset or rotate the session.',
      inputSchema: {
        type: 'object',
        properties: {
          rotate: { type: 'boolean', description: 'Mint a brand-new session key instead of clearing the current one in place (requires the user to reconnect the remote listener)' },
        },
      },
    },
  ],
}));

server.setRequestHandler(CallToolRequestSchema, async ({ params }) => {
  try {
    if (params.name === 'remote_exec') {
      const command = params.arguments?.command;
      if (typeof command !== 'string' || command.trim() === '') {
        throw new Error('remote_exec needs a non-empty "command" string.');
      }
      const r = await remoteExec(command);
      return { content: [{ type: 'text', text: r.text || '(no output)' }] };
    }
    if (params.name === 'remote_session_info') {
      const s = await sessionInfo();
      // Lead with the connect lines; drop the several-KB relay message table.
      const { mcp_messages, ...rest } = s;
      const info = { mcp_version: PKG_VERSION, ...rest };
      return { content: [{ type: 'text', text:
        msg('session_info_intro', { key: s.key, runner_lines: runnerLines(s) }) +
        JSON.stringify(info, null, 2) }] };
    }
    if (params.name === 'remote_session_reset') {
      const text = await sessionReset(params.arguments?.rotate === true);
      return { content: [{ type: 'text', text }] };
    }
    throw new Error(`Unknown tool: ${params.name}`);
  } catch (e) {
    return { isError: true, content: [{ type: 'text', text: String(e.message || e) }] };
  }
});

// Test seam: expose the state machine and let a test reset module-level state
// between cases. Only connect the stdio transport when run as the real server,
// so importing this file for tests does not block on a transport.
export const __test = {
  remoteExec,
  ensureSession,
  sessionReset,
  reset() {
    session = null; inFlight = null; renewNotice = null; recoveryNotice = null;
    relayMessages = null; connectHintPendingFor = null; lastNotified.clear();
  },
  getInFlight: () => inFlight,
  setNotifier: (fn) => { notifier = fn; },
  run,
  claudeHookMessage,
  DEFAULT_MESSAGES,
};

// --- Claude Code hook mode ---------------------------------------------------
// `remotify-mcp --claude-hook` reads a Claude Code PostToolUse hook payload on
// stdin and, when the tool result carries connect one-liners, prints a hook
// JSON with `systemMessage` so Claude Code shows the lines to the operator in
// the terminal itself. That path does not depend on the model relaying
// anything. Wire it in settings.json:
//   "hooks": { "PostToolUse": [ { "matcher": "mcp__remotify__.*",
//     "hooks": [ { "type": "command", "command": "npx -y remotify-mcp@latest --claude-hook" } ] } ] }
function collectStrings(v, out = []) {
  if (typeof v === 'string') out.push(v);
  else if (Array.isArray(v)) v.forEach((x) => collectStrings(x, out));
  else if (v && typeof v === 'object') Object.values(v).forEach((x) => collectStrings(x, out));
  return out;
}

function claudeHookMessage(payload) {
  const text = collectStrings(payload?.tool_response).join('\n');
  const re = /curl -fsSL '[^'\s]*\/r\/[0-9a-f]{32}(?:\?mode=auto)?' \| bash/g;
  const lines = [...new Set(text.match(re) ?? [])];
  if (lines.length === 0) return null;
  const supervised = lines.find((l) => !l.includes('mode=auto'));
  const auto = lines.find((l) => l.includes('mode=auto'))
    ?? (supervised ? supervised.replace("' | bash", "?mode=auto' | bash") : null);
  const shown = [];
  if (supervised) shown.push(`  supervised (y/N per command): ${supervised}`);
  if (auto)       shown.push(`  auto (unattended):            ${auto}`);
  return `remotify: the remote listener is not connected. Paste ONE of these on the remote shell:\n${shown.join('\n')}`;
}

async function runClaudeHook() {
  let raw = '';
  for await (const chunk of process.stdin) raw += chunk;
  let payload = null;
  try { payload = JSON.parse(raw); } catch { /* not JSON: nothing to show */ }
  const m = claudeHookMessage(payload);
  if (m) process.stdout.write(JSON.stringify({ systemMessage: m }) + '\n');
}

// npm installs run the CLI through a SYMLINK (node_modules/.bin/remotify-mcp
// -> ../remotify-mcp/server.js), and Node realpaths ESM module URLs - so
// comparing import.meta.url against argv[1] AS GIVEN is false under any bin
// symlink. That made isMain false on every npx / global install: connect()
// never ran and the process exited before answering initialize ("connection
// closed" in every MCP host). Compare fully-resolved real paths instead.
let isMain = false;
if (process.argv[1]) {
  try {
    isMain = realpathSync(process.argv[1]) === realpathSync(fileURLToPath(import.meta.url));
  } catch { /* argv[1] unresolvable -> we were imported, not executed */ }
}
if (isMain) {
  if (process.argv.includes('--claude-hook')) await runClaudeHook();
  else await server.connect(new StdioServerTransport());
}
