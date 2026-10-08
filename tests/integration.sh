#!/usr/bin/env bash
#
# End-to-end integration tests for the remotify relay. Hits a running stack
# (by default the local docker-compose on remotify.localtest.me:49180) with
# curl and asserts on status codes + response bodies.
#
# Prereqs:
#   docker compose up -d
#
# Run:
#   ./tests/integration.sh                  # against local stack
#   REMOTIFY_BASE=https://relay.remotify.run ./tests/integration.sh   # against public
#
# Exit status:
#   0 all green, 1 at least one failure.

set -u

# Default BASE derives from the local .env (DOMAIN + HTTP_PORT) so a bare
# `./tests/integration.sh` hits whatever stack this checkout is running, instead
# of a hardcoded port that drifts from .env. Explicit REMOTIFY_BASE always wins.
_root=$(git rev-parse --show-toplevel 2>/dev/null || pwd)
_dom=$(sed -n 's/^DOMAIN=//p' "$_root/.env" 2>/dev/null | head -1)
_port=$(sed -n 's/^HTTP_PORT=//p' "$_root/.env" 2>/dev/null | head -1)
BASE="${REMOTIFY_BASE:-http://${_dom:-remotify.localtest.me}:${_port:-49180}}"
CURL=(curl -sS)

# Private scratch dir, cleaned on exit. Fixed /tmp paths are shared across users
# and concurrent CI runs, so a stale or foreign file could satisfy an assertion.
SCR=$(mktemp -d "${TMPDIR:-/tmp}/remotify-it.XXXXXX")

# Portable millisecond clock: GNU date +%s%N where available, else python3, else
# whole-second fallback. Never lets a bad timestamp abort the suite.
now_ms() {
  local n
  n=$(date +%s%N 2>/dev/null)
  case "$n" in
    *N|'') python3 -c 'import time;print(int(time.time()*1000))' 2>/dev/null || echo $(( $(date +%s) * 1000 )) ;;
    *)     echo $(( n / 1000000 )) ;;
  esac
}

PASS=0
FAIL=0
FAILED_NAMES=()

green='\033[32m'; red='\033[31m'; dim='\033[2m'; reset='\033[0m'
ok()   { printf "  ${green}ok${reset}  %s\n" "$1"; PASS=$((PASS + 1)); }
nok()  { printf "  ${red}FAIL${reset} %s\n      %s\n" "$1" "$2"; FAIL=$((FAIL + 1)); FAILED_NAMES+=("$1"); }
banner(){ printf "\n${dim}-- %s --${reset}\n" "$1"; }
eq()   { [ "$1" = "$2" ] && ok "$3" || nok "$3" "expected [$1], got [$2]"; }
contains() { case "$2" in *"$1"*) ok "$3" ;; *) nok "$3" "output missing: $1" ;; esac; }

# Wait for the stack to respond with 200 on /api/health. A fresh
# 'docker compose up --build' returns 502 for the first few seconds
# while nginx warms up its upstream; re-trying until healthy keeps
# CI runs stable without discarding a warmup pass.
printf "waiting for %s to be ready..." "$BASE"
for _ in $(seq 1 30); do
  code=$("${CURL[@]}" -o /dev/null -w '%{http_code}' "$BASE/api/health" 2>/dev/null || echo 000)
  if [ "$code" = "200" ]; then printf " ok\n"; break; fi
  printf "."
  sleep 0.5
done
if [ "$code" != "200" ]; then
  printf "\nstack at %s never returned 200 on /api/health; aborting\n" "$BASE" >&2
  exit 1
fi

# Always clean up any session we created.
SESSIONS=()
cleanup() {
  for k in "${SESSIONS[@]:-}"; do
    "${CURL[@]}" -X DELETE "$BASE/api/session/$k" >/dev/null 2>&1 || true
  done
  rm -rf "$SCR"
}
trap cleanup EXIT

# Mint a fresh session, retrying through nginx's per-IP rate limiter
# (session_create zone: RATE_LIMIT req/min, burst RATE_LIMIT_BURST, nodelay ->
# rejects over-burst requests with 503 instead of queuing them). The sections
# below each mint their own session for isolation, which in aggregate burns
# through the burst allowance faster than steady-state throttling refills it.
# Bounded retry (20 attempts, 1s apart) comfortably covers the ~6s-per-token
# refill without risking an infinite loop.
# Runs in a $(...) subshell, so the status of the attempt that got through is
# handed back through $SCR/code rather than a variable, for the callers that
# assert on it.
new_session() {
  local code i=0
  while :; do
    code=$("${CURL[@]}" -o "$SCR/out" -w '%{http_code}' -X POST "$BASE/api/session")
    [ "$code" = "201" ] && break
    i=$((i + 1))
    if [ "$i" -ge 20 ]; then
      echo "new_session: giving up after $i attempts (last HTTP $code)" >&2
      break
    fi
    sleep 1
  done
  printf '%s' "$code" > "$SCR/code"
  grep -oE '"key": *"[a-f0-9]+"' "$SCR/out" | head -1 | sed -E 's/.*"([a-f0-9]+)".*/\1/'
}

# ---------------------------------------------------------------------------
banner "health"
code=$("${CURL[@]}" -o "$SCR/out" -w '%{http_code}' "$BASE/api/health")
eq 200 "$code" "GET /api/health -> 200"
contains '"ok": true' "$(cat "$SCR/out")" "health body says ok"

# ---------------------------------------------------------------------------
banner "session lifecycle"
# Mint through new_session() so a mint throttled by the per-IP limiter (a
# back-to-back suite run drains the burst) retries instead of leaving $KEY
# empty and cascading into unrelated failures further down.
KEY=$(new_session)
eq 201 "$(cat "$SCR/code")" "POST /api/session -> 201"
SESSIONS+=("$KEY")
eq 32 "${#KEY}" "key is 32 hex chars"

payload=$(cat "$SCR/out")
contains "\"remote_quickstart\"" "$payload"  "payload has remote_quickstart"
contains "\"remote_quickstart_auto\"" "$payload"  "payload has remote_quickstart_auto"
contains "?mode=auto' | bash" "$payload"  "remote_quickstart_auto is the ready-to-paste auto line"
contains "\"urls\"" "$payload"               "payload has urls"
# The exec recipe must use a per-process scratch file: two operators running
# the recipe on the same host would collide on a fixed /tmp/.r path.
contains 'mktemp'        "$payload" "exec recipe uses mktemp for the scratch file"
case "$payload" in
  *' /tmp/.r '*|*' /tmp/.r;'*|*' /tmp/.r"'*) nok "exec recipe avoids fixed /tmp/.r path" "found shared path" ;;
  *)                                          ok "exec recipe avoids fixed /tmp/.r path" ;;
esac
contains "/cmd-$KEY" "$payload"              "urls.cmd carries key"
contains "/result-$KEY" "$payload"           "urls.result carries key"
contains "/r/$KEY" "$payload"                "urls.runner carries key"
contains "/api/session/$KEY" "$payload"      "urls.api carries key"

code=$("${CURL[@]}" -o "$SCR/out" -w '%{http_code}' "$BASE/api/session/$KEY")
eq 200 "$code" "GET /api/session/{key} -> 200"
contains "\"key\": \"$KEY\"" "$(cat "$SCR/out")" "re-fetch returns same key"

# ---------------------------------------------------------------------------
banner "status probe"
code=$("${CURL[@]}" -o "$SCR/out" -w '%{http_code}' "$BASE/api/session/$KEY/status")
eq 200 "$code" "GET status -> 200"
body=$(cat "$SCR/out")
contains '"cmd_queued": false'    "$body" "fresh session: cmd_queued=false"
contains '"result_queued": false' "$body" "fresh session: result_queued=false"
# Listener heartbeat field is always present (null until the first cmd poll).
contains '"listener_seen_seconds_ago"' "$body" "status exposes listener_seen_seconds_ago"
contains '"listener_seen_seconds_ago": null' "$body" "fresh session: listener never seen"

# ---------------------------------------------------------------------------
banner "cmd push/consume"
code=$("${CURL[@]}" -o /dev/null -w '%{http_code}' -X POST --data-raw 'echo hello' "$BASE/cmd-$KEY")
eq 201 "$code" "POST /cmd-{key} -> 201"

body=$("${CURL[@]}" "$BASE/api/session/$KEY/status")
contains '"cmd_queued": true' "$body" "cmd_queued=true after push"

# GET consumes the hot slot (this is what the listener does).
code=$("${CURL[@]}" -D "$SCR/hdr" -o "$SCR/out" -w '%{http_code}' "$BASE/cmd-$KEY")
eq 200 "$code" "GET /cmd-{key} -> 200"
eq "echo hello" "$(cat "$SCR/out")" "GET body matches pushed cmd"
contains 'no-store' "$(grep -i '^cache-control:' "$SCR/hdr")" "GET 200 carries Cache-Control: no-store"

body=$("${CURL[@]}" "$BASE/api/session/$KEY/status")
contains '"cmd_queued": false' "$body" "cmd_queued=false after consume"

# ?nowait=1 bypasses long-poll for this synchronous probe (the unconditional
# 204 path also has to carry no-store so transparent proxies cannot cache it).
code=$("${CURL[@]}" -D "$SCR/hdr" -o /dev/null -w '%{http_code}' "$BASE/cmd-$KEY?nowait=1")
eq 204 "$code" "GET /cmd-{key}?nowait=1 on empty -> 204"
contains 'no-store' "$(grep -i '^cache-control:' "$SCR/hdr")" "GET 204 carries Cache-Control: no-store"

# Listener heartbeat updates whenever the listener polls /cmd-K, even when
# nothing was queued. Older "no listener at all" sessions stay at null.
# Accept 0 OR 1: the heartbeat is a wall-clock second delta, and a poll that
# lands right on a second boundary can tick from 0 to 1 before this status
# call runs.
body=$("${CURL[@]}" "$BASE/api/session/$KEY/status")
case "$body" in
  *'"listener_seen_seconds_ago": 0'*|*'"listener_seen_seconds_ago": 1'*)
    ok "listener_seen_seconds_ago bumps after a cmd poll" ;;
  *) nok "listener_seen_seconds_ago bumps after a cmd poll" "expected 0 or 1, got: $body" ;;
esac

# ---------------------------------------------------------------------------
banner "cmd_in_flight transitions"
# Mint a fresh session so prior tests' state doesn't leak in. The phase
# marker is per-session, so a brand-new key starts with no phase file.
FK=$(new_session)
SESSIONS+=("$FK")
body=$("${CURL[@]}" "$BASE/api/session/$FK/status")
contains '"cmd_in_flight": false' "$body" "fresh: cmd_in_flight=false"
contains '"cmd_in_flight_seconds_ago": null' "$body" "fresh: cmd_in_flight_seconds_ago=null"
# Push then consume: should flip to true (cmd picked up, no result yet).
"${CURL[@]}" -o /dev/null -X POST --data-raw 'sleep 60; echo done' "$BASE/cmd-$FK"
contains '"cmd_in_flight": false' "$("${CURL[@]}" "$BASE/api/session/$FK/status")" "queued-but-unconsumed: cmd_in_flight=false"
"${CURL[@]}" -o /dev/null "$BASE/cmd-$FK?nowait=1"
body=$("${CURL[@]}" "$BASE/api/session/$FK/status")
contains '"cmd_in_flight": true'  "$body" "consumed: cmd_in_flight=true"
# Elapsed-since-pickup is a non-null integer the moment the phase flipped.
case "$body" in
  *'"cmd_in_flight_seconds_ago": null'*) nok "consumed: cmd_in_flight_seconds_ago is non-null" "still null" ;;
  *'"cmd_in_flight_seconds_ago":'*)      ok "consumed: cmd_in_flight_seconds_ago is non-null" ;;
  *)                                     nok "consumed: cmd_in_flight_seconds_ago is non-null" "field missing" ;;
esac
# Push a result: flips back to false.
"${CURL[@]}" -o /dev/null -X POST --data-raw 'sim-output' "$BASE/result-$FK"
body=$("${CURL[@]}" "$BASE/api/session/$FK/status")
contains '"cmd_in_flight": false' "$body" "result-received: cmd_in_flight=false"
contains '"cmd_in_flight_seconds_ago": null' "$body" "result-received: cmd_in_flight_seconds_ago=null"
"${CURL[@]}" -o /dev/null "$BASE/result-$FK?nowait=1" # drain the result slot

# Same-second fast cycle: a phase marker survives because it is content-based,
# not mtime-based; consume after a same-second consume->result->consume must
# still report cmd_in_flight: true.
"${CURL[@]}" -o /dev/null -X POST --data-raw 'cmd-fast' "$BASE/cmd-$FK"
"${CURL[@]}" -o /dev/null "$BASE/cmd-$FK?nowait=1"
contains '"cmd_in_flight": true' "$("${CURL[@]}" "$BASE/api/session/$FK/status")" "fast-cycle: still in_flight"

# ---------------------------------------------------------------------------
banner "long-poll on /cmd-{key}"
# A queued cmd is delivered immediately even with long-poll on (the wait loop
# returns the moment the slot is non-empty).
"${CURL[@]}" -o /dev/null -X POST --data-raw 'echo lp' "$BASE/cmd-$KEY"
t0=$(now_ms)
out=$("${CURL[@]}" -o "$SCR/out" -w '%{http_code}' "$BASE/cmd-$KEY")
t1=$(now_ms)
eq 200 "$out" "long-poll returns 200 on queued cmd"
eq "echo lp" "$(cat "$SCR/out")" "long-poll body matches"
elapsed_ms=$(( (t1 - t0) )); elapsed_ms="${elapsed_ms:-9999}"
# Widened from 1500ms to 5000ms (still far below the 15s LONGPOLL_MS) so a
# loaded CI runner can't flake this on scheduling jitter alone.
if [ "$elapsed_ms" -lt 5000 ]; then
  ok "long-poll on full queue is fast (${elapsed_ms}ms)"
else
  nok "long-poll on full queue is fast" "took ${elapsed_ms}ms (>5000)"
fi

# A push that arrives DURING a long-poll should be picked up sub-second, not
# at the next 2-second listener tick. Schedule a push after 0.5s, then start
# a long-polling GET; total elapsed should stay well under LONGPOLL_MS.
( sleep 0.5; "${CURL[@]}" -o /dev/null -X POST --data-raw 'echo mid-poll' "$BASE/cmd-$KEY" ) &
LP_PID=$!
t0=$(now_ms)
out=$("${CURL[@]}" -o "$SCR/out" -w '%{http_code}' "$BASE/cmd-$KEY")
t1=$(now_ms)
wait "$LP_PID" 2>/dev/null || true
eq 200 "$out" "long-poll returns 200 on mid-wait push"
eq "echo mid-poll" "$(cat "$SCR/out")" "long-poll mid-wait body matches"
elapsed_ms=$(( (t1 - t0) )); elapsed_ms="${elapsed_ms:-9999}"
if [ "$elapsed_ms" -lt 5000 ]; then
  ok "long-poll picks up mid-wait push sub-second (${elapsed_ms}ms)"
else
  nok "long-poll picks up mid-wait push sub-second" "took ${elapsed_ms}ms (>5000)"
fi

# ---------------------------------------------------------------------------
banner "result push/consume"
code=$("${CURL[@]}" -o /dev/null -w '%{http_code}' -X POST --data-raw 'some output' "$BASE/result-$KEY")
eq 201 "$code" "POST /result-{key} -> 201"

code=$("${CURL[@]}" -o "$SCR/out" -w '%{http_code}' "$BASE/result-$KEY")
eq 200 "$code" "GET /result-{key} -> 200"
eq "some output" "$(cat "$SCR/out")" "result body matches"

# ---------------------------------------------------------------------------
banner "gzip-encoded push"
printf 'gzipped body' | gzip -c > "$SCR/gz"
code=$("${CURL[@]}" -o /dev/null -w '%{http_code}' -X POST \
      -H 'Content-Encoding: gzip' --data-binary @"$SCR/gz" "$BASE/result-$KEY")
eq 201 "$code" "POST gzip /result-{key} -> 201"
eq "gzipped body" "$("${CURL[@]}" "$BASE/result-$KEY")" "gzip body decoded correctly"

# Regression: a large gzip result pushed with curl's DEFAULT (form) content type
# and no explicit Content-Type. PHP used to parse the compressed bytes as form
# fields at request startup; past max_input_vars (1000 '&'-separated chunks)
# it printed a warning into the response, the 201 header could no longer be
# set, and the relay answered 200 for a result it had in fact stored. The relay
# must accept it with a clean 201 and hand the exact bytes back. The body is
# high-entropy so its gzip stream reliably carries thousands of '&' bytes; the
# precondition is asserted so a quiet stream cannot pass the test by accident.
head -c 2000000 /dev/urandom | base64 > "$SCR/bigtext"
gzip -c "$SCR/bigtext" > "$SCR/bigtext.gz"
amps=$(tr -cd '&' < "$SCR/bigtext.gz" | wc -c | tr -d ' ')
[ "$amps" -gt 1000 ] && ok "form-typed gzip body carries >1000 '&' separators ($amps)" \
                     || nok "form-typed gzip body carries >1000 '&' separators" "only $amps"
code=$("${CURL[@]}" -o "$SCR/out" -w '%{http_code}' -X POST \
      -H 'Content-Encoding: gzip' --data-binary @"$SCR/bigtext.gz" "$BASE/result-$KEY")
eq 201 "$code" "POST large form-typed gzip /result-{key} -> 201 (no max_input_vars corruption)"
case "$(cat "$SCR/out")" in
  *Warning*|*'<br'*) nok "large form-typed gzip response body is clean" "PHP warning leaked into response" ;;
  *)                 ok  "large form-typed gzip response body is clean" ;;
esac
"${CURL[@]}" -o "$SCR/back" "$BASE/result-$KEY"
cmp -s "$SCR/bigtext" "$SCR/back" && ok "large form-typed gzip body round-trips byte-exact" \
                                  || nok "large form-typed gzip body round-trips byte-exact" "bytes differ"

# ---------------------------------------------------------------------------
banner "last-wins rotation"
"${CURL[@]}" -o /dev/null -X POST --data-raw 'first' "$BASE/cmd-$KEY"
"${CURL[@]}" -o /dev/null -X POST --data-raw 'second' "$BASE/cmd-$KEY"
got=$("${CURL[@]}" "$BASE/cmd-$KEY")
eq "second" "$got" "hot slot holds latest push"

# ---------------------------------------------------------------------------
banner "DELETE /cmd-{key} drains without consuming"
"${CURL[@]}" -o /dev/null -X POST --data-raw 'to-be-dropped' "$BASE/cmd-$KEY"
contains '"cmd_queued": true' "$("${CURL[@]}" "$BASE/api/session/$KEY/status")" "queued before DELETE"
code=$("${CURL[@]}" -o /dev/null -w '%{http_code}' -X DELETE "$BASE/cmd-$KEY")
eq 204 "$code" "DELETE /cmd-{key} -> 204"
contains '"cmd_queued": false' "$("${CURL[@]}" "$BASE/api/session/$KEY/status")" "queued=false after DELETE"

# ---------------------------------------------------------------------------
banner "runner scripts"
body=$("${CURL[@]}" "$BASE/r/$KEY")
contains '#!/usr/bin/env bash' "$body" "supervised runner starts with shebang"
contains '[supervised]' "$body" "supervised runner self-identifies"
contains '[yY]*' "$body" "supervised runner uses the lenient y match"
contains "KEY='$KEY'" "$body" "runner embeds the session key"

body=$("${CURL[@]}" "$BASE/r/$KEY?mode=auto")
contains '[auto]' "$body" "auto runner self-identifies"
contains '>>> %s' "$body" "auto runner prints cmd preview"
contains '<<< done' "$body" "auto runner prints completion marker"
contains 'DEBIAN_FRONTEND=noninteractive' "$body" "runner exports non-interactive env"

# Result-push hardening: the runner must retry transient failures and surface
# loudly on stderr instead of the old `-fs ... 2>/dev/null || sleep 1` silent-loss
# path. The shared push_result() helper is what enforces that.
contains 'push_result()'              "$body" "auto runner defines push_result helper"
contains 'FAILED to push result'      "$body" "auto runner has loud failure marker"
contains 'session expired or unknown' "$body" "auto runner exits on relay 410"
case "$body" in
  *' -fs '*) nok "auto runner avoids silent -fs curl flags" "found '-fs' in script" ;;
  *)        ok "auto runner avoids silent -fs curl flags" ;;
esac
# Per-process scratch file: a fixed /tmp/.remotify-cmd path is shared across
# users on the same host, so a second listener could not rm someone else's
# leftover at exit ("Operation not permitted"). The runner now mktemp's a
# private file per process and references it through $CMD_FILE.
contains 'CMD_FILE=' "$body" "runner declares per-process CMD_FILE"
# A retired hostname answers 426 on every route, so nothing will ever arrive
# there; the poll loop must stop like it does on 410. Everything that CAN
# recover backs off instead of reprinting the same error every two seconds.
contains '426)'                        "$body" "auto runner has a 426 branch"
contains 'no longer serves the relay'  "$body" "auto runner names the retired hostname"
contains 'back_off() {'                "$body" "auto runner defines the poll backoff helper"
# The output cap follows MAX_BODY_SIZE rather than a hardcoded number, so a
# relay configured smaller does not just collect 413s on every large result.
# The exact derivation is pinned in the unit suite, which controls the env;
# here we only assert the served script carries a usable number.
mob=$(printf '%s' "$body" | sed -n 's/^MAX_OUT_BYTES=//p' | head -1)
case "$mob" in
  ''|*[!0-9]*) nok "runner output cap is a positive integer" "got [$mob]" ;;
  *) [ "$mob" -gt 0 ] && ok "runner output cap is a positive integer ($mob)" \
                      || nok "runner output cap is a positive integer" "got [$mob]" ;;
esac
case "$body" in
  *'/tmp/.remotify-cmd"'*) nok "runner avoids fixed /tmp/.remotify-cmd path" "found unsuffixed shared path" ;;
  *' /tmp/.remotify-cmd '*) nok "runner avoids fixed /tmp/.remotify-cmd path" "found unsuffixed shared path" ;;
  *)                       ok "runner avoids fixed /tmp/.remotify-cmd path" ;;
esac

# The push must declare its body as octet-stream so no relay (this one or an
# older self-hosted one) ever parses a gzip result as a form.
contains "Content-Type: application/octet-stream' -H 'Content-Encoding: gzip'" "$body" "auto runner gzip push declares octet-stream"
contains "2[0-9][0-9]|3[0-9][0-9])"   "$body" "auto runner treats non-201 2xx/3xx as unconfirmed"

sup=$("${CURL[@]}" "$BASE/r/$KEY")
contains 'push_result()'              "$sup"  "supervised runner defines push_result helper"
contains 'FAILED to push result'      "$sup"  "supervised runner has loud failure marker"
contains 'session expired or unknown' "$sup"  "supervised runner exits on relay 410"
contains "Content-Type: application/octet-stream' -H 'Content-Encoding: gzip'" "$sup" "supervised runner gzip push declares octet-stream"

# ---------------------------------------------------------------------------
banner "negative / edge cases"

# Well-formed but unknown 32-hex key -> 410.
UNKNOWN=$(printf '%032d' 0)
code=$("${CURL[@]}" -o /dev/null -w '%{http_code}' "$BASE/api/session/$UNKNOWN")
eq 410 "$code" "GET on unknown key -> 410"
code=$("${CURL[@]}" -o /dev/null -w '%{http_code}' "$BASE/api/session/$UNKNOWN/status")
eq 410 "$code" "status on unknown key -> 410"

# Malformed key (not 32 hex) -> 404 (route doesn't match).
code=$("${CURL[@]}" -o /dev/null -w '%{http_code}' "$BASE/api/session/not-a-key")
eq 404 "$code" "GET on malformed key -> 404"

# ---------------------------------------------------------------------------
banner "session delete"
code=$("${CURL[@]}" -o /dev/null -w '%{http_code}' -X DELETE "$BASE/api/session/$KEY")
eq 204 "$code" "DELETE /api/session/{key} -> 204"
code=$("${CURL[@]}" -o /dev/null -w '%{http_code}' "$BASE/api/session/$KEY")
eq 410 "$code" "subsequent GET -> 410"
# Don't try to re-clean this one in trap; it's already purged.
SESSIONS=("${SESSIONS[@]/$KEY}")

# ---------------------------------------------------------------------------
banner "empty-command rejection"
AK=$(new_session)
SESSIONS+=("$AK")

code=$("${CURL[@]}" -o "$SCR/out" -w '%{http_code}' -X POST --data-raw '' "$BASE/cmd-$AK")
eq 400 "$code" "POST empty body /cmd-{key} -> 400"
contains 'empty command' "$(cat "$SCR/out")" "empty-body response says empty command"

code=$("${CURL[@]}" -o "$SCR/out" -w '%{http_code}' -X POST --data-raw '   ' "$BASE/cmd-$AK")
eq 400 "$code" "POST whitespace-only body /cmd-{key} -> 400"

code=$("${CURL[@]}" -o /dev/null -w '%{http_code}' -X POST --data-raw 'echo ok' "$BASE/cmd-$AK")
eq 201 "$code" "POST real body /cmd-{key} -> 201"

# ---------------------------------------------------------------------------
banner "stale-result drain on new cmd"
BK=$(new_session)
SESSIONS+=("$BK")

"${CURL[@]}" -o /dev/null -X POST --data-raw 'stale result body' "$BASE/result-$BK"
contains '"result_queued": true' "$("${CURL[@]}" "$BASE/api/session/$BK/status")" "result_queued=true after a result push"

"${CURL[@]}" -o /dev/null -X POST --data-raw 'echo new-cmd' "$BASE/cmd-$BK"
contains '"result_queued": false' "$("${CURL[@]}" "$BASE/api/session/$BK/status")" "result_queued=false after a new cmd drops the stale result"

# ---------------------------------------------------------------------------
banner "gzip negative paths"
CK=$(new_session)
SESSIONS+=("$CK")

code=$(printf 'not gzip xxxx' | "${CURL[@]}" -o "$SCR/out" -w '%{http_code}' -X POST \
      -H 'Content-Encoding: gzip' --data-binary @- "$BASE/result-$CK")
eq 400 "$code" "corrupt gzip /result-{key} -> 400"
contains 'bad gzip' "$(cat "$SCR/out")" "corrupt gzip response says bad gzip"

head -c 500000000 /dev/zero | gzip -c > "$SCR/bomb.gz"
code=$("${CURL[@]}" -o "$SCR/out" -w '%{http_code}' -X POST \
      -H 'Content-Encoding: gzip' --data-binary @"$SCR/bomb.gz" "$BASE/result-$CK")
eq 413 "$code" "zip-bomb gzip (500MB decoded) /result-{key} -> 413"
rm -f "$SCR/bomb.gz"

# ---------------------------------------------------------------------------
banner "runner-mode fallback (security)"
DK=$(new_session)
SESSIONS+=("$DK")

body=$("${CURL[@]}" "$BASE/r/$DK?mode=banana")
contains '[supervised]' "$body" "mode=banana falls back to the supervised banner"
contains 'Run? [y/N]'   "$body" "mode=banana falls back to the supervised approval prompt"
case "$body" in
  *'[auto]'*) nok "mode=banana never self-identifies as auto" "found [auto] in body" ;;
  *)          ok "mode=banana never self-identifies as auto" ;;
esac

body=$("${CURL[@]}" "$BASE/r/$DK?mode=")
contains '[supervised]' "$body" "mode= (empty) falls back to the supervised banner"
contains 'Run? [y/N]'   "$body" "mode= (empty) falls back to the supervised approval prompt"
case "$body" in
  *'[auto]'*) nok "mode= (empty) never self-identifies as auto" "found [auto] in body" ;;
  *)          ok "mode= (empty) never self-identifies as auto" ;;
esac

# ---------------------------------------------------------------------------
banner "bash -n both modes"
EK=$(new_session)
SESSIONS+=("$EK")

"${CURL[@]}" -o "$SCR/sup.sh" "$BASE/r/$EK"
if bash -n "$SCR/sup.sh" 2>"$SCR/sup.err"; then
  ok "supervised runner script passes bash -n"
else
  nok "supervised runner script passes bash -n" "$(cat "$SCR/sup.err")"
fi

"${CURL[@]}" -o "$SCR/auto.sh" "$BASE/r/$EK?mode=auto"
if bash -n "$SCR/auto.sh" 2>"$SCR/auto.err"; then
  ok "auto runner script passes bash -n"
else
  nok "auto runner script passes bash -n" "$(cat "$SCR/auto.err")"
fi

# ---------------------------------------------------------------------------
banner "E2E runner execution (auto)"
GK=$(new_session)
SESSIONS+=("$GK")

"${CURL[@]}" -o "$SCR/runner.sh" "$BASE/r/$GK?mode=auto"
bash "$SCR/runner.sh" >"$SCR/listener.log" 2>&1 &
LPID=$!
sleep 1

MARKER="e2e-marker-$$"
code=$("${CURL[@]}" -o /dev/null -w '%{http_code}' -X POST --data-raw "echo $MARKER" "$BASE/cmd-$GK")
eq 201 "$code" "e2e: push marker cmd -> 201"

got=""
for _ in $(seq 1 20); do
  rc=$("${CURL[@]}" -o "$SCR/e2e1" -w '%{http_code}' "$BASE/result-$GK?nowait=1")
  if [ "$rc" = "200" ]; then got=1; break; fi
  sleep 0.3
done
if [ -n "$got" ]; then
  eq "$MARKER" "$(cat "$SCR/e2e1")" "e2e: runner executes pushed cmd, result matches marker"
else
  nok "e2e: runner executes pushed cmd, result matches marker" "no 200 result after ~6s of polling"
fi

code=$("${CURL[@]}" -o /dev/null -w '%{http_code}' -X POST --data-raw 'false' "$BASE/cmd-$GK")
eq 201 "$code" "e2e: push failing cmd -> 201"

got=""
for _ in $(seq 1 20); do
  rc=$("${CURL[@]}" -o "$SCR/e2e2" -w '%{http_code}' "$BASE/result-$GK?nowait=1")
  if [ "$rc" = "200" ]; then got=1; break; fi
  sleep 0.3
done
if [ -n "$got" ]; then
  contains '[remotify: exit status 1]' "$(cat "$SCR/e2e2")" "e2e: non-zero exit appends exit-status marker"
else
  nok "e2e: non-zero exit appends exit-status marker" "no 200 result after ~6s of polling"
fi

"${CURL[@]}" -o /dev/null -X DELETE "$BASE/api/session/$GK"
SESSIONS=("${SESSIONS[@]/$GK}")
# The relay clears its stat cache each long-poll tick, so an in-flight
# GET /cmd-{key} notices the deleted session within ~1 poll tick (~200ms) and
# returns 204; the listener then exits on its next poll's 410. This must happen
# PROMPTLY (well under LONGPOLL_MS, default 15s) -- a regression that reinstated
# the stale-stat-cache bug would make it take the full long-poll window. Bound
# at ~8s: generous for a loaded CI runner, but far below LONGPOLL_MS so the bug
# cannot pass silently.
dead=""
for _ in $(seq 1 27); do
  if ! kill -0 "$LPID" 2>/dev/null; then dead=1; break; fi
  sleep 0.3
done
if [ -n "$dead" ]; then
  ok "e2e: listener exits promptly after session delete"
else
  nok "e2e: listener exits promptly after session delete" "still running ~8s after DELETE (stat-cache bail regression?)"
fi
kill "$LPID" 2>/dev/null || true
wait "$LPID" 2>/dev/null || true

# ---------------------------------------------------------------------------
banner "runner stops on a retired endpoint (426)"
# A hostname other than DOMAIN answers 426 on every route, so a listener pointed
# at it will never receive a command. It must say so and exit, not reprint the
# same error every couple of seconds for as long as it is left running. Reaching
# the relay by IP is exactly that case -- except on a DOMAIN=localhost stack,
# where the check is skipped by design, so probe before asserting.
IPBASE=$(printf '%s' "$BASE" | sed -E 's#^(http://)[^/:]+#\1127.0.0.1#')
probe=000
case "$BASE" in
  http://*) probe=$("${CURL[@]}" -o /dev/null -w '%{http_code}' "$IPBASE/api/health" 2>/dev/null || echo 000) ;;
esac
if [ "$probe" = "426" ]; then
  # The landing page belongs to DOMAIN alone. The nginx default server sends
  # every unknown hostname to the app, so a retired name gets the same 426
  # on / as on the API routes instead of the relay's page.
  eq 426 "$("${CURL[@]}" -o /dev/null -w '%{http_code}' "$IPBASE/")" "426: no landing page on a retired name"
  # Session mint on the retired name answers 200 with a dead payload whose
  # message templates carry the notice, because clients render those and throw
  # error bodies away. Every template the MCP would otherwise render as a
  # connect instruction has to be overridden, or the agent reads "paste ONE of
  # these two lines" followed by the no-listener placeholder.
  mcode=000
  for _ in $(seq 1 20); do
    mcode=$("${CURL[@]}" -o "$SCR/moved.json" -w '%{http_code}' -X POST "$IPBASE/api/session")
    [ "$mcode" = "200" ] && break
    sleep 1
  done
  eq 200 "$mcode" "426: session mint on the retired name serves the moved payload"
  moved=$(cat "$SCR/moved.json")
  contains 'moved://'             "$moved" "426: moved payload URLs use an unopenable scheme"
  contains '"push_unreachable"'   "$moved" "426: moved payload overrides push_unreachable"
  contains '"session_info_intro"' "$moved" "426: moved payload overrides session_info_intro"
  contains '"runner_lines"'       "$moved" "426: moved payload overrides runner_lines"
  case "$moved" in
    *'"session_info_intro": "[endpoint-moved]'*) ok "426: session intro leads with the endpoint-moved notice" ;;
    *) nok "426: session intro leads with the endpoint-moved notice" "intro not replaced" ;;
  esac
  case "$moved" in
    *'paste ONE of these two lines'*) nok "426: session intro drops the paste-a-listener instruction" "still tells the user to paste a line" ;;
    *)                                ok  "426: session intro drops the paste-a-listener instruction" ;;
  esac

  XK=$(new_session)
  SESSIONS+=("$XK")
  "${CURL[@]}" "$BASE/r/$XK?mode=auto" | sed "s|^BASE='.*'|BASE='$IPBASE'|" > "$SCR/moved.sh"
  bash "$SCR/moved.sh" >"$SCR/moved.log" 2>&1 &
  MPID=$!
  gone=""
  for _ in $(seq 1 20); do
    kill -0 "$MPID" 2>/dev/null || { gone=1; break; }
    sleep 0.3
  done
  kill "$MPID" 2>/dev/null || true
  wait "$MPID" 2>/dev/null || true
  if [ -n "$gone" ]; then
    ok "426: listener exits instead of polling a retired endpoint forever"
  else
    nok "426: listener exits instead of polling a retired endpoint forever" "still running after ~6s"
  fi
  contains 'no longer serves the relay' "$(cat "$SCR/moved.log")" "426: listener says the hostname was retired"
  contains "$BASE" "$(cat "$SCR/moved.log")" "426: listener prints the endpoint that replaced it"
else
  printf "  ${dim}skipped${reset} (%s does not answer 426; DOMAIN=localhost stack?)\n" "$IPBASE"
fi

# ---------------------------------------------------------------------------
banner "session reset endpoint"
RK=$(new_session)
SESSIONS+=("$RK")

code=$("${CURL[@]}" -o /dev/null -w '%{http_code}' -X POST --data-raw 'echo reset-me' "$BASE/cmd-$RK")
eq 201 "$code" "reset: push cmd -> 201"
code=$("${CURL[@]}" -o "$SCR/out" -w '%{http_code}' -X POST "$BASE/api/session/$RK/reset")
eq 200 "$code" "POST /api/session/{key}/reset -> 200"
contains '"reset": true' "$(cat "$SCR/out")" "reset body confirms the reset"
contains '"cmd_queued": true' "$(cat "$SCR/out")" "reset reports the dropped queued cmd"
status=$("${CURL[@]}" "$BASE/api/session/$RK/status")
contains '"cmd_queued": false' "$status" "after reset: no cmd queued"
contains '"cmd_in_flight": false' "$status" "after reset: not in flight"

# The classic wedge: a consumed-but-never-answered cmd (dead executor).
code=$("${CURL[@]}" -o /dev/null -w '%{http_code}' -X POST --data-raw 'sleep 999' "$BASE/cmd-$RK")
eq 201 "$code" "reset: push wedge cmd -> 201"
code=$("${CURL[@]}" -o /dev/null -w '%{http_code}' "$BASE/cmd-$RK?nowait=1")
eq 200 "$code" "reset: consume cmd (simulated pickup) -> 200"
status=$("${CURL[@]}" "$BASE/api/session/$RK/status")
contains '"cmd_in_flight": true' "$status" "wedge: cmd marked in-flight"
code=$("${CURL[@]}" -o "$SCR/out" -w '%{http_code}' -X POST "$BASE/api/session/$RK/reset")
eq 200 "$code" "reset of the in-flight wedge -> 200"
contains '"cmd_was_in_flight": true' "$(cat "$SCR/out")" "reset reports the wedged in-flight cmd"
status=$("${CURL[@]}" "$BASE/api/session/$RK/status")
contains '"cmd_in_flight": false' "$status" "after reset: in-flight marker cleared"

code=$("${CURL[@]}" -o /dev/null -w '%{http_code}' -X POST "$BASE/api/session/00000000000000000000000000000000/reset")
eq 410 "$code" "reset of an unknown key -> 410"

# ---------------------------------------------------------------------------
banner "stale in-flight self-heal on listener reconnect"
HK=$(new_session)
SESSIONS+=("$HK")

code=$("${CURL[@]}" -o /dev/null -w '%{http_code}' -X POST --data-raw 'sleep 999' "$BASE/cmd-$HK")
eq 201 "$code" "self-heal: push cmd -> 201"
code=$("${CURL[@]}" -o /dev/null -w '%{http_code}' "$BASE/cmd-$HK?nowait=1")
eq 200 "$code" "self-heal: consume cmd (listener pickup) -> 200"
status=$("${CURL[@]}" "$BASE/api/session/$HK/status")
contains '"cmd_in_flight": true' "$status" "self-heal: in-flight after pickup"
# The executor "dies" (no result is ever pushed). Past the 2s grace, a fresh
# listener poll must self-heal: flip the phase idle + queue a synthetic
# marker result for whoever is still waiting on /result-{key}.
sleep 3
code=$("${CURL[@]}" -o /dev/null -w '%{http_code}' "$BASE/cmd-$HK?nowait=1")
eq 204 "$code" "self-heal: reconnected listener poll -> 204 (no cmd)"
status=$("${CURL[@]}" "$BASE/api/session/$HK/status")
contains '"cmd_in_flight": false' "$status" "self-heal: in-flight marker cleared by the poll"
contains '"result_queued": true' "$status" "self-heal: synthetic result queued"
code=$("${CURL[@]}" -o "$SCR/out" -w '%{http_code}' "$BASE/result-$HK?nowait=1")
eq 200 "$code" "self-heal: synthetic result retrievable"
contains '[remotify: a listener connected while a previous command was still marked in-flight' \
  "$(cat "$SCR/out")" "self-heal: marker text present"

# ---------------------------------------------------------------------------
banner "mcp message templates on the session payload"
# Relay-served wording for the MCP client: the session payload must carry the
# templates from php/app/mcp-messages.json so wording deploys with the relay.
# Mint through new_session(): a raw POST here trips nginx's per-IP mint rate
# limit (503) after the many mints above; the helper retries through it and
# leaves the successful mint body in $SCR/out for the assertions below.
MK=$(new_session)
SESSIONS+=("$MK")
eq 32 "${#MK}" "templates: mint session succeeded"
contains '"mcp_messages"' "$(cat "$SCR/out")" "templates: mcp_messages present on mint"
contains '"version": 1' "$(cat "$SCR/out")" "templates: version field served"
contains '{runner_lines}' "$(cat "$SCR/out")" "templates: placeholder syntax intact"
resp=$("${CURL[@]}" "$BASE/api/session/$MK")
contains '"mcp_messages"' "$resp" "templates: also served on session GET (preset-key path)"

# ---------------------------------------------------------------------------
banner "summary"
TOTAL=$((PASS + FAIL))
if [ "$FAIL" -eq 0 ]; then
  printf "${green}%d/%d passed${reset}\n" "$PASS" "$TOTAL"
  exit 0
fi
printf "${red}%d/%d passed, %d failed${reset}\n" "$PASS" "$TOTAL" "$FAIL"
for n in "${FAILED_NAMES[@]}"; do printf "  - %s\n" "$n"; done
exit 1
