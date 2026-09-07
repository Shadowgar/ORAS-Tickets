#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
RUNNER="$ROOT_DIR/scripts/run-qbo-integration-checks.sh"
WP_ENV_BIN='/home/rocco/.nvm/versions/node/v22.22.0/bin/wp-env'
EXPECTED_HOME='http://localhost:8895'
EXPECTED_MARKER='oras-tickets-qbo-tests-v1-3c882350e7b5f140'
QBO_OPTION_STATE=''
FIXTURE_STATE_CAPTURED=0
DOCKER_CONFIG_DIR=''
readonly ENV_BIN='/usr/bin/env'

wp_env() {
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

capture_option_state() {
	local option_name="$1"
	[[ "$option_name" =~ ^[a-z0-9_]+$ ]] || return 1
	wp_env run tests-cli wp eval "
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
	" 2>/dev/null | grep -E '^ORAS_STATE:[A-Za-z0-9+/=]+$' | tail -n 1 | sed 's/^ORAS_STATE://'
}

restore_option_state() {
	local option_name="$1"
	local encoded_state="$2"
	[[ "$option_name" =~ ^[a-z0-9_]+$ && "$encoded_state" =~ ^[A-Za-z0-9+/=]+$ ]] || return 1
	wp_env run tests-cli wp eval "
		\$state = unserialize( base64_decode( '$encoded_state', true ), array( 'allowed_classes' => false ) );
		if ( ! is_array( \$state ) || ! array_key_exists( 'exists', \$state ) ) {
			throw new RuntimeException( 'Invalid saved option state.' );
		}
		if ( \$state['exists'] ) {
			update_option( '$option_name', \$state['value'] );
		} else {
			delete_option( '$option_name' );
		}
	" >/dev/null 2>&1
}

cleanup() {
	if [[ "$FIXTURE_STATE_CAPTURED" -eq 1 ]]; then
		wp_env run tests-cli wp eval '
			global $wpdb;
			$wpdb->update( $wpdb->options, array( "option_value" => "http://localhost:8895" ), array( "option_name" => "home" ) );
			$wpdb->update( $wpdb->options, array( "option_value" => "http://localhost:8895" ), array( "option_name" => "siteurl" ) );
		' >/dev/null 2>&1 || true
		wp_env run tests-cli wp option update oras_qbo_disposable_fixture_id "$EXPECTED_MARKER" >/dev/null 2>&1 || true
		restore_option_state 'oras_tickets_settings_v1' "$QBO_OPTION_STATE" || true
	fi
	rm -rf -- "$alternate_root"
}

assert_source_contains() {
	local expected="$1"
	if ! grep -Fq -- "$expected" "$RUNNER"; then
		echo "Runner is missing required immutable guard source: $expected" >&2
		exit 1
	fi
}

assert_rejected_with() {
	local expected="$1"
	shift
	local output
	if output="$(env "$@" "$RUNNER" --verify-environment-only 2>&1)"; then
		echo "Runner guard accepted an unsafe override: $expected" >&2
		exit 1
	fi
	if [[ "$output" != *"$expected"* ]]; then
		echo "Runner rejected an unsafe override for the wrong reason; expected: $expected" >&2
		echo "$output" >&2
		exit 1
	fi
}

assert_node_startup_rejected_without_execution() {
	local expected="$1"
	local marker="$2"
	shift 2
	local output
	local status
	set +e
	output="$(env "$@" "$RUNNER" --verify-environment-only 2>&1)"
	status=$?
	set -e
	if [[ -e "$marker" ]]; then
		echo "Runner evaluated a Node startup override before rejecting it: $expected" >&2
		exit 1
	fi
	if [[ "$status" -eq 0 ]]; then
		echo "Runner guard accepted an unsafe override: $expected" >&2
		exit 1
	fi
	if [[ "$output" != *"$expected"* ]]; then
		echo "Runner rejected an unsafe override for the wrong reason; expected: $expected" >&2
		echo "$output" >&2
		exit 1
	fi
}

assert_fixture_rejected_with() {
	local expected="$1"
	local output
	if output="$($RUNNER --verify-environment-only 2>&1)"; then
		echo "Runner guard accepted an unsafe disposable-fixture mutation: $expected" >&2
		exit 1
	fi
	if [[ "$output" != *"$expected"* ]]; then
		echo "Runner rejected a disposable-fixture mutation for the wrong reason; expected: $expected" >&2
		echo "$output" >&2
		exit 1
	fi
}

assert_repository_docker_config_clean() {
	local unexpected
	unexpected="$(find "$ROOT_DIR/.wp-env/docker" -mindepth 1 ! -path "$ROOT_DIR/.wp-env/docker/config.json" -print)"
	if [[ -n "$unexpected" ]]; then
		echo 'Runner left generated Docker configuration artifacts in the repository:' >&2
		echo "$unexpected" >&2
		exit 1
	fi
}

for required_guard in \
	'EXPECTED_ROOT=' \
	'EXPECTED_CONFIG_SHA256=' \
	'EXPECTED_PROJECT=' \
	'EXPECTED_DATABASE=' \
	'EXPECTED_DOCKER_CONFIG_SHA256=' \
	'DISPOSABLE_MARKER_OPTION=' \
	'DISPOSABLE_MARKER_VALUE=' \
	'com.docker.compose.project.config_files' \
	'com.docker.compose.project.working_dir' \
	'/var/www/html/wp-content/plugins/oras-tickets' \
	'ORAS_QBO_HTTP_BLOCK_ACTIVE' \
	'wp_remote_request' \
	'#!/bin/bash -p' \
	"readonly REALPATH_BIN='/usr/bin/realpath'" \
	'BASH_ENV overrides are not permitted' \
	"export PATH='/home/rocco/.nvm/versions/node/v22.22.0/bin:/usr/local/bin:/usr/bin:/bin'"; do
	assert_source_contains "$required_guard"
done

alternate_root="$(mktemp -d)"
trap cleanup EXIT
cp "$ROOT_DIR/.wp-env.json" "$alternate_root/.wp-env.json"
DOCKER_CONFIG_DIR="$alternate_root/docker-config"
mkdir "$DOCKER_CONFIG_DIR"
cp "$ROOT_DIR/.wp-env/docker/config.json" "$DOCKER_CONFIG_DIR/config.json"

assert_rejected_with 'ORAS_WP_ENV_DIR overrides are not permitted' ORAS_WP_ENV_DIR="$alternate_root"
assert_rejected_with 'ORAS_WP_ENV_CMD overrides are not permitted' ORAS_WP_ENV_CMD='wp --url=https://production.example.invalid'
assert_rejected_with 'COMPOSE_FILE overrides are not permitted' COMPOSE_FILE="$alternate_root/forged-compose.yml"
assert_rejected_with 'COMPOSE_PROJECT_NAME overrides are not permitted' COMPOSE_PROJECT_NAME='forged-production'
assert_rejected_with 'WP_ENV_HOME overrides are not permitted' WP_ENV_HOME="$alternate_root"
assert_rejected_with 'WP_ENV_TESTS_PORT overrides are not permitted' WP_ENV_TESTS_PORT='443'
assert_rejected_with 'DOCKER_HOST overrides are not permitted' DOCKER_HOST='tcp://production.example.invalid:2375'
assert_rejected_with 'DOCKER_CONFIG overrides are not permitted' DOCKER_CONFIG="$alternate_root"
assert_rejected_with 'DOCKER_TLS_VERIFY overrides are not permitted' DOCKER_TLS_VERIFY='1'
assert_rejected_with 'BASH_ENV overrides are not permitted' BASH_ENV="$alternate_root/bash-env"
assert_rejected_with 'ENV overrides are not permitted' ENV="$alternate_root/sh-env"
assert_rejected_with 'CDPATH overrides are not permitted' CDPATH="$alternate_root"
assert_rejected_with 'LD_LIBRARY_PATH overrides are not permitted' LD_LIBRARY_PATH="$alternate_root"
assert_rejected_with 'PHP_INI_SCAN_DIR overrides are not permitted' PHP_INI_SCAN_DIR="$alternate_root"
assert_rejected_with 'COMPOSER_HOME overrides are not permitted' COMPOSER_HOME="$alternate_root"
assert_rejected_with 'WP_CLI_CONFIG_PATH overrides are not permitted' WP_CLI_CONFIG_PATH="$alternate_root/wp-cli.yml"
assert_rejected_with 'legacy disposable sentinel is not trusted' ORAS_QBO_DISPOSABLE_TEST_SENTINEL='ORAS_QBO_DISPOSABLE_LOCAL_ONLY'

# Node startup overrides must be rejected before the pinned wp-env executable
# can evaluate them. A nonzero runner exit is insufficient if this marker is
# created first.
node_startup_marker="$alternate_root/node-startup-executed"
node_startup_payload="$alternate_root/node-startup-payload.cjs"
printf "require('fs').writeFileSync('%s', 'executed');\n" "$node_startup_marker" > "$node_startup_payload"
assert_node_startup_rejected_without_execution \
	'NODE_OPTIONS overrides are not permitted' \
	"$node_startup_marker" \
	NODE_OPTIONS="--require=$node_startup_payload"
assert_rejected_with 'NODE_PATH overrides are not permitted' NODE_PATH="$alternate_root"
assert_rejected_with 'NPM_CONFIG_USERCONFIG overrides are not permitted' NPM_CONFIG_USERCONFIG="$alternate_root/npmrc"
assert_rejected_with 'npm_config_userconfig overrides are not permitted' npm_config_userconfig="$alternate_root/npmrc"

if sourced_output="$(/bin/bash -c 'source "$1"' bash "$RUNNER" 2>&1)"; then
	echo 'Runner unexpectedly allowed its guards to be sourced and overridden.' >&2
	exit 1
fi
if [[ "$sourced_output" != *'the safety runner cannot be sourced'* ]]; then
	echo 'Runner rejected sourcing for the wrong reason.' >&2
	echo "$sourced_output" >&2
	exit 1
fi

# A hostile PATH entry must never supply any executable used by the runner.
fake_path_dir="$alternate_root/fake-path"
mkdir "$fake_path_dir"
fake_path_marker="$alternate_root/fake-path-executed"
for command_name in realpath sha256sum awk mktemp cp find rmdir php grep tail wc basename docker; do
	printf '#!/bin/sh\nprintf touched > %s\nexit 99\n' "$fake_path_marker" > "$fake_path_dir/$command_name"
	chmod +x "$fake_path_dir/$command_name"
done
PATH="$fake_path_dir:/usr/bin:/bin" "$RUNNER" --verify-environment-only >/dev/null
if [[ -e "$fake_path_marker" ]]; then
	echo 'Runner executed a hostile PATH binary.' >&2
	exit 1
fi

# The immutable fixture must also reject unsafe state inside otherwise correctly
# labelled containers. Every mutation is confined to the disposable tests DB
# and restored by the EXIT trap.
"$RUNNER" --verify-environment-only >/dev/null
QBO_OPTION_STATE="$(capture_option_state 'oras_tickets_settings_v1')"
[[ -n "$QBO_OPTION_STATE" ]] || {
	echo 'Could not capture disposable QuickBooks option state.' >&2
	exit 1
}
FIXTURE_STATE_CAPTURED=1

wp_env run tests-cli wp eval '
	global $wpdb;
	$wpdb->update( $wpdb->options, array( "option_value" => "https://production.example.invalid" ), array( "option_name" => "home" ) );
' >/dev/null
assert_fixture_rejected_with 'non-loopback or redirected WordPress URL'
wp_env run tests-cli wp eval '
	global $wpdb;
	$wpdb->update( $wpdb->options, array( "option_value" => "http://localhost:8895" ), array( "option_name" => "home" ) );
' >/dev/null

wp_env run tests-cli wp option update oras_qbo_disposable_fixture_id 'copied-or-forged-marker' >/dev/null
assert_fixture_rejected_with 'missing, copied, or forged disposable database marker'
wp_env run tests-cli wp option update oras_qbo_disposable_fixture_id "$EXPECTED_MARKER" >/dev/null

wp_env run tests-cli wp eval '
	$settings = get_option( "oras_tickets_settings_v1", array() );
	$qbo = isset( $settings["quickbooks"] ) && is_array( $settings["quickbooks"] ) ? $settings["quickbooks"] : array();
	$qbo["realm_id"] = "production-like-realm";
	$qbo["access_token"] = "production-like-access-token";
	$settings["quickbooks"] = $qbo;
	update_option( "oras_tickets_settings_v1", $settings, false );
' >/dev/null
assert_fixture_rejected_with 'credentialed, or tokenized QuickBooks settings'
restore_option_state 'oras_tickets_settings_v1' "$QBO_OPTION_STATE"

# A successful baseline verification includes a real unmocked Intuit-host call
# that must return the repository MU block error, never network fallthrough.
"$RUNNER" --verify-environment-only >/dev/null
assert_repository_docker_config_clean

echo "QBO runner guard rejection tests passed."
