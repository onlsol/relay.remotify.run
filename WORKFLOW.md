# Workflow

## Glossary
- **Client side** - whatever pushes commands: an LLM (any MCP host, any Bash-tool-capable chat model), a CI runner, a human at a terminal.
- **Remote side** - the shell where commands actually run. Only has `curl` + `bash`.
- **Queue** - per-session directory on the relay's disk holding one pending command and one pending result at a time.

## Data flow

```
  CLIENT                            RELAY                        REMOTE SHELL
  (LLM / CI / terminal)         (single vhost)                   (curl + bash)

   |                              |                              |
   |-- POST /api/session -------->|                              |
   |<-- key + URLs + one-liners --|                              |
   |                              |                              |
   |    copy remote_quickstart to the clipboard                  |
   |    paste it on the remote shell --------------------------->|
   |                              |                              |
   |                              |<-- curl /r/KEY?mode=... -----|
   |                              |   runner script starts       |
   |                              |                              |
   |-- POST /cmd-KEY ------------>|   (writes cmd + archive)        |
   |<-- 201 queued ---------------|                              |
   |   (client may disconnect)    |                              |
   |                              |<-- GET /cmd-KEY (poll) ------|
   |                              |-- 200 + command body ------->|
   |                              |              >>> prints cmd  |
   |                              |              (y/N if supervised)
   |                              |              runs cmd        |
   |                              |                              |
   |                              |<-- POST /result-KEY ---------|
   |                              |   (writes result + archive)    |
   |                              |--- 201 queued -------------->|
   |                              |              <<< done (N B)  |
   |                              |   (remote may disconnect)    |
   |-- GET /result-KEY (poll) --->|                              |
   |<-- 200 + output -------------|                              |
```

Every step is a stateless HTTP call, but the `GET /cmd-KEY` and `GET /result-KEY`
polls are long-polls, not instant round-trips: the relay holds the connection
open for up to `LONGPOLL_MS` (default 15s) waiting for something to arrive
before answering 204, so pickup is typically sub-second rather than on a
fixed tick. Both sides can drop offline between steps; the queue is on disk.

## Use cases

| Actor                  | How it talks to the relay                                       | Clipboard paste |
|------------------------|-----------------------------------------------------------------|-----------------|
| LLM + MCP              | `mcp/server.js` pushes + polls; wraps it as a `remote_exec` tool  | once per session (session is minted lazily on the first `remote_exec` / `remote_session_info` call, not at process start; the one-liner is surfaced to the user then) |
| LLM without MCP        | reads `templates/AGENTS.md.example`; uses its Bash tool + curl  | once (LLM tells user the one-liner) |
| Human via landing page | open `/`, click **Generate**, click **Copy**                    | once            |
| CI / cron / script     | raw HTTP: POST `/api/session`, then POST `/cmd-KEY` + poll `/result-KEY` | once (pre-provisioned listener) |

## Clipboard

The only thing that ever hits the clipboard:

    curl -fsSL 'https://<HOST>/r/<KEY>?mode=supervised' | bash

Paste it on the remote. Done. Push commands from wherever - no more copy/paste.

## Session lifetime

Every request touching a key (pushing/popping the queue, fetching the runner, pinging `/api/session/<key>`) refreshes the session mtime. Sessions expire **after `SESSION_TTL` seconds of idleness** (default 3h). Expired session dirs are purged on next access, taking any queued + history files with them.

`DELETE /api/session/<key>` explicitly purges immediately.

## Listener modes

`?mode=supervised` - prints each command, waits for `y/N` (accepts `y`, `Y`, `yes`, `YES`, etc.). Default.
`?mode=auto`       - runs every command immediately. Unattended hosts only.

Both long-poll `/cmd-KEY`: the relay holds the GET open (up to `LONGPOLL_MS`,
default 15s) and returns the instant a command is pushed, so pickup is
sub-second rather than on a fixed tick. If nothing arrives before the
deadline it returns 204 and the runner immediately opens the next long-poll.
Approval pauses can be as long as the operator needs - the queue just sits on disk.

Both modes print `>>> <cmd>` before execution and `<<< done (N bytes pushed back)` after the result is posted, so the operator sees status flow even in auto mode.

### Non-interactive env

The runner exports a standard "headless" env block once at the top, so every executed command inherits it: `DEBIAN_FRONTEND=noninteractive`, `CI=true`, `TERM=dumb`, `PAGER=cat`, `SYSTEMD_PAGER=cat`, `GIT_TERMINAL_PROMPT=0`. That covers apt, pagers, git HTTPS auth, and most tools that would otherwise block on a TTY. Sudo still prompts unless called with `-n` - the operator sees the preview and can authenticate interactively if they choose.

## Filesystem visibility (audit + debug)

Sessions live under `/var/data/sessions/` inside the container, bind-mounted
from the host - `./data/sessions` in this repo's `docker-compose.yml`, or
under the deployment's `data/sessions`. Both path
styles point at the same tree; use whichever matches where you're looking
(host vs. inside the container).

```
data/sessions/                        # host path; container sees /var/data/sessions/
└── <KEY>/                            # dir mode 0700 - readable only by the php-fpm worker user
    ├── cmd                            # hot: current unconsumed command (absent when none pending)
    ├── cmd-20260422T103015Z           # archive of every cmd push ever, timestamped
    ├── cmd-20260422T103020Z
    ├── result                         # hot: current unconsumed result
    ├── result-20260422T103017Z        # archive of every result push ever
    ├── _lc                            # listener heartbeat: touched on every GET /cmd-{key}, backs listener_seen_seconds_ago
    ├── _phase                         # in-flight phase marker: 'in_flight' while a picked-up cmd awaits its result, else 'idle'
    └── .lock                          # internal (flock coordination)
```

The hot file (`cmd` / `result`) is hard-linked to its corresponding archive on write, so the bytes exist once on disk. When the hot is consumed by the other side it's unlinked; the archive survives.

Everything in `<KEY>/` is removed when the session expires (`SESSION_TTL`, default 3h sliding) or on explicit `DELETE /api/session/<KEY>`.

`ls -lt data/sessions/` - live sessions sorted by last activity.  
`ls -lt data/sessions/<KEY>/` - chronological push history for one session.  
`cat data/sessions/<KEY>/cmd-20260422T103015Z` - inspect any past push.

## Queue semantics

- **Last-wins rotation.** Pushing while a hot file still exists **replaces** it; the old bytes stay on disk as a timestamped archive. No 409.
- **Single delivery.** The remote only ever consumes the *current* hot file. Older archives are audit, never delivered.
- **GET on empty slot** returns **204 No Content**. Poll again.
- **GET / POST on expired or unknown key** returns **410 Gone**.
- **DELETE /cmd-KEY** drops the hot command slot without consuming or archiving. Used by the MCP server to cancel its own queued command when it gives up waiting for a listener, so the command won't run as an orphan when one eventually connects.
- **POST /api/session/KEY/reset** clears all transient command state (queued cmd, queued result, in-flight marker) while keeping the session, its key, and a connected listener alive. The response reports what was actually cleared.
- **Max payload per push**: `MAX_BODY_SIZE` from `.env` (default 25 MB); larger pushes are refused by nginx before they reach PHP.

## Wedge recovery (dead executor)

A listener that dies without its signal trap firing (kill -9, closed terminal,
host reboot) - or whose result push permanently fails - leaves
`_phase=in_flight` with no result ever coming. Historically this wedged the
session until a human hand-posted a dummy result and reloaded the MCP. Three
layers now clear it automatically:

- **Relay self-heal.** A listener polling `GET /cmd-KEY` while a command is
  marked in-flight (and none is queued) proves that command's executor is gone:
  under the one-listener-per-session contract, an executing (or
  approval-waiting) listener never polls. Past a 2s grace, the relay flips the
  phase back to idle and queues a synthetic `[remotify: ...]` result so any
  client still waiting on `/result-KEY` gets a definitive answer. Simply
  reconnecting the listener unwedges the session.
- **MCP auto-recovery.** When a *new* command hits the busy-guard and the
  in-flight marker is provably stale (listener heartbeat newer than the
  pickup) or past the absolute ceiling (`REMOTIFY_MAX_INFLIGHT_MS`, default
  30 min), the MCP resets the session state itself and proceeds, prefixing the
  response with `[recovered]`. No human action, no MCP reload.
- **Explicit reset.** `POST /api/session/KEY/reset` for operators/scripts, and
  the `remote_session_reset` MCP tool for agents (in-place clear, or
  `{"rotate": true}` to mint a fresh key).
