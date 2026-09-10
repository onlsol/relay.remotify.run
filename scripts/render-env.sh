#!/usr/bin/env bash
# Render .env from .env.example, overriding keys that are set as real environment
# variables (e.g. GitLab CI variables). Keys absent from .env.example are appended.
# Exits non-zero if DOMAIN is missing or is still the 'localhost' placeholder
# (unless ALLOW_LOCALHOST=1), so a prod deploy can never silently ship
# DOMAIN=localhost and hand out unreachable session URLs.
#
# The same silent failure exists one step later: a real DOMAIN with the
# .env.example SCHEME=http / PUBLIC_PORT=49180 defaults hands out
# http://DOMAIN:49180/... one-liners while the vhost actually serves
# https://DOMAIN/. So for a non-localhost DOMAIN this script also
#   - refuses SCHEME=http unless ALLOW_PLAIN_HTTP=1 (CI/test stacks), and
#   - when SCHEME is given but PUBLIC_PORT is not, sets PUBLIC_PORT to the
#     standard port of SCHEME (443/80) instead of keeping the example's 49180.
# PUBLIC_PORT given explicitly always wins (non-standard public ports).
set -euo pipefail

cp .env.example .env

for var in DOMAIN SCHEME PUBLIC_PORT NGINX_MODE HTTP_PORT HTTPS_PORT \
           RATE_LIMIT RATE_LIMIT_BURST PROXY_TIMEOUT MAX_BODY_SIZE LONGPOLL_MS \
           FPM_MAX_CHILDREN FPM_MAX_REQUESTS AUDIT_LOG SESSION_TTL SOURCE_URL \
           CERTBOT_EMAIL CERTBOT_STAGING COMPOSE_PROFILES; do
    val="${!var-}"
    [ -n "$val" ] || continue
    # Escape sed replacement metacharacters (\, &, and the | delimiter) so a
    # value like a SOURCE_URL containing '&' cannot corrupt the rendered .env.
    val_esc=$(printf '%s' "$val" | sed -e 's/[\\&|]/\\&/g')
    if grep -q "^${var}=" .env; then
        sed -i "s|^${var}=.*|${var}=${val_esc}|" .env
    else
        printf '%s=%s\n' "$var" "$val" >> .env
    fi
done

# Derive PUBLIC_PORT from SCHEME when the caller set SCHEME but not PUBLIC_PORT.
if [ -n "${SCHEME-}" ] && [ -z "${PUBLIC_PORT-}" ]; then
    case "$SCHEME" in
        https) sed -i 's|^PUBLIC_PORT=.*|PUBLIC_PORT=443|' .env ;;
        http)  sed -i 's|^PUBLIC_PORT=.*|PUBLIC_PORT=80|'  .env ;;
    esac
fi

dom=$(sed -n 's/^DOMAIN=//p' .env | head -1)
if [ -z "$dom" ]; then
    echo "render-env: DOMAIN must be set (via .env.example or a CI variable)" >&2
    exit 1
fi
if [ "$dom" = "localhost" ] && [ "${ALLOW_LOCALHOST:-0}" != "1" ]; then
    echo "render-env: DOMAIN is still 'localhost' - refusing to render for deploy." >&2
    echo "            Set the DOMAIN environment/CI variable, or ALLOW_LOCALHOST=1 for local use." >&2
    exit 1
fi
scheme=$(sed -n 's/^SCHEME=//p' .env | head -1)
if [ "$dom" != "localhost" ] && [ "$scheme" != "https" ] && [ "${ALLOW_PLAIN_HTTP:-0}" != "1" ]; then
    echo "render-env: DOMAIN=$dom with SCHEME=$scheme - refusing to render for deploy." >&2
    echo "            One-liners would point at http://$dom:$(sed -n 's/^PUBLIC_PORT=//p' .env)/." >&2
    echo "            Set the SCHEME=https environment/CI variable (PUBLIC_PORT then defaults to 443)," >&2
    echo "            or ALLOW_PLAIN_HTTP=1 for a test stack." >&2
    exit 1
fi
