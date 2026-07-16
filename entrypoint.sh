#!/bin/sh
# Materializes secrets that arrive as Railway env vars into files this
# process needs on disk. Nothing here is baked into the image — both
# env vars are set on the dispatcher's Railway service, not committed.
set -e

echo "[entrypoint] HOME=$HOME USER=$(whoami 2>/dev/null || echo '?')"
echo "[entrypoint] DISPATCHER_SSH_PRIVATE_KEY: ${#DISPATCHER_SSH_PRIVATE_KEY} chars"
echo "[entrypoint] GOOGLE_SERVICE_ACCOUNT_JSON: ${#GOOGLE_SERVICE_ACCOUNT_JSON} chars"

if [ -n "$DISPATCHER_SSH_PRIVATE_KEY" ]; then
    mkdir -p /root/.ssh
    printf '%s\n' "$DISPATCHER_SSH_PRIVATE_KEY" > /root/.ssh/id_ed25519
    chmod 600 /root/.ssh/id_ed25519
    echo "[entrypoint] wrote /root/.ssh/id_ed25519, $(wc -c < /root/.ssh/id_ed25519) bytes"
else
    echo "[entrypoint] WARNING: DISPATCHER_SSH_PRIVATE_KEY is empty/unset"
fi

if [ -n "$GOOGLE_SERVICE_ACCOUNT_JSON" ]; then
    printf '%s' "$GOOGLE_SERVICE_ACCOUNT_JSON" > /app/service-account.json
    export GOOGLE_SERVICE_ACCOUNT_FILE=/app/service-account.json
    echo "[entrypoint] wrote /app/service-account.json, $(wc -c < /app/service-account.json) bytes"
else
    echo "[entrypoint] WARNING: GOOGLE_SERVICE_ACCOUNT_JSON is empty/unset"
fi

exec python3 dispatcher.py
