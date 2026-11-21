#!/usr/bin/env bash
#
# Run one valkey-cli command as nexo_ci_admin inside the test container
# (ADR-0022 §4). For CI steps only: the sentinel and command statistics.
#
# The password is read inside the container from /run/nexo-admin.pass (see
# start.sh) into valkey-cli's own environment. It never appears on the
# runner, in a process argument or in the output.
set -euo pipefail

NAME="${NEXO_VALKEY_CONTAINER:-nexo-valkey}"

# shellcheck disable=SC2016 # expanded inside the container, not here
MSYS_NO_PATHCONV=1 docker exec "$NAME" sh -c \
    'REDISCLI_AUTH="$(cat /run/nexo-admin.pass)" exec valkey-cli --no-auth-warning --user nexo_ci_admin "$@"' \
    admin "$@"
