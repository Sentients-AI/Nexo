#!/usr/bin/env bash
#
# Start a second, throwaway Valkey for tests/Redis/ProbeReconnectTest.php
# only: the misconfigured server the R6 probe exists to catch. Its default
# user may run anything, so the test can drop a probe's connection with
# CLIENT KILL and prove nothing queued runs. The test only ever writes its own
# keys there; it sends no destructive command.
#
# Published on 127.0.0.1:6391 only, no persistence, separate from the test
# Valkey (start.sh), which is never interrupted.
#
# Identity: a marker that is unpredictable and new on every run. It is stored
# on the new server and handed to later CI steps as
# NEXO_TEST_DISPOSABLE_REDIS_MARKER (not a secret: it only proves "this is the
# server this run started"). The test refuses any server that does not carry
# exactly that value.
#
# Fails, and starts nothing usable, if the port or the container name is
# taken, or if the new server is not empty before the marker is written.
set -euo pipefail

IMAGE='valkey/valkey:8.1.11-trixie@sha256:8f836008e0986c36adf50da714c7060cf689dbb335f61fe077939d90fa650809'
NAME="${NEXO_VALKEY_DISPOSABLE_CONTAINER:-nexo-valkey-disposable}"
PORT="${NEXO_TEST_DISPOSABLE_REDIS_PORT:-6391}"
MARKER="nexo-disposable-$(od -An -N16 -tx1 /dev/urandom | tr -d ' \n')"

cli() {
    MSYS_NO_PATHCONV=1 docker exec "$NAME" valkey-cli -p "$PORT" "$@"
}

# docker run fails, and set -e stops here, if NAME exists or PORT is in use.
MSYS_NO_PATHCONV=1 docker run -d --name "$NAME" \
    -p "127.0.0.1:${PORT}:${PORT}" \
    "$IMAGE" valkey-server --port "$PORT" --save '' --appendonly no --protected-mode no >/dev/null

for _ in $(seq 1 30); do
    if [ "$(cli PING 2>/dev/null)" = "PONG" ]; then
        if [ -n "$(cli INFO keyspace | grep '^db')" ]; then
            echo "The new disposable Valkey is not empty; refusing to use it" >&2
            exit 1
        fi

        test "$(cli SET nexo_disposable:marker "$MARKER")" = OK

        if [ -n "${GITHUB_ENV:-}" ]; then
            echo "NEXO_TEST_DISPOSABLE_REDIS_MARKER=$MARKER" >> "$GITHUB_ENV"
        else
            echo "export NEXO_TEST_DISPOSABLE_REDIS_MARKER=$MARKER"
        fi

        echo "disposable Valkey ready on 127.0.0.1:${PORT}" >&2
        exit 0
    fi
    sleep 1
done

echo "The disposable Valkey did not become ready" >&2
docker logs "$NAME" >&2 || true
exit 1
