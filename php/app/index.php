<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
// Config (read once, cached forever in the worker).
// Pass $reset=true in tests to force the next call to re-read the environment.
// ---------------------------------------------------------------------------
function cfg(bool $reset = false): array {
    static $c = null;
    if ($reset) { $c = null; return []; }
    if ($c !== null) return $c;
    $scheme      = getenv('SCHEME')      ?: 'http';
    $domain      = getenv('DOMAIN')      ?: 'localhost';
    $publicPort  = getenv('PUBLIC_PORT') ?: '';
    $defaultPort = $scheme === 'https' ? '443' : '80';
    $portSuffix  = ($publicPort !== '' && $publicPort !== $defaultPort) ? ":$publicPort" : '';
    $dataDir     = getenv('DATA_DIR')    ?: '/var/data/sessions';
    // 0700: the tree holds live keys + command/result content; keep it private
    // to the php worker user (see docker-entrypoint.sh).
    if (!is_dir($dataDir)) @mkdir($dataDir, 0700, true);
    $c = [
        'scheme'         => $scheme,
        'domain'         => $domain,
        'port_suffix'    => $portSuffix,
        'base'           => "$scheme://$domain$portSuffix",
        'data_dir'       => $dataDir,
        'session_ttl'    => (int)(getenv('SESSION_TTL') ?: '10800'),
        'audit_log'      => getenv('AUDIT_LOG') === '1',
        'source_url'     => getenv('SOURCE_URL') ?: '',
        'max_body_bytes' => (int)(getenv('MAX_BODY_BYTES') ?: (25 * 1024 * 1024)),
        // GET /cmd-{key} holds the connection up to this many ms waiting for a
        // queued command, returning 200+body the moment one appears. 0 = legacy
        // immediate 204. Listener-side latency drops from ~POLL_INTERVAL to
        // sub-second; nginx fastcgi_read_timeout must comfortably exceed this.
        'longpoll_ms'    => (int)(getenv('LONGPOLL_MS') ?: '15000'),
    ];
    return $c;
}

// Hostname the request arrived on if it is not DOMAIN, else null. A retired
// name that still resolves here gets a loud 426 instead of service, so clients
// on an old default fail until updated; nothing is rerouted for them.
// Skipped for DOMAIN=localhost so local stacks reached via 127.0.0.1 work.
function foreign_host(array $server): ?string {
    $domain = cfg()['domain'];
    if ($domain === 'localhost') return null;
    $host = $server['HTTP_X_FORWARDED_HOST'] ?? $server['HTTP_HOST'] ?? '';
    $host = strtolower(trim(explode(',', $host)[0]));
    $host = preg_replace('/:\d+$/', '', $host);
    return ($host === '' || $host === $domain) ? null : $host;
}

// ---------------------------------------------------------------------------
// Response helpers.
// ---------------------------------------------------------------------------
function json_out(int $code, array $data): never {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function text_out(int $code, string $body, string $type = 'text/plain'): never {
    header("Content-Type: $type");
    header('Cache-Control: no-store');
    http_response_code($code);
    echo $body;
    exit;
}

// ---------------------------------------------------------------------------
// Session directory lifecycle.
// ---------------------------------------------------------------------------
function session_dir(string $key): string {
    return cfg()['data_dir'] . "/$key";
}

// True if the session exists and is within TTL; touches the dir on hit.
// Expired sessions are purged on contact and return false.
function touch_session(string $key): bool {
    $dir = session_dir($key);
    if (!is_dir($dir)) return false;
    // A session minted under another DOMAIN (or before the marker existed) is
    // gone: its client keeps working through the URLs it was handed and would
    // otherwise never re-mint, never reaching the endpoint-moved notice.
    if ((time() - filemtime($dir)) > cfg()['session_ttl']
        || @file_get_contents("$dir/_domain") !== cfg()['domain']) {
        purge_session($key);
        return false;
    }
    @touch($dir);
    return true;
}

// False when the dir could not be created or stamped; do not hand out the key then.
function create_session(string $key): bool {
    $dir = session_dir($key);
    if (!@mkdir($dir, 0700, true) && !is_dir($dir)) return false;
    return @file_put_contents("$dir/_domain", cfg()['domain']) !== false;
}

function purge_session(string $key): void {
    $dir = session_dir($key);
    if (!is_dir($dir)) return;
    // Move the live dir aside atomically BEFORE deleting its contents. A
    // concurrent long-poll can recreate `.lock` (fopen 'c') at any instant; if
    // that happened between an in-place unlink sweep and the final rmdir, the
    // rmdir would fail (ENOTEMPTY) and leave a zombie dir with a fresh mtime
    // that touch_session() then treats as a live session - a runner polling a
    // "deleted" key would never receive its 410. rename() removes the key in a
    // single step: afterwards is_dir(session_dir(key)) is false and any
    // straggler fopen() targets a parent that no longer exists and just fails.
    $tomb = $dir . '.dead-' . bin2hex(random_bytes(6));
    if (@rename($dir, $tomb)) {
        $dir = $tomb;
    } else {
        // A failed rename() leaves the stat cache primed by is_dir() above.
        clearstatcache(true, $dir);
        if (!is_dir($dir)) return; // already gone (lost the race to a concurrent purge)
    }
    try {
        $it = new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS);
        foreach (new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            if ($f->isDir()) @rmdir($f->getPathname()); else @unlink($f->getPathname());
        }
    } catch (UnexpectedValueException) {
        return;
    }
    @rmdir($dir);
}

// Opportunistic garbage collection. touch_session() only purges a session when
// THAT key is requested again, so a session whose client never returns would
// otherwise leak on disk forever (there is no background sweeper). Called from
// session creation -- a rate-limited, low-frequency endpoint -- it scans the
// data dir and purges any session past its idle TTL, plus any leftover
// `.dead-*` purge tombstones. Bounds disk growth to roughly the set of sessions
// active within one TTL window.
function gc_sessions(): void {
    $c   = cfg();
    $dir = $c['data_dir'];
    $ttl = $c['session_ttl'];
    $now = time();
    $dh  = @opendir($dir);
    if ($dh === false) return;
    while (($e = readdir($dh)) !== false) {
        if ($e === '.' || $e === '..') continue;
        $path = "$dir/$e";
        if (!is_dir($path)) continue;
        $isTomb = strpos($e, '.dead-') !== false;   // mid-purge leftover
        // is_dir() above primed the stat cache; a session purged meanwhile looks live.
        clearstatcache(true, $path);
        $m = @filemtime($path);
        if (!$isTomb && ($m === false || ($now - $m) <= $ttl)) continue;
        try {
            $it = new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS);
            foreach (new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST) as $f) {
                if ($f->isDir()) @rmdir($f->getPathname()); else @unlink($f->getPathname());
            }
        } catch (UnexpectedValueException) {
            continue; // purged by a concurrent request; nothing left to sweep
        }
        @rmdir($path);
    }
    closedir($dh);
}

// ---------------------------------------------------------------------------
// Listener heartbeat.
//
// Every GET /cmd-{key} touches `_lc` so /status can expose how recently the
// remote listener was seen polling. Lets MCP / operators tell apart "no
// listener connected at all" from "listener present, command in flight".
// ---------------------------------------------------------------------------
function mark_listener_seen(string $key): void {
    @touch(session_dir($key) . '/_lc');
}

function listener_seen_seconds_ago(string $key): ?int {
    $f = session_dir($key) . '/_lc';
    if (!@file_exists($f)) return null;
    $m = @filemtime($f);
    return $m === false ? null : max(0, time() - (int)$m);
}

// Phase marker: 'in_flight' once a cmd has been delivered to the listener
// (hot slot consumed via read_queue), 'idle' once the result for it has been
// pushed back. Single-file state instead of two mtimes because filemtime is
// integer-second resolution and a fast-cycling consume->result->consume can
// collapse within the same second. Lets /status answer "is there a cmd
// currently being executed on the remote?" without depending on listener
// heartbeat, which goes silent the whole time the listener is busy running.
function mark_cmd_consumed(string $key): void {
    @file_put_contents(session_dir($key) . '/_phase', 'in_flight');
}

function mark_result_received(string $key): void {
    @file_put_contents(session_dir($key) . '/_phase', 'idle');
}

// True when a cmd has been picked up by the listener and no subsequent
// result has been pushed back. This is the "still running on the remote"
// state the MCP needs to keep waiting through long-running operations
// (mongodump, big-tarball uploads, slow restarts) instead of giving up
// because the listener heartbeat went stale.
function cmd_in_flight(string $key): bool {
    $dir = session_dir($key);
    // A queued-but-unconsumed cmd is a different state ("waiting for pickup").
    if (@file_exists("$dir/cmd")) return false;
    return @file_get_contents("$dir/_phase") === 'in_flight';
}

// Wall-clock seconds since the in-flight phase started (i.e. since the
// listener consumed the cmd). Returns null when no command is in flight.
// Lets the MCP show true elapsed-since-pickup instead of an
// MCP-side chunk counter that resets across process restarts.
function cmd_in_flight_seconds_ago(string $key): ?int {
    if (!cmd_in_flight($key)) return null;
    $m = @filemtime(session_dir($key) . '/_phase');
    return $m === false ? null : max(0, time() - (int)$m);
}

// Text queued as a synthetic result when a wedged in-flight marker is cleared.
// Starts with '[remotify: ' so clients (MCP) can tell a recovery marker from
// real command output.
const STALE_INFLIGHT_RESULT =
    "[remotify: a listener connected while a previous command was still marked in-flight; " .
    "its executor is gone, the output was lost, and the command may or may not have completed]\n";

// Self-heal a wedged in-flight marker. A listener asking for NEW work while a
// command is marked in-flight proves that command's executor is gone: under
// the one-listener-per-session contract, the listener that consumed the cmd is
// either running it or waiting at the supervised y/N prompt -- in both states
// it does not poll /cmd. So a poll during in_flight means the executor died
// without its signal trap firing (kill -9, closed terminal, reboot) or its
// result push permanently failed, and no result will ever arrive. Queue a
// synthetic result so any client still waiting on /result-{key} gets a
// definitive answer, and flip the phase to idle so new commands are accepted.
// The 2s grace dodges same-second races around the consuming poll itself.
function self_heal_stale_inflight(string $key): bool {
    if (!cmd_in_flight($key)) return false;
    $age = cmd_in_flight_seconds_ago($key);
    if ($age === null || $age < 2) return false;
    write_queue($key, 'result', STALE_INFLIGHT_RESULT);
    mark_result_received($key);
    return true;
}

// ---------------------------------------------------------------------------
// Queue slot I/O.
//
// Every push writes a timestamped archive and a "hot" hard-link pointing at
// it. The hot file is what the consumer sees and unlinks; the archive
// survives until the session expires, giving a free audit trail without
// doubling on-disk bytes.
// ---------------------------------------------------------------------------
function write_queue(string $key, string $slot, string $body): int {
    $dir  = session_dir($key);
    $lock = "$dir/.lock";
    $hot  = "$dir/$slot";
    $ts   = gmdate('Ymd\THis\Z');
    $archive = "$dir/$slot-$ts";
    $fp = @fopen($lock, 'c');
    if (!$fp || !flock($fp, LOCK_EX)) return 500;
    try {
        $i = 1;
        while (file_exists($archive)) { $archive = "$dir/$slot-$ts-$i"; $i++; }
        if (@file_put_contents($archive, $body) === false) return 500;
        // Prior hot (if still unconsumed) already has its own archive from its
        // own write; last-wins rotation replaces the hot pointer.
        @unlink($hot);
        if (!@link($archive, $hot) && !@copy($archive, $hot)) return 500;
        return 201;
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

function read_queue(string $key, string $slot): ?string {
    $dir  = session_dir($key);
    $lock = "$dir/.lock";
    $hot  = "$dir/$slot";
    $fp = @fopen($lock, 'c');
    if (!$fp || !flock($fp, LOCK_EX)) return null;
    try {
        if (!file_exists($hot)) return null;
        $body = @file_get_contents($hot);
        @unlink($hot);
        return $body === false ? null : $body;
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

// Long-polling read: keep the connection open up to $timeoutMs, polling the
// hot slot every ~200ms, returning as soon as a body appears. Returns null
// (caller should answer 204) if the deadline elapses or the session vanishes
// mid-wait. Falls back to a single read_queue() when timeoutMs <= 0.
function read_queue_longpoll(string $key, string $slot, int $timeoutMs): ?string {
    if ($timeoutMs <= 0) return read_queue($key, $slot);
    $deadline = microtime(true) + ($timeoutMs / 1000.0);
    $tickUs   = 200_000;
    $hot      = session_dir($key) . "/$slot";
    while (true) {
        // PHP caches stat() results per-request, so without clearing them every
        // file_exists()/is_dir() below would return the value cached when this
        // request began -- the polling worker would neither see a hot slot that
        // ANOTHER worker just wrote nor notice the session being deleted, and
        // would spin here for the full timeout. Clear the cache each tick so
        // both the pickup and the bail react within ~200ms.
        clearstatcache();
        // Presence check first, so a tick never consumes a payload that is then lost.
        if (file_exists($hot)) {
            $body = read_queue($key, $slot);
            if ($body !== null) return $body;
        }
        $remainingS = $deadline - microtime(true);
        if ($remainingS <= 0) return null;
        // Bail early if the session disappeared mid-poll (operator DELETE or
        // TTL purge from another request).
        if (!is_dir(session_dir($key))) return null;
        $sleepUs = (int)min($tickUs, max(50_000, $remainingS * 1_000_000));
        usleep($sleepUs);
    }
}

// Drops the hot slot without consuming (no archive change, no listener will
// ever see it). Used by the client side to cancel an uncollected command.
// Returns true if a hot file was actually present and removed.
function delete_hot(string $key, string $slot): bool {
    return @unlink(session_dir($key) . "/$slot");
}

// ---------------------------------------------------------------------------
// Payloads.
// ---------------------------------------------------------------------------

// Agent-facing message templates for the MCP client (mcp/server.js), served on
// the session payload. Living here means wording iterations deploy with the
// relay instead of requiring an npm re-publish; the MCP keeps baked-in
// fallbacks for older relays and offline failure modes, so a missing or
// invalid file simply omits the field. Tool names/schemas/descriptions
// deliberately stay in the npm package (host approval surface).
function mcp_messages(): ?array {
    $parsed = json_decode((string)@file_get_contents(__DIR__ . '/mcp-messages.json'), true);
    return (is_array($parsed) && is_array($parsed['templates'] ?? null)) ? $parsed : null;
}

function session_payload(string $key): array {
    $c = cfg();
    $base = $c['base'];
    $payload = [
        'key' => $key,
        'ttl_seconds' => $c['session_ttl'],
        'urls' => [
            'cmd'    => "$base/cmd-$key",
            'result' => "$base/result-$key",
            'api'    => "$base/api/session/$key",
            'runner' => "$base/r/$key",
        ],
        // Supervised is the safe default; auto runs every command unattended.
        // Both ship as ready-to-paste lines so every client can show the
        // operator the pair instead of expecting them to edit a URL.
        'remote_quickstart'      => "curl -fsSL '$base/r/$key' | bash",
        'remote_quickstart_auto' => "curl -fsSL '$base/r/$key?mode=auto' | bash",
        // Push-then-poll one-liner. Verifies the enqueue returned 201, and the
        // poll loop exits cleanly on 410 (session gone) instead of spinning
        // forever. The relay drops any stale prior result on a new cmd push, so
        // no client-side drain is needed here.
        'exec' => "T=\$(mktemp 2>/dev/null || echo \"/tmp/remotify-exec.\$\$\"); "
                . "P=\$(curl -s -o /dev/null -w '%{http_code}' --data-raw 'COMMAND' $base/cmd-$key); "
                . "[ \"\$P\" = 201 ] || { echo \"remotify: push failed (HTTP \$P)\" >&2; rm -f \"\$T\"; exit 1; }; "
                . "while :; do R=\$(curl -s -o \"\$T\" -w '%{http_code}' $base/result-$key); "
                . "case \"\$R\" in 200) cat \"\$T\"; rm -f \"\$T\"; break;; "
                . "410) echo 'remotify: session gone' >&2; rm -f \"\$T\"; exit 1;; "
                . "*) sleep 1;; esac; done",
    ];
    if (($m = mcp_messages()) !== null) {
        $payload['mcp_messages'] = $m;
    }
    return $payload;
}

function runner_script(string $key, string $mode): string {
    $base = cfg()['base'];
    $modeLabel = $mode === 'auto' ? 'auto' : 'supervised';
    // 80% of the configured wire cap, leaving room for the markers appended after it.
    $maxOutBytes = intdiv(cfg()['max_body_bytes'] * 4, 5);

    // --- Header (interpolated HEREDOC) --------------------------------------
    // Runtime config + env hardening. Bash's own `$` are escaped as \$ so they
    // survive PHP interpolation; only $base/$key/$modeLabel are spliced in.
    $header = <<<BASH
#!/usr/bin/env bash
# $base remote-side runner (mode: $modeLabel)
set -u
BASE='$base'
KEY='$key'
MODE='$modeLabel'
CMD_URL="\$BASE/cmd-\$KEY"
RES_URL="\$BASE/result-\$KEY"

# Per-process scratch files, kept private to this listener's uid (mktemp, with
# an unpredictable pid+RANDOM fallback if mktemp is missing) so a co-tenant
# listener's EXIT trap can never fail trying to rm a file it does not own, and
# so the fallback path cannot be pre-created/symlinked by another local user.
_rand="\$\$-\${RANDOM:-0}\${RANDOM:-0}"
ERR=\$(mktemp -t remotify-err.XXXXXX 2>/dev/null)      || ERR="/tmp/.remotify-err.\$_rand"
CMD_FILE=\$(mktemp -t remotify-cmd.XXXXXX 2>/dev/null) || CMD_FILE="/tmp/.remotify-cmd.\$_rand"
OUT_FILE=\$(mktemp -t remotify-out.XXXXXX 2>/dev/null) || OUT_FILE="/tmp/.remotify-out.\$_rand"

# Cap relayed output so a giant dump is truncated (head kept, with a marker)
# rather than lost wholesale to a relay 413 / nginx body limit.
MAX_OUT_BYTES=$maxOutBytes

POLL_WAIT=2

# gzip is optional: the remote is promised to need only curl + bash. Compress
# result pushes when it is present, POST raw when it is not.
HAVE_GZIP=0
command -v gzip >/dev/null 2>&1 && HAVE_GZIP=1

# CMD_RUNNING is 1 only while a command is actually executing; CMD_PID is that
# command's pid. The command is run in the BACKGROUND and waited on, because a
# foreground external command blocks bash from running a signal trap until it
# returns -- backgrounding lets Ctrl+C interrupt the wait immediately so the
# handler can notify the relay and kill the child.
CMD_RUNNING=0
CMD_PID=0

# Inherited by every command the relay sends. Suppresses dpkg/apt prompts,
# disables pagers, forces plain terminal output, and turns off git's tty
# interactive prompts.
export DEBIAN_FRONTEND=noninteractive
export CI=true
export TERM=dumb
export PAGER=cat
export SYSTEMD_PAGER=cat
export GIT_TERMINAL_PROMPT=0
BASH;

    // --- Helpers (NOWDOC: $VAR stays verbatim for bash) ---------------------
    $helpers = <<<'BASH'
# Sleep between failed polls, doubling POLL_WAIT up to 30s; an answered poll resets it.
back_off() {
  sleep "$POLL_WAIT"
  POLL_WAIT=$((POLL_WAIT * 2))
  [ "$POLL_WAIT" -gt 30 ] && POLL_WAIT=30
  return 0
}

# Push the file at $1 back to the relay. gzip-compress when available, else POST
# raw. The body is declared application/octet-stream: without it curl labels it
# as a form and PHP splits the bytes on '&' at request startup, which on a large
# gzip body trips max_input_vars and corrupts the response (a 200 instead of
# the 201 acknowledgement). Only transient failures (network / 5xx) are
# retried; a 400/413 (bad or oversize body) is fatal because retrying cannot
# help, and a 410 means the session is gone. The relay acknowledges a stored
# result with 201 and nothing else, so any other 2xx/3xx is not a confirmation:
# the URL is answered by something that is not a healthy relay (moved endpoint,
# DNS, proxy, or a relay whose response was corrupted). Retrying cannot turn
# that into a 201. Loud on stderr so output loss is never silent.
push_result() {
  local file="$1" code attempt=0
  while [ "$attempt" -lt 5 ]; do
    : > "$ERR"
    if [ "$HAVE_GZIP" = 1 ]; then
      code=$(gzip -c "$file" | curl -sS -o /dev/null -w '%{http_code}' \
        -H 'Content-Type: application/octet-stream' -H 'Content-Encoding: gzip' \
        --data-binary @- --max-time 120 "$RES_URL" 2>>"$ERR" || echo "")
    else
      code=$(curl -sS -o /dev/null -w '%{http_code}' \
        -H 'Content-Type: application/octet-stream' \
        --data-binary @"$file" --max-time 120 "$RES_URL" 2>>"$ERR" || echo "")
    fi
    case "$code" in
      201) return 0 ;;
      410) echo "remotify: session expired during result push; result lost" >&2; return 1 ;;
      400|413) echo "remotify: relay rejected the result (HTTP $code); not retrying" >&2
               [ -s "$ERR" ] && sed 's/^/  /' "$ERR" >&2; return 1 ;;
      2[0-9][0-9]|3[0-9][0-9])
               echo "remotify: result push got HTTP $code from $RES_URL; the relay confirms a stored result with 201 only" >&2
               echo "remotify: not retrying. The push may or may not have been stored (wrong endpoint / DNS / proxy / relay error in response)" >&2
               return 1 ;;
    esac
    echo "remotify: result push got HTTP ${code:-000} (attempt $((attempt+1))/5)" >&2
    [ -s "$ERR" ] && sed 's/^/  /' "$ERR" >&2
    sleep 1
    attempt=$((attempt+1))
  done
  return 1
}

# Run $CMD in the BACKGROUND and wait on it, so a signal can interrupt the wait
# immediately (a foreground external command would block bash from running the
# trap until it returned). Captures combined stdout+stderr to $OUT_FILE as raw
# bytes; sets the globals rc (exit status) and ran=1.
run_cmd() {
  CMD_RUNNING=1
  bash -c "$CMD" > "$OUT_FILE" 2>&1 & CMD_PID=$!
  wait "$CMD_PID"; rc=$?
  CMD_RUNNING=0; CMD_PID=0; ran=1
}

# On Ctrl+C / SIGTERM / SIGHUP while a command is executing, best-effort kill
# the command and tell the relay the listener was interrupted, so its phase
# marker flips back to idle and the client gets a definitive answer instead of
# waiting forever on a command that no machine is running any more. (A simple
# command is exec'd directly by `bash -c`, so this kills it; a compound command
# may leave a short-lived leaf, which is harmless - the relay is already told.)
on_signal() {
  echo
  if [ "${CMD_RUNNING:-0}" = 1 ]; then
    [ "${CMD_PID:-0}" != 0 ] && kill "$CMD_PID" 2>/dev/null
    echo "remotify: interrupted mid-command; notifying relay" >&2
    printf '[remotify: listener interrupted; the command may or may not have completed]\n' > "$OUT_FILE" 2>/dev/null || true
    push_result "$OUT_FILE" >/dev/null 2>&1 || true
  fi
  echo "remotify: disconnected"
  exit 0
}
trap 'rm -f "$ERR" "$CMD_FILE" "$OUT_FILE" 2>/dev/null' EXIT
trap on_signal INT TERM HUP
BASH;

    // --- Per-mode splices ---------------------------------------------------
    // Supervised mode needs an interactive terminal to prompt; fail loudly at
    // startup if there is none rather than silently declining every command.
    $ttycheck = $mode === 'auto'
        ? ''
        : <<<'BASH'
if ! { exec 3</dev/tty; } 2>/dev/null; then
  echo "remotify: supervised mode needs a terminal (/dev/tty) to approve commands, but none is attached" >&2
  echo "remotify: (running under nohup / CI / a pipe?). For an unattended host, re-run in auto mode:" >&2
  echo "remotify:   curl -fsSL '$BASE/r/$KEY?mode=auto' | bash" >&2
  exit 1
fi
exec 3<&-
BASH;

    // Approval branch. Both write the command's combined stdout+stderr to
    // $OUT_FILE (a file, not a shell var, so NUL / binary bytes survive intact)
    // and record whether it actually ran (ran=1) and its exit code (rc).
    $approval = $mode === 'auto'
        ? <<<'BASH'
      run_cmd
BASH
        : <<<'BASH'
      if read -r -p 'Run? [y/N] ' ok </dev/tty; then
        case "$ok" in
          [yY]*) run_cmd ;;
          *)     printf '%s' '[declined by operator]' > "$OUT_FILE" ;;
        esac
      else
        printf '%s' '[no tty for approval; command not run - use ?mode=auto for unattended hosts]' > "$OUT_FILE"
      fi
BASH;

    $banner = $mode === 'auto'
        ? '"remotify: connected [auto] - polling for commands. Ctrl+C to stop."'
        : '"remotify: connected [supervised] - preview each command before it runs. Ctrl+C to stop."';

    // --- Command loop (NOWDOC with splices) ---------------------------------
    $body = <<<'BASH'
echo __BANNER__
__TTYCHECK__
while :; do
  : > "$ERR"
  t0=$(date +%s 2>/dev/null || echo 0)
  CODE=$(curl -sS -o "$CMD_FILE" -w '%{http_code}' \
    --max-time 120 "$CMD_URL" 2>>"$ERR" || echo "")
  t1=$(date +%s 2>/dev/null || echo 0)
  case "$CODE" in
    200)
      POLL_WAIT=2
      CMD=$(cat "$CMD_FILE" 2>/dev/null)
      printf '\n>>> %s\n' "$CMD"
      : > "$OUT_FILE"; ran=0; rc=0
__APPROVAL__
      # Truncate first: head -c keeps the head, so markers appended earlier get cut.
      sz=$(wc -c < "$OUT_FILE" 2>/dev/null || echo 0)
      if [ "${sz:-0}" -gt "$MAX_OUT_BYTES" ] 2>/dev/null; then
        head -c "$MAX_OUT_BYTES" "$OUT_FILE" > "$OUT_FILE.t" 2>/dev/null && mv "$OUT_FILE.t" "$OUT_FILE"
        printf '\n[remotify: output truncated to %d of %d bytes]\n' "$MAX_OUT_BYTES" "$sz" >> "$OUT_FILE"
      fi
      # Surface a failing exit code (only on non-zero, so clean binary output on
      # success is never mutated) - the client otherwise cannot tell a silent
      # success from a silent failure.
      if [ "$ran" = 1 ] && [ "${rc:-0}" -ne 0 ]; then
        printf '\n[remotify: exit status %d]\n' "$rc" >> "$OUT_FILE"
      fi
      cat "$OUT_FILE"; echo
      CMD_RUNNING=0
      if push_result "$OUT_FILE"; then
        printf '<<< done (%s bytes pushed back)\n' "$(wc -c < "$OUT_FILE" 2>/dev/null || echo 0)"
      else
        printf '<<< FAILED to push result\n' >&2
      fi
      ;;
    204) POLL_WAIT=2; [ "$((t1 - t0))" -lt 2 ] && sleep 1 ;;  # relay not long-polling; pace the loop
    410) echo "remotify: session expired or unknown key" >&2; exit 1 ;;
    # Retired hostname: it answers but serves no relay traffic; retrying cannot help.
    426) echo "remotify: this hostname no longer serves the relay" >&2
         [ -s "$CMD_FILE" ] && { sed 's/^/  /' "$CMD_FILE" >&2; echo >&2; }
         echo "remotify: ask for a fresh one-liner from the endpoint named above" >&2
         exit 1 ;;
    # A transport fault yields "000" (curl prints -w before failing), not just "".
    ""|000)
         echo "remotify: poll error (network/curl)" >&2
         [ -s "$ERR" ] && sed 's/^/  /' "$ERR" >&2
         back_off ;;
    *)   echo "remotify: poll got HTTP $CODE" >&2; back_off ;;
  esac
done
BASH;

    $body = str_replace(
        ['__BANNER__', '__TTYCHECK__', '__APPROVAL__'],
        [$banner,      $ttycheck,      $approval],
        $body
    );

    return $header . "\n\n" . $helpers . "\n\n" . $body . "\n";
}

// ---------------------------------------------------------------------------
// Handlers.
// Each handler receives the regex captures (if any) from its route pattern as
// positional arguments. All handlers terminate the request via json_out(),
// text_out(), or an explicit exit.
// ---------------------------------------------------------------------------
function h_health(): never {
    json_out(200, ['ok' => true, 'service' => cfg()['domain']]);
}

function h_source_redirect(): never {
    $url = cfg()['source_url'];
    if ($url === '') text_out(404, "source link not configured\n");
    header("Location: $url", true, 302);
    exit;
}

function h_source_json(): never {
    json_out(200, ['url' => cfg()['source_url']]);
}

function h_session_create(): never {
    gc_sessions(); // sweep expired/abandoned sessions before minting a new one
    $key = bin2hex(random_bytes(16));
    if (!create_session($key)) {
        error_log('remotify: cannot write session dir under ' . cfg()['data_dir']);
        json_out(500, ['error' => 'session storage unavailable']);
    }
    if (cfg()['audit_log']) {
        error_log(sprintf('remotify: session key=%s from=%s', $key, $_SERVER['REMOTE_ADDR'] ?? '-'));
    }
    json_out(201, session_payload($key));
}

function h_session_get(string $key): never {
    if (!touch_session($key)) json_out(410, ['error' => 'session gone']);
    json_out(200, session_payload($key));
}

function h_session_status(string $key): never {
    if (!touch_session($key)) json_out(410, ['error' => 'session gone']);
    $dir = session_dir($key);
    json_out(200, [
        'cmd_queued'                  => file_exists("$dir/cmd"),
        'result_queued'               => file_exists("$dir/result"),
        'cmd_in_flight'               => cmd_in_flight($key),
        'cmd_in_flight_seconds_ago'   => cmd_in_flight_seconds_ago($key),
        'listener_seen_seconds_ago'   => listener_seen_seconds_ago($key),
    ]);
}

function h_session_delete(string $key): never {
    purge_session($key);
    http_response_code(204);
    exit;
}

// Recovery endpoint: clear ALL transient command state of a session while
// keeping the session (key, TTL, connected listener) alive. Drops a queued
// cmd, drops a queued result, and flips the phase marker to idle -- the manual
// "post a dummy result to unwedge the slot" hack, done properly. Used by the
// MCP's auto-recovery and available to operators via curl. The response
// reports what was actually cleared so the caller can tell a no-op reset from
// a real unwedge.
function h_session_reset(string $key): never {
    if (!touch_session($key)) json_out(410, ['error' => 'session gone']);
    $wasInFlight   = cmd_in_flight($key);
    $cmdDropped    = delete_hot($key, 'cmd');
    $resultDropped = delete_hot($key, 'result');
    mark_result_received($key); // phase -> idle
    json_out(200, [
        'reset'   => true,
        'cleared' => [
            'cmd_queued'        => $cmdDropped,
            'result_queued'     => $resultDropped,
            'cmd_was_in_flight' => $wasInFlight,
        ],
    ]);
}

function h_runner(string $key): never {
    if (!touch_session($key)) text_out(410, "remotify: session gone\n");
    $mode = (($_GET['mode'] ?? 'supervised') === 'auto') ? 'auto' : 'supervised';
    header('Content-Type: text/x-shellscript; charset=utf-8');
    header('Cache-Control: no-store');
    header('Content-Disposition: inline; filename="remotify-' . $key . '-' . $mode . '.sh"');
    echo runner_script($key, $mode);
    exit;
}

function h_queue_write(string $slot, string $key): never {
    if (!touch_session($key)) text_out(410, "session gone\n");
    $body = file_get_contents('php://input');
    if ($body === false) $body = '';
    if (strtolower($_SERVER['HTTP_CONTENT_ENCODING'] ?? '') === 'gzip') {
        // Zip-bomb guard: 3x the nginx wire cap is the tolerable inflate ratio.
        // A 25MB wire body can inflate to tens of GB and would OOM the worker
        // (fatal error, served as HTTP 200 with a broken body) long before any
        // after-the-fact strlen() check on the decoded string could run.
        $cap = 3 * cfg()['max_body_bytes'];
        // Fast pre-check: a gzip stream (magic 1f 8b) stores its uncompressed
        // size (mod 2^32) in the trailing 4 bytes (ISIZE). An honest bomb is
        // rejected here with a clean 413 and ZERO decompression. Gate on the
        // magic so a non-gzip body whose tail merely looks like a big number
        // still falls through to the 400 "bad gzip" path below. ISIZE is only a
        // hint (truncatable and spoofable), so the capped decode still bounds
        // actual memory.
        if (strlen($body) >= 6 && substr($body, 0, 2) === "\x1f\x8b") {
            $isize = unpack('V', substr($body, -4))[1];
            if ($isize > $cap) text_out(413, "decoded body too large\n");
        }
        // Passing the cap as gzdecode()'s max-output length makes inflation STOP
        // at the limit, so a body that lies about its ISIZE still cannot expand
        // past $cap+1 bytes in memory.
        $decoded = @gzdecode($body, $cap + 1);
        if ($decoded === false)          text_out(400, "bad gzip\n");
        if (strlen($decoded) > $cap)     text_out(413, "decoded body too large\n");
        $body = $decoded;
    }
    // A command must be non-empty. An empty (or whitespace-only) command would
    // be consumed by the listener, produce no result, and leave the phase
    // marker stuck 'in_flight' forever - the client (and the MCP's never-give-up
    // in-flight wait) would then block on a command no one will ever answer.
    // Results, by contrast, are legitimately empty for silent commands.
    if ($slot === 'cmd' && trim($body) === '') text_out(400, "empty command\n");
    $code = write_queue($key, $slot, $body);
    if ($code === 201 && $slot === 'cmd') {
        // A new command supersedes any still-unfetched result from a prior one.
        // Dropping it here stops GET /result-KEY from handing that stale output
        // back as if it were this command's result (the misattribution the MCP
        // busy-guard also defends against, but raw-curl clients rely on this).
        delete_hot($key, 'result');
    }
    // Mark "result received" so cmd_in_flight flips back to false even when
    // the result body is consumed before any /status probe sees it queued.
    if ($code === 201 && $slot === 'result') mark_result_received($key);
    text_out($code, $code === 201 ? "queued\n" : "error\n");
}

function h_queue_read(string $slot, string $key): never {
    // No-store on every queue read so an intermediate proxy can never serve a
    // cached 204 to a polling listener after a real cmd has been queued at
    // the origin. Was the suspected cause of "listener up, no >>> prompt,
    // no error" reports from hosts behind transparent proxies.
    header('Cache-Control: no-store');
    if (!touch_session($key)) text_out(410, "session gone\n");
    // Track listener heartbeat on every cmd poll (long-poll or not) so
    // /status can report whether the remote runner is actually online.
    // A cmd poll also self-heals a wedged in-flight marker left by a dead
    // executor (see self_heal_stale_inflight), so simply reconnecting the
    // listener unwedges the session with no manual intervention.
    if ($slot === 'cmd') {
        mark_listener_seen($key);
        self_heal_stale_inflight($key);
    }
    // Long-poll on both slots: cmd-side gives the listener sub-second pickup
    // after a push; result-side stretches each MCP /result-K call to ~LONGPOLL_MS
    // so the implicit MAX_CHUNKS ceiling covers minutes of in-flight commands
    // (mongodump, slow restarts) instead of seconds. ?nowait=1 disables it
    // for explicit synchronous probes (tests, ad-hoc curl).
    $longpollMs = !isset($_GET['nowait']) ? cfg()['longpoll_ms'] : 0;
    $body = $longpollMs > 0
        ? read_queue_longpoll($key, $slot, $longpollMs)
        : read_queue($key, $slot);
    // A consumed cmd marks the start of "in-flight on the remote"; doing this
    // here (after long-poll exits with a body) means the marker only fires on
    // an actual delivery, not on every empty-poll tick.
    if ($slot === 'cmd' && $body !== null) mark_cmd_consumed($key);
    if ($body === null) { http_response_code(204); exit; }
    header('Content-Type: application/octet-stream');
    echo $body;
    exit;
}

function h_queue_delete(string $slot, string $key): never {
    if (!touch_session($key)) text_out(410, "session gone\n");
    $removed = delete_hot($key, $slot);
    // Cancelling a still-queued (unconsumed) command means nothing from it will
    // ever go in flight, so clear any phase marker to idle. Only do this when a
    // hot cmd was actually present: if the slot was already empty the command
    // may have been picked up and be genuinely executing on the remote, and we
    // must not falsely report it idle.
    if ($slot === 'cmd' && $removed) mark_result_received($key);
    http_response_code(204);
    exit;
}

// ---------------------------------------------------------------------------
// Route table: [method, pattern, handler].
// Regex captures are passed to the handler as positional arguments.
// First match wins; order matters only for overlapping patterns (e.g. the
// /status variant must come before the bare /api/session/{key} pattern).
// ---------------------------------------------------------------------------
$ROUTES = [
    ['GET',    '#^/api/health$#',                                'h_health'],
    ['GET',    '#^/source$#',                                    'h_source_redirect'],
    ['GET',    '#^/api/source$#',                                'h_source_json'],
    ['POST',   '#^/api/session$#',                               'h_session_create'],
    ['GET',    '#^/api/session/([a-f0-9]{32})/status$#',         'h_session_status'],
    ['POST',   '#^/api/session/([a-f0-9]{32})/reset$#',          'h_session_reset'],
    ['GET',    '#^/api/session/([a-f0-9]{32})$#',                'h_session_get'],
    ['DELETE', '#^/api/session/([a-f0-9]{32})$#',                'h_session_delete'],
    ['GET',    '#^/r/([a-f0-9]{32})$#',                          'h_runner'],
    ['POST',   '#^/(cmd|result)-([a-f0-9]{32})$#',               'h_queue_write'],
    ['GET',    '#^/(cmd|result)-([a-f0-9]{32})$#',               'h_queue_read'],
    ['DELETE', '#^/(cmd|result)-([a-f0-9]{32})$#',               'h_queue_delete'],
];

// ---------------------------------------------------------------------------
// Dispatch.
// Unit tests define REMOTIFY_TESTING before requiring this file so the
// helpers, payloads, and runner_script() are available without the HTTP
// dispatcher running on include.
// ---------------------------------------------------------------------------
if (defined('REMOTIFY_TESTING')) return;

$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (foreign_host($_SERVER) !== null) {
    // Session mint/fetch gets a "moved" payload instead of a bare status: old
    // clients discard error bodies, but they adopt the templates and quickstart
    // lines from any session payload, so this is the only way the notice
    // reaches the operator (desktop notification, clipboard, tool text). The
    // payload is dead by construction: no session exists, the command URLs use
    // a scheme fetch cannot open, so every later call fails with the notice.
    if (preg_match('#^/api/session(/[a-f0-9]{32})?$#', $path) && ($method === 'POST' || $method === 'GET')) {
        $ep = cfg()['base'];
        $fix = "update remotify-mcp (npx -y remotify-mcp@latest, then restart your MCP host) or set REMOTIFY_URL=$ep";
        $line = "echo 'remotify: this endpoint moved to $ep - $fix'";
        $dead = 'moved://' . cfg()['domain'];
        json_out(200, [
            'key' => bin2hex(random_bytes(16)),
            'ttl_seconds' => 0,
            'urls' => ['cmd' => "$dead/cmd", 'result' => "$dead/result", 'api' => "$dead/api", 'runner' => "$dead/r"],
            'remote_quickstart' => $line,
            'remote_quickstart_auto' => $line,
            'exec' => $line,
            'mcp_messages' => ['version' => 1, 'templates' => [
                'push_unreachable' => "[endpoint-moved] This remotify-mcp talks to a retired endpoint; the relay now lives at $ep. "
                    . "Nothing was sent and nothing can run. STOP and tell the user to $fix. Do not retry and do not fall back to ssh.",
                'session_info_intro' => "[endpoint-moved] This remotify-mcp talks to a retired endpoint; the relay now lives at $ep. "
                    . "There is no session here and no listener line to paste, so nothing can run. STOP and tell the user to $fix. "
                    . "Do not retry and do not fall back to ssh.\n\n",
                'runner_lines' => "  (no listener line: the endpoint moved to $ep - $fix)\n",
            ]],
        ]);
    }
    json_out(426, [
        'error'    => 'endpoint moved',
        'endpoint' => cfg()['base'],
        'action'   => 'update remotify-mcp (npx -y remotify-mcp@latest, then restart your MCP host) '
                    . 'or point REMOTIFY_URL at the endpoint above; nothing is served on this name',
    ]);
}

foreach ($ROUTES as [$m, $pat, $fn]) {
    if ($m !== $method) continue;
    if (!preg_match($pat, $path, $match)) continue;
    $fn(...array_slice($match, 1));
}

json_out(404, ['error' => 'not found', 'path' => $path]);
