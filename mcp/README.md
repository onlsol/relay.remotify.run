# remotify-mcp

MCP server that gives any MCP-compatible host a `remote_exec` tool backed by
a [remotify.run](https://relay.remotify.run) relay.

Runs on **your** machine; zero cost to the relay server.

## Install

Most users don't install - the MCP hosts below all launch it via `npx`:

```bash
npx -y remotify-mcp@latest
```

Or clone this repo and run `npm install` in `mcp/` if you want to hack on it.

## Wire it into your MCP host

Every host below launches the same command; only the config file differs. If a
host does not pick the server up, check its own docs for where it reads MCP
config from.

### Claude Code

```bash
claude mcp add -s user remotify -- npx -y remotify-mcp@latest
```

`-s user` registers it for every working directory instead of only the one you
ran the command in. `claude mcp list` shows it; `/mcp` inside a session shows
the live status and the exposed tools.

### Codex CLI

`~/.codex/config.toml`:

```toml
[mcp_servers.remotify]
command = "npx"
args    = ["-y", "remotify-mcp@latest"]
```

### Cursor

`~/.cursor/mcp.json` for every project, or `.cursor/mcp.json` for one:

```json
{ "mcpServers": { "remotify": { "command": "npx", "args": ["-y", "remotify-mcp@latest"] } } }
```

Restart Cursor afterwards.

### Windsurf

`~/.codeium/windsurf/mcp_config.json`, same shape:

```json
{ "mcpServers": { "remotify": { "command": "npx", "args": ["-y", "remotify-mcp@latest"] } } }
```

### Claude Desktop

`~/Library/Application Support/Claude/claude_desktop_config.json` on macOS,
`%APPDATA%\Claude\claude_desktop_config.json` on Windows:

```json
{ "mcpServers": { "remotify": { "command": "npx", "args": ["-y", "remotify-mcp@latest"] } } }
```

Restart the app afterwards.

### Gemini CLI

`~/.gemini/settings.json`:

```json
{ "mcpServers": { "remotify": { "command": "npx", "args": ["-y", "remotify-mcp@latest"] } } }
```

### Anything else

Continue, Cline, Roo Code, Zed, VS Code's built-in MCP and the rest take the
same `command` plus `args` pair in a file they each document. Drop the snippet
in and you are done.

### Pointing at a self-hosted relay

Out of the box this talks to the public relay at `https://relay.remotify.run`.
For your own instance add one env var to whichever snippet you used:

```json
"env": { "REMOTIFY_URL": "https://remotify.example.com" }
```

### What the host has to render

The connect one-liners travel in an ordinary text content block on the tool
result, because that is the one thing every host above passes to the model
unchanged. Nothing in this server depends on host-specific machinery: not
Claude Code hooks, not `notifications/progress` or `notifications/message`,
not MCP resources, not elicitation. The hook and the desktop notification
described further down are extras for hosts that support them, never the only
path.

## Environment

| Var | Default | Meaning |
|-----|---------|---------|
| `REMOTIFY_URL` | `https://relay.remotify.run` | Relay to talk to. Override to point at your self-hosted instance. |
| `REMOTIFY_KEY` | _(unset)_ | Reuse an existing session key instead of creating a new one on startup. |
| `REMOTIFY_CHUNK_MS` | `5000` | Per-call blocking budget before the tool returns `[pending]` and asks the assistant to loop. |
| `REMOTIFY_MAX_CHUNKS` | `24` | Ceiling on consecutive **unproductive** `[pending]` returns before the tool gives up. Time a command spends genuinely in flight on the remote is refunded and does not count against this, so a long-running job isn't mistaken for a dead listener. |
| `REMOTIFY_POLL_MS` | `500` | Internal poll interval against the relay's `/api/session/<key>/status` probe. |
| `REMOTIFY_RESULT_TIMEOUT_MS` | `30000` | Per-fetch timeout on the `GET /result-{key}` long-poll. |
| `REMOTIFY_QUICK_TIMEOUT_MS` | `10000` | Timeout on the quick status/push/session HTTP calls. |
| `REMOTIFY_MAX_INFLIGHT_MS` | `1800000` | Absolute ceiling on how long a command may sit "in flight" before the tool stops waiting - guards against a listener that died mid-command. Also the ceiling past which a *stale* in-flight marker (left by a dead prior session) is cleared automatically so new commands can run. |
| `REMOTIFY_STALE_GRACE_S` | `30` | Slack for the stale-in-flight fast path: when the listener heartbeat is newer than the in-flight start by more than this many seconds, the in-flight command's executor is provably gone and the wedge is cleared immediately instead of waiting out `REMOTIFY_MAX_INFLIGHT_MS`. |

## Tools exposed

- `remote_exec(command)` - pushes `command` to the relay, waits for the listener
  to run it, returns combined stdout+stderr as plain text.
- `remote_session_info()` - leads with both ready-to-paste one-liners
  (supervised and auto) and a sentence on what each does, then the session
  JSON: key, URLs and TTL. Minting the session lazily on this call is enough
  to hand the user everything they need to connect a listener.
- `remote_session_reset({rotate?})` - recovery escape hatch. Default: clears
  wedged relay state in place (queued cmd/result + in-flight marker); the key
  and a connected listener keep working. `{"rotate": true}`: purges the current
  session and mints a brand-new key (the user must re-paste the new one-liner).
  Normally unnecessary - `remote_exec` auto-recovers on its own (see below).

## Auto-recovery from a wedged session

A listener killed mid-command without a chance to notify the relay (kill -9,
closed terminal, host reboot) used to leave the session wedged: every new
command returned `[busy]` for a command the current session never issued, and
the only way out was hand-posting a dummy result and reloading the MCP. That
state now clears itself:

- **Relay-side:** the moment any listener (re)connects and polls for work, the
  relay detects the orphaned in-flight marker, clears it, and queues a
  synthetic `[remotify: ...]` result for whoever was waiting.
- **MCP-side:** when a new command hits the busy-guard and the marker is
  provably stale (listener heartbeat newer than the pickup by
  `REMOTIFY_STALE_GRACE_S`) or older than `REMOTIFY_MAX_INFLIGHT_MS`, the MCP
  resets the session state itself (`POST /api/session/{key}/reset`, with a
  manual-clear fallback for older relays) and proceeds with the new command,
  prefixing the response with `[recovered]`.

A genuinely long-running command is not affected: while its listener is busy
executing, the heartbeat cannot be newer than the pickup, so nothing clears
before `REMOTIFY_MAX_INFLIGHT_MS`.

One wedge variant cannot self-clear: the listener died mid-command (heartbeat
frozen at pickup, indistinguishable from a genuine long run) and stays under
the in-flight ceiling. Recovery then requires the user to reconnect the runner,
so the `[busy]` response includes the paste one-liners and tells the assistant
to relay them - reconnecting triggers the relay-side self-heal above.

## Relay-served message wording

Every runtime string `remote_exec` returns to the assistant (`[pending]`,
`[busy]`, `[recovered]`, the paste one-liners, ...) is rendered from a
template table. The relay may serve replacements for any subset of keys on the
session payload (`mcp_messages.templates`, maintained in the relay repo at
`php/app/mcp-messages.json`), so wording improvements ship with a relay deploy
and reach every client - including stale cached installs - on their next
session, without an npm release. The package keeps the full table baked in as
a fallback for older relays and offline failure modes.

Deliberately **not** relay-served: tool names, schemas, and descriptions.
Hosts and users grant approval based on those; they only change through
versioned npm releases.

## How `remote_exec` handles a missing listener

The MCP server has no channel to show text to the user mid-tool-call (Claude
Code, Cursor, etc. don't render `notifications/progress` or `notifications/message`
from an MCP server during a tool call). Elicitation dialogs work but can't be
closed server-side on current clients, which leaves stale prompts in chat.

There is a second constraint that shaped the current design: text the
assistant writes between tool calls is not reliably shown either. Claude Code
collapses it into a one-line "summarized" stub, and other hosts truncate or
hide it. An assistant that "relays the one-liners, then keeps polling" has
therefore relayed nothing the operator can paste. The only text a host shows
in full is the final message of the assistant's turn.

So `remote_exec` distinguishes two `[pending]` cases:

1. Mints the session if this is the first call, then pushes the command so it
   is queued on the relay whatever happens next.
2. First command of a session no listener has ever polled: the tool answers
   straight away, with no waiting at all. Nothing can pick the command up
   until the user pastes a runner line and they have not been shown one yet,
   so waiting would only burn seconds. The response is a `[pending]` carrying
   BOTH paste one-liners (supervised and auto) and telling the assistant to
   stop, end its turn, and make those two lines, verbatim in a fenced code
   block, its final message. The command stays queued. When the user pastes a
   one-liner the listener picks it up and runs it; on the user's next message
   the assistant calls `remote_exec` again with the same arguments and
   collects the output. The server carries state across turns, so the command
   is not re-queued and will not execute twice.
3. Otherwise the tool polls for ~5s (`REMOTIFY_CHUNK_MS`). If the listener
   picks the command up in that window it waits for the result and returns
   it. Most calls finish here.
4. Still no listener on a later call: the same `[pending]` with both lines.
   They are repeated on every no-listener `[pending]`, on dead-listener
   `[busy]` wedges, on session renewal, and in the give-up error, so an
   assistant that ignored the stop once still has them on the next response.
5. Listener online (fresh heartbeat) but pickup not yet confirmed: the tool
   returns a `[pending]` without one-liners and asks for a silent same-args
   retry, giving up only after about 10 consecutive responses. Once the relay
   confirms the command is executing (`cmd_in_flight`) the response switches
   to `[in-flight]`, which has no retry cap at all.
6. Relay unreachable rather than listener missing: when not one status probe
   got through, the response says so instead of sending the user off to paste
   a line that is not what is broken. A relay that cannot be reached at all
   (DNS, refused, timeout) and a retired endpoint answering `426` each get
   their own message naming the URL and what to change.

Every `[pending]` also restates the hard rule: never fall back to `ssh`,
`scp`, or any other transport, and never try to start the listener yourself.
Operators pick remotify precisely so that no agent gets shell access; an
assistant that works around a missing listener defeats the product.

`REMOTIFY_MAX_CHUNKS` (default 24) is the server's internal ceiling on
unproductive waiting. Time a command spends genuinely in flight on the remote
is refunded and does not count against it, so a long-running job (`mongodump`,
`restic`, a slow deploy) is never mistaken for a dead listener. The queued
command is only auto-dropped from the relay once this pickup budget is
exhausted on continued unproductive retries, or once the session expires on
its own idle TTL, whichever comes first.

Net effect the user sees: "Calling remotify..." for a few seconds, then either
the command output (if the listener was already up) or a short final message
from the assistant showing both one-liners to paste on the remote. No dialog,
no leftover UI prompts; the queued command runs the moment the listener
connects, and the next user message collects its output.

## Operator visibility that does not depend on the model

Every instruction above lives inside a tool result, and a model can ignore
it: relay the lines between tool calls (collapsed by the host), relay only
one of them, or not relay them at all and keep polling for a listener nobody
knows how to start. Two mechanisms put the lines in front of the operator
regardless of what the model does.

Desktop notification and clipboard. The MCP server runs on the operator's
own machine. On session mint and on every response whose only exit is the
operator pasting a line (no-listener `[pending]`, dead-listener `[busy]`,
the no-listener give-up) it raises a desktop notification showing BOTH
one-liners and copies the supervised line to the clipboard, throttled to
once per minute per session. Linux uses `notify-send` (or `kdialog`) and
`wl-copy` / `xclip` / `xsel`; macOS uses `osascript` and `pbcopy`; Windows
uses `msg` and `clip`. Everything is best-effort and silent when a tool is
missing, so a headless box loses nothing.

| Variable | Default | Effect |
|---|---|---|
| `REMOTIFY_NOTIFY` | `1` | `0` disables the desktop notification |
| `REMOTIFY_CLIPBOARD` | `1` | `0` disables the clipboard copy |
| `REMOTIFY_NOTIFY_THROTTLE_MS` | `60000` | Minimum gap between notifications for the same session |

Claude Code hook. `remotify-mcp --claude-hook` reads a PostToolUse hook
payload on stdin and, when the tool result carries connect one-liners,
answers with a `systemMessage` that Claude Code prints in the terminal
itself, so the lines are shown even if the model says nothing. Add to
`.claude/settings.json` (project) or `~/.claude/settings.json` (user):

```json
{
  "hooks": {
    "PostToolUse": [
      {
        "matcher": "mcp__remotify__.*",
        "hooks": [
          { "type": "command", "command": "npx -y remotify-mcp@latest --claude-hook" }
        ]
      }
    ]
  }
}
```

## Typical first-run flow

1. Your MCP host launches this server. Nothing happens yet - no session is
   created at startup. The first call to `remote_exec` or `remote_session_info`
   mints the session lazily and prints it to stderr:
   ```
   remotify: new session a1b2...
     on remote (supervised): curl -fsSL 'https://relay.remotify.run/r/a1b2...' | bash
     on remote (auto):       curl -fsSL 'https://relay.remotify.run/r/a1b2...?mode=auto' | bash
   ```
2. First `remote_exec` call triggers that session creation. No listener yet:
   the tool returns `[pending]` immediately, without waiting, and the
   assistant ends its turn with both paste one-liners (supervised and auto)
   as its final message.
3. You paste one of them on the remote (supervised prompts `y/N` per command;
   auto runs every command straight away). The listener connects and runs
   the queued command.
4. You reply (anything, e.g. "connected"). The assistant calls `remote_exec`
   again with the same arguments and returns the output. From here on, calls
   with a running listener finish in ~0.5-2s.
