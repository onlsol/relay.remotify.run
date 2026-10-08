# HTTP command relay

Ephemeral HTTP command relay. Push a shell command from any HTTP client; have
it run on a remote machine that has nothing installed but `curl` and `bash`.

Useful whenever you need to bounce a command to a shell without opening an SSH
port, installing an agent, or deploying anything:

- LLM coding sessions driving an arbitrary remote host - any tool, self-hosted or cloud (MCP hosts, curl-literate chat models, local runners, etc.)
- CI runners poking at post-deploy state
- Admin shells behind NAT / firewalls
- Headless devices reachable only outbound

Data on the relay is minimised, not absent: each session gets a small
directory holding the pending command/result and a timestamped push history,
purged as soon as the idle TTL lapses (default 3h) or on explicit delete.
Session keys are 128-bit random.

## Architecture

```
  CLIENT                            RELAY                        REMOTE SHELL
  (LLM / CI / terminal)         (single vhost)                   (curl + bash)

   |                              |                              |
   |-- POST /api/session -------->|                              |
   |<-- key + URLs ---------------|                              |
   |                              |                              |
   |        (user pastes the remote-quickstart on the remote)    |
   |                              |                              |
   |                              |<-- curl /r/KEY?mode=... -----|
   |                              |   runner script starts       |
   |                              |                              |
   |-- POST /cmd-KEY ------------>|      queued on disk          |
   |<-- 201 ----------------------|                              |
   |   (client may disconnect)    |                              |
   |                              |<-- GET /cmd-KEY (poll) ------|
   |                              |-- 200 + command body ------->|
   |                              |                >>> prints cmd|
   |                              |                (y/N if supervised)
   |                              |                bash runs     |
   |                              |                              |
   |                              |<-- POST /result-KEY ---------|
   |                              |      queued on disk          |
   |                              |--- 201 --------------------->|
   |                              |                <<< done      |
   |-- GET /result-KEY (poll) --->|                              |
   |<-- 200 + output -------------|                              |
```

## Stack

| Component | Role | First-party code |
|-----------|------|------------------|
| nginx | Reverse proxy, TLS, rate limiting, static landing page | Config only |
| PHP-FPM | Session API, runner script generator, file-backed queue | Yes |
| certbot | Automatic TLS issuance + renewal (optional, `--profile tls`) | No |

No database. No worker. The only persistent state is a flat-file queue under
`data/sessions/<KEY>/` (bind-mounted from the host into the container at
`/var/data/sessions/<KEY>/`), data-minimised and purged on idle TTL or
explicit delete.

## Quick start

```bash
cp .env.example .env
docker compose up -d
```

The shipped `.env.example` defaults to a self-consistent local HTTP setup -
no editing required for a first run. After the command above, the API and
the one-liners it hands out are both reachable at `http://localhost:49180`.

For a public deployment, edit `.env` first: set `DOMAIN` to your real
hostname, `SCHEME=https`, `NGINX_MODE=tls`, `HTTP_PORT=80` (required so
Let's Encrypt's HTTP-01 challenge is reachable) and `HTTPS_PORT=443`, then:

```bash
docker compose --profile tls up -d         # HTTP + HTTPS with automatic TLS
```

That's the whole install. Everything else runs in containers.

## Environment

All knobs live in `.env`; nothing is hardcoded.

| Var | Default | Meaning |
|-----|-----------------|---------|
| `DOMAIN` | `localhost` | Public hostname for the API / landing page |
| `SCHEME` | `http` | `http` or `https` - baked into returned one-liners |
| `PUBLIC_PORT` | `49180` | Port baked into generated URLs. Leave empty for the standard port of `SCHEME` (80/443) |
| `NGINX_MODE` | `http` | `http` or `tls`. `tls` requires `--profile tls` |
| `HTTP_PORT` / `HTTPS_PORT` | `49180` / `49443` | Host ports published by nginx. Public HTTPS deploy: `80` / `443` (80 is required for the ACME HTTP-01 challenge) |
| `RATE_LIMIT` | `10r/m` | Per-IP rate on `POST /api/session` (nginx `limit_req` syntax) |
| `RATE_LIMIT_BURST` | `5` | Burst slots before 503 |
| `PROXY_TIMEOUT` | `3600` | Max seconds either side of a pipe waits for the other end. Must comfortably exceed `LONGPOLL_MS` |
| `MAX_BODY_SIZE` | `25m` | Single source of truth for body-size limits: nginx `client_max_body_size` on the queue endpoints, and the API derives its raw-body cap (`post_max_size`), memory limit, its gzip zip-bomb decoded-size cap (3x this value), and the runner's output-truncation cap (80% of this value) from it, so none of them can drift apart. `0` = unlimited at the nginx layer (PHP still keeps a finite decoded cap for safety) |
| `LONGPOLL_MS` | `15000` | Long-poll window (ms) `GET /cmd-{key}` / `GET /result-{key}` hold an empty slot open before returning `204`, so listeners pick up work sub-second instead of on the next poll tick |
| `FPM_MAX_CHILDREN` | `64` | PHP-FPM pool size. Each active session pins ~2 workers for up to `LONGPOLL_MS` at a time, so this bounds how many sessions can be live concurrently before other requests start queueing |
| `FPM_MAX_REQUESTS` | `500` | Requests a PHP-FPM worker serves before it is recycled, so no slow leak accumulates over a long uptime. `0` = never recycle |
| `SESSION_TTL` | `10800` | Idle TTL for a session (seconds). Every request on the key resets it; expired sessions are purged with their queue + history. |
| `AUDIT_LOG` | `0` | `1` = log key generation to container stderr. Independently of this, nginx's access log masks the key segment of every request line as `<key>` |
| `SOURCE_URL` | _(empty)_ | Target of the landing page's source link and of `GET /source`. Empty hides the link |
| `CERTBOT_EMAIL` | _(required for tls profile)_ | Contact address used when requesting certs from the ACME CA |
| `CERTBOT_STAGING` | `0` | `1` = use the ACME staging environment (test-only certs, avoids rate limits) |

The defaults above are a ready-to-run local HTTP setup - `cp .env.example .env
&& docker compose up -d` gives a working roundtrip at `http://localhost:49180`
with no edits. A public HTTPS deploy needs `DOMAIN` set to your real hostname,
`SCHEME=https`, `NGINX_MODE=tls`, `HTTP_PORT=80` (for the ACME HTTP-01
challenge) and `HTTPS_PORT=443`.

Behind your own reverse proxy? Skip the `tls` profile. Point your proxy at
`HTTP_PORT` on localhost and terminate TLS there. `SCHEME` and `PUBLIC_PORT`
describe what clients see, so behind a proxy on the standard port they are
`https` and `443` (or empty), not the internal `HTTP_PORT`.

The CI deploy renders `.env` with `scripts/render-env.sh`: `.env.example`
defaults overridden by same-named CI variables. For a non-localhost `DOMAIN`
it refuses `SCHEME=http` unless `ALLOW_PLAIN_HTTP=1`, and when `SCHEME` is
given without `PUBLIC_PORT` it uses the standard port of `SCHEME`, so a
public deploy cannot silently hand out `http://DOMAIN:49180/` one-liners.

## API

### `POST /api/session`
Generate a new session. Returns:

- `key` - 32-char hex, 128-bit entropy
- `ttl_seconds` - idle TTL (default 3h sliding; any request on the key resets it)
- `urls.cmd` / `urls.result` - queue push/pop endpoints for this session
- `urls.runner` - base URL of the runner script; append `?mode=auto` for unattended mode
- `urls.api` - `/api/session/<key>` for re-fetching the payload
- `remote_quickstart` - ready-to-paste one-liner, supervised mode (y/N per command)
- `remote_quickstart_auto` - the same line with `?mode=auto`, for unattended hosts; show operators both and let them pick
- `exec` - one-liner template with a `COMMAND` placeholder for pushing from any HTTP client
- `mcp_messages` - agent-facing message templates for the MCP client
  (`{version, templates}`, from `php/app/mcp-messages.json`). Serving the
  wording from the relay means prompt iterations deploy with the relay instead
  of requiring an npm re-publish of `remotify-mcp`; clients keep baked-in
  fallbacks, so the field is optional. Tool names/schemas/descriptions stay in
  the npm package deliberately (host approval surface). Omitted if the file is
  missing or invalid.

### `GET /api/session/{key}`
Re-fetch the same payload for a known key. Touches the session (resets its idle
timer) without consuming anything queued.

### `GET /api/session/{key}/status`
Cheap probe: returns `{cmd_queued, result_queued, cmd_in_flight,
cmd_in_flight_seconds_ago, listener_seen_seconds_ago}` without consuming
anything:

- `cmd_queued` / `result_queued` - whether a command/result is currently sitting in the hot slot.
- `cmd_in_flight` - a command was picked up by the listener and no result has been pushed back yet.
- `cmd_in_flight_seconds_ago` - seconds since that pickup (`null` when nothing is in flight).
- `listener_seen_seconds_ago` - seconds since the listener last polled `GET /cmd-{key}` (`null` if it never has).

Used by the MCP server to detect whether the listener has already picked up the
queued command.

### `POST /api/session/{key}/reset`
Recovery endpoint: drops any queued command, any queued result, and clears the
in-flight marker, while keeping the session (key, TTL, connected listener)
alive. Returns what it actually cleared:
`{"reset": true, "cleared": {"cmd_queued": ..., "result_queued": ..., "cmd_was_in_flight": ...}}`.
Rarely needed by hand - the relay self-heals a wedged in-flight marker as soon
as the listener reconnects, and the MCP server resets stale state on its own -
but useful for scripts and as an operator escape hatch.

### `DELETE /api/session/{key}`
Purges the session directory (queued cmd/result + any archived pushes) and
returns 204.

### `POST /cmd-{key}` / `GET /cmd-{key}`
POST pushes a command (plain bytes or `Content-Encoding: gzip`). GET consumes
the hot command slot - used by the listener. Last-write-wins: pushing while a
command is already queued replaces it; the old bytes survive as a timestamped
archive.

GET **long-polls**: if the slot is empty the PHP worker holds the connection
open (up to `LONGPOLL_MS`, default 15s) and returns the instant a command is
pushed - sub-second pickup in practice - or `204` once the deadline passes.
Pass `?nowait=1` to disable the hold and get an immediate `204`/body, useful
for synchronous probes.

A GET also **self-heals a wedged session**: polling for new work while a
command is still marked in-flight proves that command's executor is gone (an
executing listener never polls), so the relay clears the marker and queues a
synthetic `[remotify: ...]` result for whoever is still waiting on it. A
listener that died mid-command (kill -9, closed terminal, reboot) therefore
stops blocking the session the moment it - or a replacement - reconnects.

### `DELETE /cmd-{key}`
Drops the hot command slot without consuming (no archive, no listener side
effects). Used by the MCP server to cancel its own queued command when it
gives up waiting for a listener, so the command won't execute as an orphan
when one eventually connects.

### `POST /result-{key}` / `GET /result-{key}`
Symmetric endpoints for the listener to post results and the client to fetch
them. `GET /result-{key}` long-polls exactly like `GET /cmd-{key}` (same
`LONGPOLL_MS` window, same `?nowait=1` escape hatch).

### `GET /r/{key}?mode=supervised|auto`
Returns a ready-to-`bash` runner script. The remote operator runs:

```bash
curl -fsSL 'https://relay.remotify.run/r/KEY' | bash              # supervised
curl -fsSL 'https://relay.remotify.run/r/KEY?mode=auto' | bash    # auto
```

`supervised` previews every incoming command and waits for `y/N` (accepts `y`,
`Y`, `yes`, etc. - any response starting with y/Y). `auto` trusts every command
(only on hosts where the session key is fully private).

Both modes print each incoming command as `>>> <cmd>` before running it and
`<<< done (N bytes pushed back)` after the result is posted back, so the
operator can see traffic even in auto mode.

The runner exports a non-interactive env block (`DEBIAN_FRONTEND=noninteractive`,
`CI=true`, `TERM=dumb`, `PAGER=cat`, `SYSTEMD_PAGER=cat`, `GIT_TERMINAL_PROMPT=0`)
before executing each command, so apt, pagers, and git don't block on TTY input.
Sudo still prompts unless the caller uses `sudo -n` - the operator sees the
preview line and can choose to authenticate.

`supervised` mode needs an attached terminal to ask `y/N`; if none is present
(nohup, CI, piped in from something other than an interactive shell) the
runner exits immediately with a message pointing at `?mode=auto` instead of
hanging. The output of any command that exits non-zero gets a trailing
`[remotify: exit status N]` marker so the client can tell success from
failure; very large output is truncated to a head plus a marker rather than
dropped or rejected outright. Result pushes are gzip-compressed when `gzip`
is available on the remote, falling back to a plain POST when it isn't, so a
box with only `curl` + `bash` still works.

A poll answered `410` (session gone) or `426` (hostname retired) ends the
runner with a message, because neither can be fixed by retrying. Every other
poll failure is retried with a widening wait, 2s doubling up to 30s and reset
by the next answered poll, so a relay that is down is not polled - and its
error line not reprinted - every two seconds for as long as the listener is
left running.

### `GET /api/health`
Returns `{"ok": true, "service": "<DOMAIN>"}`, so a monitor can also confirm which relay answered.

### Requests arriving on a retired hostname

A name that still resolves here but is not `DOMAIN` is answered `426 Upgrade
Required` on every route, carrying the current endpoint and the fix. Nothing is
served on the old name; clients fail loudly until they are updated.

`POST /api/session` and `GET /api/session/{key}` are the exception: they answer
`200` with a session payload whose quickstart lines and `mcp_messages`
templates carry the notice in place of a command. Clients render those and
discard error bodies, so it is the only way the message reaches an operator.
The payload is dead by construction - no session exists behind it, and its URLs
use a scheme no HTTP client can open - so nothing runs.

Skipped entirely when `DOMAIN=localhost`, so a local stack reached over
`127.0.0.1` keeps working.

This includes health probes: an uptime monitor that hits the relay by IP or
by a hostname other than `DOMAIN` sees `426`, not `200`. Point monitors at
`GET https://DOMAIN/api/health` (or send a `Host: DOMAIN` header).

## Landing page

`GET /` serves a tiny zero-dependency HTML page that calls `/api/session`,
shows the generated key, the remote-side one-liner with a copy button, and the
`curl`-only exec template you can paste straight into any terminal. Useful
when an operator just needs the one-liner without hitting the API manually.

## Using it from an LLM tool

Two integration paths. Pick whichever your tool supports:

- **MCP** (most coding agents): your tool runs `mcp/server.js` locally and the model gets a native `remote_exec` tool.
- **HTTP / curl** (any tool with a Bash/shell tool): drop `templates/AGENTS.md.example` into your repo as the rules file your tool reads; the model calls the API itself with curl.

The MCP server is on npm as [`remotify-mcp`](https://www.npmjs.com/package/remotify-mcp) - every host below can use `npx` to run it with zero install.

### Claude Code

```bash
claude mcp add -s user remotify -- npx -y remotify-mcp@latest
```
`-s user` registers the server globally (in `~/.claude.json` at the user scope) so it's available from every working directory. Without `-s user` it lands in project-local scope and only appears in sessions whose cwd matches where you ran the command.

Verify:
```bash
claude mcp list          # remotify should be listed
```
Inside a session, the `/mcp` slash-command shows the live status and the exposed `remote_exec` tool.

### Cursor

Edit `~/.cursor/mcp.json`:
```json
{ "mcpServers": { "remotify": { "command": "npx", "args": ["-y", "remotify-mcp@latest"] } } }
```
Restart Cursor.

### Codex CLI (OpenAI)

Edit `~/.codex/config.toml`:
```toml
[mcp_servers.remotify]
command = "npx"
args    = ["-y", "remotify-mcp@latest"]
```

### OpenCode V2

```bash
opencode mcp add remotify --global -- npx -y remotify-mcp@latest
```

Requires Node.js 18+ with `npx`. `--global` registers the server for every
project in `~/.config/opencode/opencode.jsonc`; omit it for the current project
only. No account, API key, or global npm install is needed.

Check the connection with `opencode mcp list` or `/mcps` inside OpenCode.
Ask OpenCode to call `remote_session_info` and show both connection one-liners,
then paste your chosen line on the remote. Supervised mode asks for approval
before each command; auto mode executes without approval.

### Gemini CLI

Edit `~/.gemini/settings.json`:
```json
{ "mcpServers": { "remotify": { "command": "npx", "args": ["-y", "remotify-mcp@latest"] } } }
```

### Any other MCP-capable tool

Windsurf, Continue, Cline/Roo Code, Zed, VS Code's built-in MCP - they all accept the same `command` + `args` shape, just in a config file they each document. Drop the snippet in and you're done.

### Tools that are NOT MCP-capable (ChatGPT web, Ollama UIs, any bash-only agent, ...)

Drop [`templates/AGENTS.md.example`](templates/AGENTS.md.example) into your project as the rules file the tool reads - common filenames: `AGENTS.md`, `CLAUDE.md`, `.cursorrules`, `CONVENTIONS.md`, `.github/copilot-instructions.md`. It teaches the model to `POST /api/session` and use curl to push/pull. No server-side integration needed.

### Overriding the relay

All snippets above default to `https://relay.remotify.run`. If you self-host, add one env var:
```json
"env": { "REMOTIFY_URL": "https://remotify.example.com" }
```


## Using it from anything else

If it speaks HTTP, it can talk to the relay. The only API call needed to
get started is `POST /api/session`; everything after that is an HTTP GET or
POST against `DOMAIN` - there is no separate `pipe.` subdomain, it's all one vhost.

## Security model

- **Key = access.** 128-bit random hex, unguessable at internet scale. Keep
  it private; anyone with the key can push commands to any listener on that key.
- **TLS is mandatory in production.** Keys travel in URL paths; HTTPS prevents
  interception.
- **nginx rate-limits** `POST /api/session` per IP (see `RATE_LIMIT`).
- **Data-minimised persistence.** Each session's pending command/result and
  push history live in a `0700` directory on the relay host, readable only by
  the PHP worker user. Kept only as long as needed: purged on the idle TTL
  (default 3h) or an explicit `DELETE`.
- **Supervised mode on the remote.** `/r/KEY?mode=supervised` prompts `y/N`
  before running each incoming command.
- **`--data-raw`** (rather than plain `-d` / `--data`) is used throughout to
  avoid curl's `@file` magic - a command that starts with `@` will not be
  read as a filename.

Threat-wise this is roughly equivalent to handing someone an SSH session:
commands run as whatever user pasted the remote one-liner. Do not paste the
one-liner on a shell you would not SSH into.

## License

Apache-2.0. See [`LICENSE`](LICENSE) and [`NOTICE`](NOTICE).
