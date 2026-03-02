#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CHECK_FILE="$ROOT_DIR/scripts/phase3-reporting-checks.php"
PLUGIN_TOOLS_DIR="$ROOT_DIR/oras-tickets/tools"

if [[ ! -f "$CHECK_FILE" ]]; then
	echo "Phase 3 reporting check script is missing: $CHECK_FILE" >&2
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
		echo "Installing The Events Calendar for reporting integration checks..."
		bash -lc "$WP_ENV_CMD run cli wp plugin install the-events-calendar --activate"
	fi

	if ! bash -lc "$WP_ENV_CMD run cli wp plugin is-installed woocommerce >/dev/null 2>&1"; then
		echo "Installing WooCommerce for reporting integration checks..."
		bash -lc "$WP_ENV_CMD run cli wp plugin install woocommerce --activate"
	elif ! bash -lc "$WP_ENV_CMD run cli wp plugin is-active woocommerce >/dev/null 2>&1"; then
		echo "Activating WooCommerce for reporting integration checks..."
		bash -lc "$WP_ENV_CMD run cli wp plugin activate woocommerce"
	fi

	bash -lc "$WP_ENV_CMD run cli wp plugin activate oras-tickets >/dev/null 2>&1 || true"

	base_name="phase3-reporting-checks.php"
	runtime_file="$PLUGIN_TOOLS_DIR/$base_name"
	cp "$CHECK_FILE" "$runtime_file"
	bash -lc "$WP_ENV_CMD run cli wp eval-file /var/www/html/wp-content/plugins/oras-tickets/tools/$base_name"
	rm -f "$runtime_file"
)
