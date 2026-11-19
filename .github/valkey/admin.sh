#!/usr/bin/env bash
#
# Run one valkey-cli command as nexo_ci_admin inside the test container
# (ADR-0022 §4). For CI steps only: the sentinel and command statistics.
set -euo pipefail

NAME="${NEXO_VALKEY_CONTAINER:-nexo-valkey}"
RUNTIME="${NEXO_VALKEY_RUNTIME:-${RUNNER_TEMP:-${TMPDIR:-/tmp}}/nexo-valkey}"

MSYS_NO_PATHCONV=1 docker exec -e "REDISCLI_AUTH=$(cat "$RUNTIME/admin.pass")" "$NAME" \
    valkey-cli --no-auth-warning --user nexo_ci_admin "$@"
