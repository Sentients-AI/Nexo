#!/usr/bin/env bash
#
# Start a second, throwaway Valkey for tests/Redis/ProbeReconnectTest.php
# only: the misconfigured server the R6 probe exists to catch. Its default
# user may run anything, so the test can drop a probe's connection with
# CLIENT KILL and prove nothing queued runs.
#
# Published on 127.0.0.1:6391 only, no persistence, separate from the test
# Valkey (start.sh), which is never interrupted. It holds nothing but a marker
# key; the test refuses to touch a server without it.
set -euo pipefail

IMAGE='valkey/valkey:8.1.11-trixie@sha256:8f836008e0986c36adf50da714c7060cf689dbb335f61fe077939d90fa650809'
NAME="${NEXO_VALKEY_DISPOSABLE_CONTAINER:-nexo-valkey-disposable}"
PORT="${NEXO_TEST_DISPOSABLE_REDIS_PORT:-6391}"

MSYS_NO_PATHCONV=1 docker run -d --name "$NAME" \
    -p "127.0.0.1:${PORT}:${PORT}" \
    "$IMAGE" valkey-server --port "$PORT" --save '' --appendonly no --protected-mode no >/dev/null

for _ in $(seq 1 30); do
    if [ "$(MSYS_NO_PATHCONV=1 docker exec "$NAME" valkey-cli -p "$PORT" PING 2>/dev/null)" = "PONG" ]; then
        MSYS_NO_PATHCONV=1 docker exec "$NAME" valkey-cli -p "$PORT" SET nexo_disposable:marker nexo-disposable-valkey >/dev/null
        echo "disposable Valkey ready on 127.0.0.1:${PORT}"
        exit 0
    fi
    sleep 1
done

echo "The disposable Valkey did not become ready" >&2
docker logs "$NAME" >&2 || true
exit 1
