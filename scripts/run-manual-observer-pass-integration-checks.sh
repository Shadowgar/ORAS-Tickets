#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CHECK_FILE="$ROOT_DIR/scripts/manual-observer-pass-integration-checks.php"
WP_ENV_CMD="${ORAS_WP_ENV_CMD:-npx --yes @wordpress/env}"

CHECK_FILE_B64="$(base64 -w 0 "$CHECK_FILE")"
bash -lc "$WP_ENV_CMD run cli sh -lc 'echo \"$CHECK_FILE_B64\" | base64 -d > /tmp/oras-manual-observer-pass-checks.php && wp eval-file /tmp/oras-manual-observer-pass-checks.php'"
