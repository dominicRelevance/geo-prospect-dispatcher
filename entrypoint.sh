#!/bin/sh
# Materializes secrets that arrive as Railway env vars into files this
# process needs on disk. Nothing here is baked into the image — both
# env vars are set on the dispatcher's Railway service, not committed.
set -e

if [ -n "$DISPATCHER_SSH_PRIVATE_KEY" ]; then
    mkdir -p /root/.ssh
    printf '%s\n' "$DISPATCHER_SSH_PRIVATE_KEY" > /root/.ssh/id_ed25519
    chmod 600 /root/.ssh/id_ed25519
fi

if [ -n "$GOOGLE_SERVICE_ACCOUNT_JSON" ]; then
    printf '%s' "$GOOGLE_SERVICE_ACCOUNT_JSON" > /app/service-account.json
    export GOOGLE_SERVICE_ACCOUNT_FILE=/app/service-account.json
fi

exec python3 dispatcher.py
