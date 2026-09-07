#!/bin/bash -p
set -euo pipefail

early_fail() {
	printf 'Refusing QBO integration checks: %s\n' "$1" >&2
	exit 1
}

if [[ "${BASH_SOURCE[0]}" != "$0" ]]; then
	early_fail 'the safety runner cannot be sourced.'
fi

# Reject startup/configuration vectors before invoking any external program.
# PATH and IFS are normalized because both are ordinarily inherited by every
# shell; optional loader/runtime variables are rejected outright.
if [[ -v BASH_ENV ]]; then
	early_fail 'BASH_ENV overrides are not permitted.'
fi
if [[ -v ENV ]]; then
	early_fail 'ENV overrides are not permitted.'
fi
if [[ -v CDPATH ]]; then
	early_fail 'CDPATH overrides are not permitted.'
fi
while IFS= read -r ambient_name; do
	case "$ambient_name" in
		LD_*|PHP_*|COMPOSER_*|WP_CLI_*|DOCKER_*|NODE_*|NPM_CONFIG_*|npm_config_*)
			early_fail "$ambient_name overrides are not permitted."
			;;
	esac
done < <(compgen -e)

IFS=$' \t\n'
export PATH='/home/rocco/.nvm/versions/node/v22.22.0/bin:/usr/local/bin:/usr/bin:/bin'

readonly REALPATH_BIN='/usr/bin/realpath'
readonly SHA256_BIN='/usr/bin/sha256sum'
readonly AWK_BIN='/usr/bin/awk'
readonly MKTEMP_BIN='/usr/bin/mktemp'
readonly CP_BIN='/usr/bin/cp'
readonly FIND_BIN='/usr/bin/find'
readonly RMDIR_BIN='/usr/bin/rmdir'
readonly PHP_BIN='/usr/bin/php'
readonly GREP_BIN='/usr/bin/grep'
readonly TAIL_BIN='/usr/bin/tail'
readonly WC_BIN='/usr/bin/wc'
readonly BASENAME_BIN='/usr/bin/basename'
readonly ENV_BIN='/usr/bin/env'

EXPECTED_ROOT='/home/rocco/projects/ORAS-Tickets'
EXPECTED_CONFIG_SHA256='d3efa1ac7c2d0124c7cd1ba1912c36ff7db9758edeff838327eeb5026dccb393'
EXPECTED_DOCKER_CONFIG_SHA256='ca3d163bab055381827226140568f3bef7eaac187cebd76878e0b63e9e442356'
EXPECTED_HTTP_BLOCK_SHA256='ecf2c90829b4ce2bf10bc06aaf97158ce95ede66c213bcd793596ee2ee1b3eb5'
EXPECTED_PROJECT='3c882350e7b5f1407215adcc14f7ee5a'
EXPECTED_COMPOSE_SHA256='d2af945c6e3d866100670f6788204ff275eb0eaf0f433e12d6de27f0f3143d18'
EXPECTED_DATABASE='tests-wordpress'
EXPECTED_DATABASE_HOST='tests-mysql'
EXPECTED_HOME='http://localhost:8895'
EXPECTED_WP_ENV_VERSION='11.3.0'
EXPECTED_WP_ENV_REALPATH='/home/rocco/.nvm/versions/node/v22.22.0/lib/node_modules/@wordpress/env/bin/wp-env'
EXPECTED_WP_ENV_SHA256='c3ad55a8eb7c006a58b5133cea146e8b5afc9c755dfb09dd2d631e2bc2264ef3'
DISPOSABLE_MARKER_OPTION='oras_qbo_disposable_fixture_id'
DISPOSABLE_MARKER_VALUE='oras-tickets-qbo-tests-v1-3c882350e7b5f140'

RUNNER_PATH="$($REALPATH_BIN "${BASH_SOURCE[0]}")"
ROOT_DIR="$($REALPATH_BIN "${RUNNER_PATH%/*}/..")"
CONFIG_FILE="$ROOT_DIR/.wp-env.json"
DOCKER_CONFIG_SOURCE_DIR="$ROOT_DIR/.wp-env/docker"
DOCKER_CONFIG_FILE="$DOCKER_CONFIG_SOURCE_DIR/config.json"
DOCKER_CONFIG_DIR=''
HTTP_BLOCK_FILE="$ROOT_DIR/scripts/fixtures/oras-qbo-http-block.php"
WP_ENV_BIN='/home/rocco/.nvm/versions/node/v22.22.0/bin/wp-env'
WP_ENV_HOME_DIR="/home/rocco/wp-env/$EXPECTED_PROJECT"
COMPOSE_FILE_PATH="$WP_ENV_HOME_DIR/docker-compose.yml"
DOCKER_BIN='/usr/bin/docker'
TEST_SERVICE='tests-cli'
HPOS_CHECK_FILE="$ROOT_DIR/scripts/qbo-hpos-source-claim-tests.php"
HPOS_ENABLED_STATE=''
HPOS_COMPATIBILITY_STATE=''
HPOS_STATE_CAPTURED=0
RUNNER_TEST_CLEANUP_ACTIVE=0

HOST_CHECK_FILES=(
	"$ROOT_DIR/scripts/qbo-fixture-registry-tests.php"
)

CHECK_FILES=(
	"$ROOT_DIR/scripts/qbo-sync-safeguard-tests.php"
	"$ROOT_DIR/scripts/qbo-split-calculator-tests.php"
	"$ROOT_DIR/scripts/qbo-reclass-safety-tests.php"
	"$ROOT_DIR/scripts/stripe-metadata-tests.php"
	"$ROOT_DIR/scripts/qbo-safety-controls-tests.php"
	"$ROOT_DIR/scripts/qbo-reconciliation-tests.php"
	"$ROOT_DIR/scripts/qbo-api-error-matrix-tests.php"
	"$ROOT_DIR/scripts/qbo-oauth-callback-tests.php"
)

fail() {
	printf 'Refusing QBO integration checks: %s\n' "$1" >&2
	exit 1
}

reject_environment_override() {
	local variable_name="$1"
	if [[ -v "$variable_name" ]]; then
		fail "$variable_name overrides are not permitted."
	fi
}

sha256_of() {
	"$SHA256_BIN" "$1" | "$AWK_BIN" '{print $1}'
}

prepare_runtime_docker_config() {
	[[ -z "$DOCKER_CONFIG_DIR" ]] || fail 'runtime Docker configuration was already prepared.'
	DOCKER_CONFIG_DIR="$("$MKTEMP_BIN" -d /tmp/oras-qbo-docker-config.XXXXXX)" \
		|| fail 'could not create an isolated runtime Docker configuration.'
	"$CP_BIN" "$DOCKER_CONFIG_FILE" "$DOCKER_CONFIG_DIR/config.json" \
		|| fail 'could not prepare the isolated runtime Docker configuration.'
}

cleanup_runtime_docker_config() {
	if [[ -z "$DOCKER_CONFIG_DIR" ]]; then
		return
	fi
	if [[ "$DOCKER_CONFIG_DIR" != /tmp/oras-qbo-docker-config.* ]]; then
		echo 'Refusing to clean an unexpected runtime Docker configuration path.' >&2
		DOCKER_CONFIG_DIR=''
		return
	fi
	if [[ -d "$DOCKER_CONFIG_DIR" ]]; then
		"$FIND_BIN" "$DOCKER_CONFIG_DIR" -depth -mindepth 1 -delete
		"$RMDIR_BIN" "$DOCKER_CONFIG_DIR"
	fi
	DOCKER_CONFIG_DIR=''
}

wp_env() {
	[[ -n "$DOCKER_CONFIG_DIR" ]] || fail 'isolated runtime Docker configuration is unavailable.'
	(
		cd "$ROOT_DIR"
		"$ENV_BIN" -i \
			HOME='/home/rocco' \
			PATH='/home/rocco/.nvm/versions/node/v22.22.0/bin:/usr/local/bin:/usr/bin:/bin' \
			DOCKER_CONFIG="$DOCKER_CONFIG_DIR" \
			COMPOSE_BAKE=false \
			"$WP_ENV_BIN" "$@"
	)
}

wp_env_version() {
	"$ENV_BIN" -i \
		HOME='/home/rocco' \
		PATH='/home/rocco/.nvm/versions/node/v22.22.0/bin:/usr/local/bin:/usr/bin:/bin' \
		"$WP_ENV_BIN" --version
}

container_id_for_service() {
	local service="$1"
	local ids
	ids="$($DOCKER_BIN ps \
		--filter "label=com.docker.compose.project=$EXPECTED_PROJECT" \
		--filter "label=com.docker.compose.service=$service" \
		--format '{{.ID}}')"
	if [[ -z "$ids" || "$ids" == *$'\n'* ]]; then
		fail "expected exactly one running $service container for project $EXPECTED_PROJECT."
	fi
	printf '%s' "$ids"
}

verify_container_identity() {
	local service="$1"
	local expected_name="$2"
	local container_id
	local inspect_json

	container_id="$(container_id_for_service "$service")"
	inspect_json="$($DOCKER_BIN inspect "$container_id")"

	"$PHP_BIN" -r '
		$data = json_decode($argv[1], true);
		if (!is_array($data) || count($data) !== 1) {
			fwrite(STDERR, "Invalid container inspection response.\n"); exit(1);
		}
		$container = $data[0];
		$labels = $container["Config"]["Labels"] ?? array();
		$expected = array(
			"com.docker.compose.project" => $argv[2],
			"com.docker.compose.service" => $argv[3],
			"com.docker.compose.project.config_files" => $argv[4],
			"com.docker.compose.project.working_dir" => $argv[5],
		);
		foreach ($expected as $key => $value) {
			if (($labels[$key] ?? "") !== $value) {
				fwrite(STDERR, "Unexpected container label: {$key}\n"); exit(1);
			}
		}
		if (($container["Name"] ?? "") !== "/" . $argv[6]) {
			fwrite(STDERR, "Unexpected container name.\n"); exit(1);
		}
		if (($container["State"]["Running"] ?? false) !== true) {
			fwrite(STDERR, "Expected container is not running.\n"); exit(1);
		}
	' "$inspect_json" "$EXPECTED_PROJECT" "$service" "$COMPOSE_FILE_PATH" "$WP_ENV_HOME_DIR" "$expected_name" \
		|| fail "container/project identity did not match the repository fixture."

	printf '%s' "$container_id"
}

verify_mounts_and_database() {
	local cli_container_id="$1"
	local database_container_id="$2"
	local cli_json
	local database_json

	cli_json="$($DOCKER_BIN inspect "$cli_container_id")"
	database_json="$($DOCKER_BIN inspect "$database_container_id")"

	"$PHP_BIN" -r '
		$container = json_decode($argv[1], true)[0] ?? array();
		$mounts = $container["Mounts"] ?? array();
		$required = array(
			$argv[2] => "/var/www/html/wp-content/plugins/oras-tickets",
			$argv[3] => "/var/www/html/wp-content/oras-qbo-tests",
			$argv[4] => "/var/www/html/wp-content/mu-plugins/oras-qbo-http-block.php",
		);
		$found = array();
		foreach ($mounts as $mount) {
			$source = $mount["Source"] ?? "";
			$destination = $mount["Destination"] ?? "";
			if (isset($required[$source]) && $required[$source] === $destination) {
				$found[$source] = true;
			}
			if (str_starts_with($source, "/home/rocco/projects/") && !isset($required[$source])) {
				fwrite(STDERR, "Unexpected project mount: {$source}\n"); exit(1);
			}
		}
		if (count($found) !== count($required)) {
			fwrite(STDERR, "Required repository mounts are missing.\n"); exit(1);
		}
		$environment = $container["Config"]["Env"] ?? array();
		if (!in_array("WORDPRESS_DB_NAME=" . $argv[5], $environment, true)
			|| !in_array("WORDPRESS_DB_HOST=" . $argv[6], $environment, true)) {
			fwrite(STDERR, "Unexpected WordPress database environment.\n"); exit(1);
		}
	' "$cli_json" "$ROOT_DIR/oras-tickets" "$ROOT_DIR/scripts" "$HTTP_BLOCK_FILE" "$EXPECTED_DATABASE" "$EXPECTED_DATABASE_HOST" \
		|| fail "mounted project identity or disposable database configuration did not match."

	"$PHP_BIN" -r '
		$container = json_decode($argv[1], true)[0] ?? array();
		$environment = $container["Config"]["Env"] ?? array();
		if (!in_array("MYSQL_DATABASE=" . $argv[2], $environment, true)) {
			fwrite(STDERR, "Unexpected database name.\n"); exit(1);
		}
		$expected_volume = $argv[3] . "_mysql-test";
		$found = false;
		foreach (($container["Mounts"] ?? array()) as $mount) {
			if (($mount["Name"] ?? "") === $expected_volume
				&& ($mount["Destination"] ?? "") === "/var/lib/mysql") {
				$found = true;
			}
		}
		if (!$found) {
			fwrite(STDERR, "Unexpected disposable database volume.\n"); exit(1);
		}
	' "$database_json" "$EXPECTED_DATABASE" "$EXPECTED_PROJECT" \
		|| fail "disposable database container/volume identity did not match."
}

extract_json_line() {
	"$GREP_BIN" -E '^\{.*\}$' | "$TAIL_BIN" -n 1
}

verify_wordpress_identity() {
	local identity
	identity="$(wp_env run "$TEST_SERVICE" wp eval '
		global $wpdb;
		echo wp_json_encode(
			array(
				"home" => get_option("home"),
				"siteurl" => get_option("siteurl"),
				"raw_home" => $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", "home")),
				"raw_siteurl" => $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", "siteurl")),
				"db_name" => DB_NAME,
				"db_host" => DB_HOST,
				"db_prefix" => $wpdb->prefix,
				"http_block" => defined("ORAS_QBO_HTTP_BLOCK_ACTIVE") && ORAS_QBO_HTTP_BLOCK_ACTIVE,
			)
		);
	' 2>/dev/null | extract_json_line)"

	"$PHP_BIN" -r '
		$identity = json_decode($argv[1], true);
		if (!is_array($identity)) {
			fwrite(STDERR, "Invalid WordPress identity response.\n"); exit(1);
		}
		foreach (array("home", "siteurl", "raw_home", "raw_siteurl") as $key) {
			$url = parse_url((string) ($identity[$key] ?? ""));
			if (($identity[$key] ?? "") !== $argv[2]
				|| ($url["scheme"] ?? "") !== "http"
				|| !in_array(strtolower((string) ($url["host"] ?? "")), array("localhost", "127.0.0.1", "::1"), true)) {
				fwrite(STDERR, "Refusing non-loopback or redirected WordPress URL.\n"); exit(1);
			}
		}
		if (($identity["db_name"] ?? "") !== $argv[3]
			|| ($identity["db_host"] ?? "") !== $argv[4]
			|| ($identity["db_prefix"] ?? "") !== "wp_") {
			fwrite(STDERR, "Refusing alternate WordPress database identity.\n"); exit(1);
		}
		if (($identity["http_block"] ?? false) !== true) {
			fwrite(STDERR, "Required Intuit HTTP blocker is not active.\n"); exit(1);
		}
	' "$identity" "$EXPECTED_HOME" "$EXPECTED_DATABASE" "$EXPECTED_DATABASE_HOST" \
		|| fail "WordPress disposable-fixture identity did not match."
}

ensure_disposable_database_marker() {
	local marker_state
	marker_state="$(wp_env run "$TEST_SERVICE" wp eval '
		$missing = new stdClass();
		$value = get_option( "oras_qbo_disposable_fixture_id", $missing );
		echo wp_json_encode(
			array(
				"exists" => $value !== $missing,
				"value" => $value !== $missing ? $value : "",
			)
		);
	' 2>/dev/null | extract_json_line)"

	if "$PHP_BIN" -r '
		$state = json_decode($argv[1], true);
		if (!is_array($state)) { exit(2); }
		if (!empty($state["exists"]) && ($state["value"] ?? "") !== $argv[2]) { exit(3); }
		exit(!empty($state["exists"]) ? 0 : 1);
	' "$marker_state" "$DISPOSABLE_MARKER_VALUE"; then
		:
	else
		local marker_status="$?"
		case "$marker_status" in
			1)
				wp_env run "$TEST_SERVICE" wp eval '
					if ( ! add_option( "oras_qbo_disposable_fixture_id", "oras-tickets-qbo-tests-v1-3c882350e7b5f140", "", false ) ) {
						throw new RuntimeException( "Could not initialize disposable database marker." );
					}
				' >/dev/null || fail "could not initialize the repository-controlled disposable database marker."
				;;
			*)
				fail "missing, copied, or forged disposable database marker was detected."
				;;
		esac
	fi

	marker_state="$(wp_env run "$TEST_SERVICE" wp option get "$DISPOSABLE_MARKER_OPTION" 2>/dev/null | "$GREP_BIN" -F "$DISPOSABLE_MARKER_VALUE" | "$TAIL_BIN" -n 1)"
	[[ "$marker_state" == "$DISPOSABLE_MARKER_VALUE" ]] \
		|| fail "repository-controlled disposable database marker could not be verified."
}

verify_quickbooks_safety() {
	local identity
	local http_block_result
	identity="$(wp_env run "$TEST_SERVICE" wp eval '
		$settings = get_option("oras_tickets_settings_v1", array());
		$qbo = is_array($settings) && isset($settings["quickbooks"]) && is_array($settings["quickbooks"])
			? $settings["quickbooks"] : array();
		echo wp_json_encode(
			array(
				"enabled" => !empty($qbo["enabled"]),
				"dry_run" => !array_key_exists("dry_run_mode", $qbo) || !empty($qbo["dry_run_mode"]),
				"sandbox" => !array_key_exists("sandbox", $qbo) || !empty($qbo["sandbox"]),
				"client_id" => !empty($qbo["client_id"]),
				"client_secret" => !empty($qbo["client_secret"]),
				"realm" => !empty($qbo["realm_id"]),
				"access" => !empty($qbo["access_token"]),
				"refresh" => !empty($qbo["refresh_token"]),
			)
		);
	' 2>/dev/null | extract_json_line)"

	"$PHP_BIN" -r '
		$qbo = json_decode($argv[1], true);
		if (!is_array($qbo)
			|| !empty($qbo["enabled"])
			|| empty($qbo["dry_run"])
			|| empty($qbo["sandbox"])
			|| !empty($qbo["client_id"])
			|| !empty($qbo["client_secret"])
			|| !empty($qbo["realm"])
			|| !empty($qbo["access"])
			|| !empty($qbo["refresh"])) {
			fwrite(STDERR, "Unsafe QuickBooks settings found.\n"); exit(1);
		}
	' "$identity" || fail "enabled, live, identified, credentialed, or tokenized QuickBooks settings were found."

	http_block_result="$(wp_env run "$TEST_SERVICE" wp eval '
		$result = wp_remote_request("https://quickbooks.api.intuit.com/v3/company/blocked", array("timeout" => 1));
		echo is_wp_error($result) ? $result->get_error_code() : "network-fallthrough";
	' 2>/dev/null | "$GREP_BIN" -E '^(oras_qbo_disposable_http_blocked|network-fallthrough)$' | "$TAIL_BIN" -n 1)"
	if [[ "$http_block_result" != 'oras_qbo_disposable_http_blocked' ]]; then
		fail "HTTP interception fell through instead of blocking unmocked Intuit traffic."
	fi
}

reset_disposable_quickbooks_controls() {
	wp_env run "$TEST_SERVICE" wp eval '
		$settings = get_option("oras_tickets_settings_v1", array());
		if (!is_array($settings)) {
			$settings = array();
		}
		$qbo = isset($settings["quickbooks"]) && is_array($settings["quickbooks"])
			? $settings["quickbooks"] : array();
		$qbo = array_merge(
			$qbo,
			array(
				"enabled" => false,
				"dry_run_mode" => true,
				"sandbox" => true,
				"client_id" => "",
				"client_secret" => "",
				"realm_id" => "",
				"access_token" => "",
				"refresh_token" => "",
				"token_expires_at" => "",
				"refresh_token_expires_at" => "",
			)
		);
		$settings["quickbooks"] = $qbo;
		update_option("oras_tickets_settings_v1", $settings, false);
	' >/dev/null 2>&1 || true
}

capture_hpos_option_state() {
	local option_name="$1"
	local state

	[[ "$option_name" =~ ^[a-z_]+$ ]] || fail "invalid HPOS option name."
	state="$(wp_env run "$TEST_SERVICE" wp eval "
		\$missing = new stdClass();
		\$value = get_option( '$option_name', \$missing );
		echo 'ORAS_STATE:' . base64_encode(
			serialize(
				array(
					'exists' => \$value !== \$missing,
					'value' => \$value !== \$missing ? \$value : null,
				)
			)
		);
	" 2>/dev/null | "$GREP_BIN" -E '^ORAS_STATE:[A-Za-z0-9+/=]+$' | "$TAIL_BIN" -n 1)"

	[[ -n "$state" ]] || fail "could not capture original HPOS option: $option_name."
	printf '%s' "${state#ORAS_STATE:}"
}

restore_hpos_option_state() {
	local option_name="$1"
	local encoded_state="$2"

	[[ "$option_name" =~ ^[a-z_]+$ && "$encoded_state" =~ ^[A-Za-z0-9+/=]+$ ]] || return 1
	wp_env run "$TEST_SERVICE" wp eval "
		\$state = unserialize( base64_decode( '$encoded_state', true ), array( 'allowed_classes' => false ) );
		if ( ! is_array( \$state ) || ! array_key_exists( 'exists', \$state ) ) {
			throw new RuntimeException( 'Invalid saved HPOS option state.' );
		}
		if ( \$state['exists'] ) {
			update_option( '$option_name', \$state['value'] );
		} else {
			delete_option( '$option_name' );
		}
	" >/dev/null
}

restore_hpos_settings() {
	if [[ "$HPOS_STATE_CAPTURED" -ne 1 ]]; then
		return
	fi

	wp_env run "$TEST_SERVICE" wp wc hpos sync --batch-size=500 >/dev/null 2>&1 || true
	restore_hpos_option_state 'woocommerce_custom_orders_table_enabled' "$HPOS_ENABLED_STATE" || true
	restore_hpos_option_state 'woocommerce_custom_orders_table_data_sync_enabled' "$HPOS_COMPATIBILITY_STATE" || true
	HPOS_STATE_CAPTURED=0
}

cleanup_runner() {
	if [[ "$RUNNER_TEST_CLEANUP_ACTIVE" -eq 1 ]]; then
		restore_hpos_settings
		reset_disposable_quickbooks_controls
	fi
	cleanup_runtime_docker_config
}

configure_hpos_for_test() {
	HPOS_ENABLED_STATE="$(capture_hpos_option_state 'woocommerce_custom_orders_table_enabled')"
	HPOS_COMPATIBILITY_STATE="$(capture_hpos_option_state 'woocommerce_custom_orders_table_data_sync_enabled')"
	HPOS_STATE_CAPTURED=1

	wp_env run "$TEST_SERVICE" wp wc hpos sync --batch-size=500 >/dev/null
	wp_env run "$TEST_SERVICE" wp eval '
		update_option( "woocommerce_custom_orders_table_enabled", "yes" );
		update_option( "woocommerce_custom_orders_table_data_sync_enabled", "no" );
	' >/dev/null
}

main() {
	local variable_name
	local cli_container_id
	local database_container_id

	if [[ "$ROOT_DIR" != "$EXPECTED_ROOT" ]]; then
		fail "repository realpath must be exactly $EXPECTED_ROOT."
	fi
	if [[ -v ORAS_QBO_DISPOSABLE_TEST_SENTINEL ]]; then
		fail 'legacy disposable sentinel is not trusted.'
	fi

	for variable_name in \
		ORAS_WP_ENV_DIR ORAS_WP_ENV_CMD \
		WP_ENV_HOME WP_ENV_PORT WP_ENV_MYSQL_PORT WP_ENV_TESTS_PORT WP_ENV_TESTS_MYSQL_PORT \
		WP_ENV_PHPMYADMIN_PORT WP_ENV_TESTS_PHPMYADMIN_PORT WP_ENV_CORE WP_ENV_PHP_VERSION \
		WP_ENV_LIFECYCLE_SCRIPT_AFTER_START WP_ENV_LIFECYCLE_SCRIPT_AFTER_CLEAN \
		WP_ENV_LIFECYCLE_SCRIPT_AFTER_RESET WP_ENV_LIFECYCLE_SCRIPT_AFTER_CLEANUP \
		WP_ENV_LIFECYCLE_SCRIPT_AFTER_DESTROY COMPOSE_FILE COMPOSE_PROJECT_NAME \
		DOCKER_HOST DOCKER_CONTEXT DOCKER_CONFIG; do
		reject_environment_override "$variable_name"
	done

	[[ -f "$CONFIG_FILE" ]] || fail "repository-controlled .wp-env.json is missing."
	[[ "$(sha256_of "$CONFIG_FILE")" == "$EXPECTED_CONFIG_SHA256" ]] \
		|| fail "repository-controlled .wp-env.json does not match its approved hash."
	[[ -f "$DOCKER_CONFIG_FILE" && "$(sha256_of "$DOCKER_CONFIG_FILE")" == "$EXPECTED_DOCKER_CONFIG_SHA256" ]] \
		|| fail "repository-controlled Docker configuration does not match its approved hash."
	[[ -f "$HTTP_BLOCK_FILE" && "$(sha256_of "$HTTP_BLOCK_FILE")" == "$EXPECTED_HTTP_BLOCK_SHA256" ]] \
		|| fail "repository-controlled Intuit HTTP blocker does not match its approved hash."
	[[ -x "$WP_ENV_BIN" && "$("$REALPATH_BIN" "$WP_ENV_BIN")" == "$EXPECTED_WP_ENV_REALPATH" ]] \
		|| fail "wp-env executable identity does not match."
	[[ "$(sha256_of "$EXPECTED_WP_ENV_REALPATH")" == "$EXPECTED_WP_ENV_SHA256" ]] \
		|| fail "wp-env executable hash does not match."
	[[ "$(wp_env_version)" == "$EXPECTED_WP_ENV_VERSION" ]] \
		|| fail "wp-env version does not match $EXPECTED_WP_ENV_VERSION."
	[[ -x "$DOCKER_BIN" ]] || fail "fixed Docker executable is unavailable."
	[[ "$($DOCKER_BIN context show)" == 'default' ]] || fail "Docker context must be default."
	[[ "$($DOCKER_BIN context inspect default --format '{{.Endpoints.docker.Host}}')" == 'unix:///var/run/docker.sock' ]] \
		|| fail "Docker context must use the local Unix socket."

	trap cleanup_runner EXIT
	prepare_runtime_docker_config

	for check_file in "${CHECK_FILES[@]}"; do
		[[ -f "$check_file" ]] || fail "QBO check script is missing: $check_file"
	done
	for check_file in "${HOST_CHECK_FILES[@]}"; do
		[[ -f "$check_file" ]] || fail "QBO host check script is missing: $check_file"
	done
	[[ -f "$HPOS_CHECK_FILE" ]] || fail "QBO HPOS check script is missing: $HPOS_CHECK_FILE"

	if [[ -f "$COMPOSE_FILE_PATH" && "$(sha256_of "$COMPOSE_FILE_PATH")" != "$EXPECTED_COMPOSE_SHA256" ]]; then
		fail "generated Compose configuration does not match its pinned repository identity."
	fi

	if [[ "$($DOCKER_BIN ps \
		--filter "label=com.docker.compose.project=$EXPECTED_PROJECT" \
		--filter 'label=com.docker.compose.service=tests-cli' \
		--format '{{.ID}}' | "$WC_BIN" -l)" -ne 1 ]]; then
		echo 'Starting the pinned repository-local disposable wp-env definition...'
		wp_env start
	fi

	[[ -f "$COMPOSE_FILE_PATH" && "$(sha256_of "$COMPOSE_FILE_PATH")" == "$EXPECTED_COMPOSE_SHA256" ]] \
		|| fail "generated Compose configuration does not match its pinned repository identity."

	cli_container_id="$(verify_container_identity 'tests-cli' "$EXPECTED_PROJECT-tests-cli-1")"
	verify_container_identity 'tests-wordpress' "$EXPECTED_PROJECT-tests-wordpress-1" >/dev/null
	database_container_id="$(verify_container_identity 'tests-mysql' "$EXPECTED_PROJECT-tests-mysql-1")"
	verify_mounts_and_database "$cli_container_id" "$database_container_id"
	verify_wordpress_identity
	ensure_disposable_database_marker
	verify_quickbooks_safety

	echo "Verified disposable wp-env: $EXPECTED_PROJECT, $EXPECTED_DATABASE@$EXPECTED_DATABASE_HOST, loopback-only, sandbox/dry-run, no credentials, Intuit HTTP fail-closed."

	if [[ "${1:-}" == '--verify-environment-only' ]]; then
		exit 0
	fi
	if [[ $# -gt 0 ]]; then
		fail "unknown runner argument: $1"
	fi

	RUNNER_TEST_CLEANUP_ACTIVE=1
	for check_file in "${HOST_CHECK_FILES[@]}"; do
		echo "Running $("$BASENAME_BIN" "$check_file")"
		"$PHP_BIN" "$check_file"
	done

	if ! wp_env run "$TEST_SERVICE" wp eval 'exit(class_exists("Tribe__Events__Main") ? 0 : 1);' >/dev/null 2>&1; then
		echo 'Installing The Events Calendar in the disposable test database...'
		wp_env run "$TEST_SERVICE" wp plugin install the-events-calendar --activate
	fi
	if ! wp_env run "$TEST_SERVICE" wp plugin is-installed woocommerce >/dev/null 2>&1; then
		echo 'Installing WooCommerce in the disposable test database...'
		wp_env run "$TEST_SERVICE" wp plugin install woocommerce --activate
	elif ! wp_env run "$TEST_SERVICE" wp plugin is-active woocommerce >/dev/null 2>&1; then
		echo 'Activating WooCommerce in the disposable test database...'
		wp_env run "$TEST_SERVICE" wp plugin activate woocommerce
	fi
	wp_env run "$TEST_SERVICE" wp plugin activate oras-tickets >/dev/null

	for check_file in "${CHECK_FILES[@]}"; do
		echo "Running $("$BASENAME_BIN" "$check_file")"
		wp_env run "$TEST_SERVICE" wp eval-file "/var/www/html/wp-content/oras-qbo-tests/$("$BASENAME_BIN" "$check_file")"
	done

	configure_hpos_for_test
	echo "Running $("$BASENAME_BIN" "$HPOS_CHECK_FILE")"
	wp_env run "$TEST_SERVICE" wp eval-file "/var/www/html/wp-content/oras-qbo-tests/$("$BASENAME_BIN" "$HPOS_CHECK_FILE")"
	restore_hpos_settings

	reset_disposable_quickbooks_controls
	verify_quickbooks_safety
	RUNNER_TEST_CLEANUP_ACTIVE=0
}

main "$@"
