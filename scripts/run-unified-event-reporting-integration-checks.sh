#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WP_ENV_DIR="${ORAS_WP_ENV_DIR:-/home/rocco/projects/oras-wp-env}"
WP_ENV_CMD="${ORAS_WP_ENV_CMD:-npx --yes @wordpress/env}"
CHECK_FILE="$ROOT_DIR/scripts/unified-event-reporting-integration-checks.php"

if [[ ! -f "$WP_ENV_DIR/.wp-env.json" ]]; then
	echo "Verified disposable wp-env is unavailable: $WP_ENV_DIR" >&2
	exit 1
fi

(
	cd "$WP_ENV_DIR"
	if ! bash -lc "$WP_ENV_CMD run cli wp option get home >/dev/null 2>&1"; then
		bash -lc "$WP_ENV_CMD start"
	fi
	if ! bash -lc "$WP_ENV_CMD run cli wp plugin is-installed woocommerce >/dev/null 2>&1"; then
		bash -lc "$WP_ENV_CMD run cli wp plugin install woocommerce --activate"
	elif ! bash -lc "$WP_ENV_CMD run cli wp plugin is-active woocommerce >/dev/null 2>&1"; then
		bash -lc "$WP_ENV_CMD run cli wp plugin activate woocommerce"
	fi
	bash -lc "$WP_ENV_CMD run cli wp plugin activate oras-tickets >/dev/null 2>&1 || true"
	CHECK_FILE_B64="$(base64 -w 0 "$CHECK_FILE")"
	bash -lc "$WP_ENV_CMD run cli sh -lc 'echo \"$CHECK_FILE_B64\" | base64 -d > /tmp/oras-unified-event-reporting-checks.php && wp eval-file /tmp/oras-unified-event-reporting-checks.php'"
)
