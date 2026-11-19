#!/usr/bin/env bash
#
# Start the ADR-0022 test Valkey: standalone, published on 127.0.0.1 only,
# ACL-restricted, no persistence. Used by CI, and locally by anyone with
# Docker who wants to run the Redis suite.
#
# The admin user (nexo_ci_admin) gets a fresh random password, appended to a
# run-time copy of users.acl. The password is kept in $NEXO_VALKEY_RUNTIME and
# is used only by admin.sh; it is never exported to the PHP test environment.
set -euo pipefail

# Valkey 8.1 on Debian (owner decision 2026-11-17; 8.1.4+ ship no bookworm
# image, so trixie). Tag for readability, digest for immutability.
IMAGE='valkey/valkey:8.1.11-trixie@sha256:8f836008e0986c36adf50da714c7060cf689dbb335f61fe077939d90fa650809'
NAME="${NEXO_VALKEY_CONTAINER:-nexo-valkey}"
RUNTIME="${NEXO_VALKEY_RUNTIME:-${RUNNER_TEMP:-${TMPDIR:-/tmp}}/nexo-valkey}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

rm -rf "$RUNTIME"
mkdir -p "$RUNTIME/etc"
cp "$HERE/valkey.conf" "$HERE/users.acl" "$RUNTIME/etc/"

umask 077
ADMIN_PASSWORD="$(od -An -N24 -tx1 /dev/urandom | tr -d ' \n')"
printf '%s' "$ADMIN_PASSWORD" > "$RUNTIME/admin.pass"
printf 'user nexo_ci_admin on >%s resetkeys ~* resetchannels -@all +ping +info +get +set +exists +acl|whoami +acl|getuser +config|resetstat\n' \
    "$ADMIN_PASSWORD" >> "$RUNTIME/etc/users.acl"
chmod 0644 "$RUNTIME/etc/"*
chmod 0755 "$RUNTIME/etc"

MOUNT="$RUNTIME/etc"
if command -v cygpath >/dev/null 2>&1; then
    MOUNT="$(cygpath -w "$MOUNT")"
fi

MSYS_NO_PATHCONV=1 docker run -d --name "$NAME" \
    -p 127.0.0.1:6379:6379 \
    -v "$MOUNT:/etc/valkey:ro" \
    "$IMAGE" valkey-server /etc/valkey/valkey.conf >/dev/null

for _ in $(seq 1 30); do
    if [ "$("$HERE/admin.sh" PING 2>/dev/null)" = "PONG" ]; then
        "$HERE/admin.sh" INFO server | grep -E '^(valkey_version|redis_mode|os):'
        exit 0
    fi
    sleep 1
done

echo "Valkey did not become ready" >&2
docker logs "$NAME" >&2 || true
exit 1
