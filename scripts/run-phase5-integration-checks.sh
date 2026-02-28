#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CHECK_FILE="$ROOT_DIR/scripts/phase5-integration-checks.php"

if [[ ! -f "$CHECK_FILE" ]]; then
	echo "Phase 5 check script is missing: $CHECK_FILE" >&2
	exit 1
fi

WP_ENV_DIR="${ORAS_WP_ENV_DIR:-}"
if [[ -z "$WP_ENV_DIR" ]]; then
	if [[ -f "/home/rocco/projects/oras-wp-env/.wp-env.json" ]]; then
		WP_ENV_DIR="/home/rocco/projects/oras-wp-env"
	else
		WP_ENV_DIR="$ROOT_DIR"
	fi
fi

if [[ ! -f "$WP_ENV_DIR/.wp-env.json" ]]; then
	echo "No .wp-env.json found at: $WP_ENV_DIR" >&2
	exit 1
fi

WP_ENV_CMD="${ORAS_WP_ENV_CMD:-npx --yes @wordpress/env}"

echo "Using wp-env directory: $WP_ENV_DIR"
echo "Using wp-env command: $WP_ENV_CMD"

(
	cd "$WP_ENV_DIR"

	if ! bash -lc "$WP_ENV_CMD run cli wp option get home >/dev/null 2>&1"; then
		echo "wp-env is not running; starting it now..."
		bash -lc "$WP_ENV_CMD start"
	fi

	if ! bash -lc "$WP_ENV_CMD run cli wp eval 'exit( class_exists( \"Tribe__Events__Main\" ) ? 0 : 1 );'" >/dev/null 2>&1; then
		echo "Installing The Events Calendar for ticket integration checks..."
		bash -lc "$WP_ENV_CMD run cli wp plugin install the-events-calendar --activate"
	fi

	if ! bash -lc "$WP_ENV_CMD run cli wp plugin is-installed woocommerce >/dev/null 2>&1"; then
		echo "Installing WooCommerce for ticket integration checks..."
		bash -lc "$WP_ENV_CMD run cli wp plugin install woocommerce --activate"
	elif ! bash -lc "$WP_ENV_CMD run cli wp plugin is-active woocommerce >/dev/null 2>&1"; then
		echo "Activating WooCommerce for ticket integration checks..."
		bash -lc "$WP_ENV_CMD run cli wp plugin activate woocommerce"
	fi

	bash -lc "$WP_ENV_CMD run cli wp plugin activate oras-tickets >/dev/null 2>&1 || true"
	bash -lc "$WP_ENV_CMD run cli sh -lc 'cat > /tmp/oras-phase5-integration-checks.php && wp eval-file /tmp/oras-phase5-integration-checks.php'" < "$CHECK_FILE"
)
