#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
RUNNER="$ROOT_DIR/scripts/run-qbo-integration-checks.sh"
WP_ENV_BIN="$ROOT_DIR/node_modules/.bin/wp-env"
EXPECTED_HOME='http://localhost:8895'
legacy_project="$(printf '%s' "$ROOT_DIR/.wp-env.json" | md5sum | awk '{print $1}')"
project_directory="${ROOT_DIR##*/}"
descriptive_project="wp-env-${project_directory//[^a-zA-Z0-9._]/-}-${legacy_project:0:8}"
account_home="$(getent passwd "$(id -u)" | awk -F: '{print $6}')"
if [[ -e /snap ]]; then
	wp_env_cache_root="$account_home/wp-env"
else
	wp_env_cache_root="$account_home/.wp-env"
fi
if [[ -d "$wp_env_cache_root/$legacy_project" ]]; then
	wp_env_directory_name="$legacy_project"
else
	wp_env_directory_name="$descriptive_project"
fi
EXPECTED_PROJECT="${wp_env_directory_name,,}"
EXPECTED_PROJECT="${EXPECTED_PROJECT//./}"
EXPECTED_MARKER="oras-tickets-qbo-tests-v1-${legacy_project:0:16}"
QBO_OPTION_STATE=''
FIXTURE_STATE_CAPTURED=0
DOCKER_CONFIG_DIR=''
alternate_root=''
portable_parent=''
portable_root=''
compose_backup=''
compose_path=''
renamed_container_from=''
renamed_container_to=''
readonly ENV_BIN='/usr/bin/env'

node_bin="$(realpath "$(command -v node)")"
node_path="${node_bin%/*}:/usr/local/bin:/usr/bin:/bin"

wp_env() {
	(
		cd "$ROOT_DIR"
		"$ENV_BIN" -i \
			HOME="$account_home" \
			PATH='/usr/local/bin:/usr/bin:/bin' \
			DOCKER_CONFIG="$DOCKER_CONFIG_DIR" \
			CI=1 \
			COMPOSE_BAKE=false \
			"$node_bin" "$WP_ENV_BIN" "$@"
	)
}

assert_static_accepted() {
	local output
	if ! output="$(env "$@" "$RUNNER" --verify-static-identity-only 2>&1)"; then
		echo 'Runner rejected an approved portable static environment.' >&2
		echo "$output" >&2
		exit 1
	fi
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
	if [[ -n "$renamed_container_to" ]] && docker inspect "$renamed_container_to" >/dev/null 2>&1; then
		docker rename "$renamed_container_to" "$renamed_container_from" >/dev/null 2>&1 || true
	fi
	if [[ -n "$compose_backup" && -f "$compose_backup" && -n "$compose_path" ]]; then
		if [[ -L "$compose_path" ]]; then
			rm -- "$compose_path" 2>/dev/null || true
		fi
		cp "$compose_backup" "$compose_path" 2>/dev/null || true
	fi
	if [[ "$FIXTURE_STATE_CAPTURED" -eq 1 ]]; then
		wp_env run tests-cli wp eval '
			global $wpdb;
			$wpdb->update( $wpdb->options, array( "option_value" => "http://localhost:8895" ), array( "option_name" => "home" ) );
			$wpdb->update( $wpdb->options, array( "option_value" => "http://localhost:8895" ), array( "option_name" => "siteurl" ) );
		' >/dev/null 2>&1 || true
		wp_env run tests-cli wp option update oras_qbo_disposable_fixture_id "$EXPECTED_MARKER" >/dev/null 2>&1 || true
		restore_option_state 'oras_tickets_settings_v1' "$QBO_OPTION_STATE" || true
	fi
	if [[ -n "$portable_root" ]]; then
		git -C "$ROOT_DIR" worktree remove --force "$portable_root" >/dev/null 2>&1 || true
	fi
	for cleanup_path in "$portable_parent" "$alternate_root"; do
		if [[ -n "$cleanup_path" && "$cleanup_path" == "$account_home"/oras-qbo-* && -d "$cleanup_path" ]]; then
			find "$cleanup_path" -depth -mindepth 1 -delete 2>/dev/null || true
			rmdir "$cleanup_path" 2>/dev/null || true
		fi
	done
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

assert_mode_rejected_with() {
	local expected="$1"
	local mode="$2"
	shift 2
	local output
	if output="$(env "$@" "$RUNNER" "$mode" 2>&1)"; then
		echo "Runner guard accepted an unsafe test condition: $expected" >&2
		exit 1
	fi
	if [[ "$output" != *"$expected"* ]]; then
		echo "Runner rejected an unsafe test condition for the wrong reason; expected: $expected" >&2
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
	'EXPECTED_REPOSITORY_URL=' \
	'EXPECTED_CONFIG_SHA256=' \
	'EXPECTED_PACKAGE_LOCK_SHA256=' \
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
	'--verify-static-identity-only'; do
	assert_source_contains "$required_guard"
done

assert_static_accepted
assert_static_accepted \
	COMPOSER_PROCESS_TIMEOUT=0 \
	COMPOSER_NO_INTERACTION=1 \
	COMPOSER_NO_AUDIT=1 \
	ORAS_WP_ENV_DIR=. \
	ORAS_WP_ENV_CMD=./node_modules/.bin/wp-env
assert_static_accepted \
	ORAS_WP_ENV_DIR="$ROOT_DIR" \
	ORAS_WP_ENV_CMD="$ROOT_DIR/node_modules/.bin/wp-env"

# Exercise the same runner from a second real Git worktree whose shape mirrors
# /home/runner/work/<repo>/<repo>. Only the platform path differs.
trap cleanup EXIT
portable_parent="$(mktemp -d "$account_home/oras-qbo-gha-workspace.XXXXXX")"
mkdir -p "$portable_parent/work/ORAS-Tickets"
portable_root="$portable_parent/work/ORAS-Tickets/ORAS-Tickets"
git -C "$ROOT_DIR" worktree add --detach "$portable_root" HEAD >/dev/null
cp "$RUNNER" "$portable_root/scripts/run-qbo-integration-checks.sh"
chmod +x "$portable_root/scripts/run-qbo-integration-checks.sh"
cp -al "$ROOT_DIR/node_modules" "$portable_root/node_modules"
if ! portable_output="$(
	cd "$portable_root"
	env \
		CI=true \
		GITHUB_ACTIONS=true \
		GITHUB_WORKSPACE="$portable_root" \
		COMPOSER_PROCESS_TIMEOUT=0 \
		COMPOSER_NO_INTERACTION=1 \
		COMPOSER_NO_AUDIT=1 \
		ORAS_WP_ENV_DIR=. \
		ORAS_WP_ENV_CMD=./node_modules/.bin/wp-env \
		./scripts/run-qbo-integration-checks.sh --verify-static-identity-only 2>&1
)"; then
	echo 'Runner rejected a GitHub Actions-style canonical worktree.' >&2
	echo "$portable_output" >&2
	exit 1
fi

alternate_root="$(mktemp -d "$account_home/oras-qbo-guard-fixtures.XXXXXX")"
cp "$ROOT_DIR/.wp-env.json" "$alternate_root/.wp-env.json"
DOCKER_CONFIG_DIR="$alternate_root/docker-config"
mkdir "$DOCKER_CONFIG_DIR"
cp "$ROOT_DIR/.wp-env/docker/config.json" "$DOCKER_CONFIG_DIR/config.json"

assert_rejected_with 'ORAS_WP_ENV_DIR must identify only the canonical checkout' ORAS_WP_ENV_DIR="$alternate_root"
assert_rejected_with 'ORAS_WP_ENV_DIR must identify only the canonical checkout' ORAS_WP_ENV_DIR="$ROOT_DIR/scripts/.."
ln -s "$ROOT_DIR" "$alternate_root/repository-link"
assert_rejected_with 'ORAS_WP_ENV_DIR must identify only the canonical checkout' ORAS_WP_ENV_DIR="$alternate_root/repository-link"
assert_rejected_with 'ORAS_WP_ENV_CMD must identify only the repository-local wp-env entry point' ORAS_WP_ENV_CMD='npx --yes @wordpress/env'
assert_rejected_with 'ORAS_WP_ENV_CMD must identify only the repository-local wp-env entry point' ORAS_WP_ENV_CMD="$alternate_root/repository-link/node_modules/.bin/wp-env"
assert_rejected_with 'GitHub Actions workspace identity does not match the canonical checkout' \
	CI=true GITHUB_ACTIONS=true GITHUB_WORKSPACE="$alternate_root"
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
assert_rejected_with 'IFS overrides are not permitted' IFS='malicious'
assert_rejected_with 'LD_LIBRARY_PATH overrides are not permitted' LD_LIBRARY_PATH="$alternate_root"
assert_rejected_with 'PHP_INI_SCAN_DIR overrides are not permitted' PHP_INI_SCAN_DIR="$alternate_root"
assert_rejected_with 'COMPOSER_HOME overrides are not permitted' COMPOSER_HOME="$alternate_root"
assert_rejected_with 'COMPOSER_AUTH overrides are not permitted' COMPOSER_AUTH='{"github-oauth":{"github.com":"unsafe"}}'
assert_rejected_with 'COMPOSER_VENDOR_DIR overrides are not permitted' COMPOSER_VENDOR_DIR="$alternate_root/vendor"
assert_rejected_with 'COMPOSER_NO_AUDIT must be the exact workflow value 1' COMPOSER_NO_AUDIT=0
assert_rejected_with 'COMPOSER_PROCESS_TIMEOUT must be the exact workflow value 0' COMPOSER_PROCESS_TIMEOUT=1
assert_rejected_with 'COMPOSER_NO_INTERACTION must be the exact workflow value 1' COMPOSER_NO_INTERACTION=0
assert_rejected_with 'WP_CLI_CONFIG_PATH overrides are not permitted' WP_CLI_CONFIG_PATH="$alternate_root/wp-cli.yml"
assert_rejected_with 'legacy disposable sentinel is not trusted' ORAS_QBO_DISPOSABLE_TEST_SENTINEL='ORAS_QBO_DISPOSABLE_LOCAL_ONLY'

alternate_repository="$alternate_root/alternate-repository"
git clone --quiet --shared "$ROOT_DIR" "$alternate_repository"
git -C "$alternate_repository" remote set-url origin 'https://github.com/example/not-oras-tickets.git'
cp "$RUNNER" "$alternate_repository/scripts/run-qbo-integration-checks.sh"
chmod +x "$alternate_repository/scripts/run-qbo-integration-checks.sh"
if alternate_repository_output="$(
	cd "$alternate_repository"
	./scripts/run-qbo-integration-checks.sh --verify-static-identity-only 2>&1
)"; then
	echo 'Runner accepted an alternate Git repository.' >&2
	exit 1
fi
if [[ "$alternate_repository_output" != *'Git origin does not identify the canonical ORAS Tickets repository'* ]]; then
	echo 'Runner rejected an alternate Git repository for the wrong reason.' >&2
	echo "$alternate_repository_output" >&2
	exit 1
fi

assert_mode_rejected_with \
	'HTTP interception fell through instead of blocking unmocked Intuit traffic' \
	'--guard-test-http-fallthrough'

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
for command_name in realpath sha256sum md5sum awk mktemp cp find rmdir php git getent id readlink grep tail wc basename docker; do
	printf '#!/bin/sh\nprintf touched > %s\nexit 99\n' "$fake_path_marker" > "$fake_path_dir/$command_name"
	chmod +x "$fake_path_dir/$command_name"
done
PATH="$fake_path_dir:$node_path" "$RUNNER" --verify-static-identity-only >/dev/null
if [[ -e "$fake_path_marker" ]]; then
	echo 'Runner executed a hostile PATH binary.' >&2
	exit 1
fi

fake_node_marker="$alternate_root/fake-node-executed"
printf '#!/bin/sh\nprintf touched > %s\nexit 99\n' "$fake_node_marker" > "$fake_path_dir/node"
chmod +x "$fake_path_dir/node"
assert_node_startup_rejected_without_execution \
	'Node executable content does not match the approved release' \
	"$fake_node_marker" \
	PATH="$fake_path_dir:$node_path"

# The immutable fixture must also reject unsafe state inside otherwise correctly
# labelled containers. Every mutation is confined to the disposable tests DB
# and restored by the EXIT trap.
"$RUNNER" --verify-environment-only >/dev/null

cli_container_id="$(docker ps \
	--filter "label=com.docker.compose.project=$EXPECTED_PROJECT" \
	--filter 'label=com.docker.compose.service=tests-cli' \
	--format '{{.ID}}')"
[[ -n "$cli_container_id" && "$cli_container_id" != *$'\n'* ]] || {
	echo 'Could not identify the disposable tests-cli container.' >&2
	exit 1
}
compose_path="$(docker inspect --format '{{index .Config.Labels "com.docker.compose.project.config_files"}}' "$cli_container_id")"
[[ "$compose_path" == "$account_home"/*/"$wp_env_directory_name/docker-compose.yml" && -f "$compose_path" ]] || {
	echo 'Could not identify the canonical generated Compose file.' >&2
	exit 1
}
compose_backup="$alternate_root/docker-compose.approved.yml"
cp "$compose_path" "$compose_backup"

# Alternate Compose mount and database identities are rejected before Docker is
# allowed to act on the changed file.
sed -i "s|$ROOT_DIR/oras-tickets|$alternate_root/alternate-plugin|g" "$compose_path"
assert_fixture_rejected_with 'generated Compose configuration does not match its pinned normalized identity'
cp "$compose_backup" "$compose_path"
sed -i '0,/MYSQL_DATABASE: tests-wordpress/s//MYSQL_DATABASE: production-wordpress/' "$compose_path"
assert_fixture_rejected_with 'generated Compose configuration does not match its pinned normalized identity'
cp "$compose_backup" "$compose_path"

forged_compose="$alternate_root/forged-compose.yml"
cp "$compose_backup" "$forged_compose"
rm -- "$compose_path"
ln -s "$forged_compose" "$compose_path"
assert_fixture_rejected_with 'derived wp-env project or Compose path is redirected by a symlink'
rm -- "$compose_path"
cp "$compose_backup" "$compose_path"

# Container labels may still name the right project, but a renamed container is
# not the exact wp-env service identity and must fail closed.
renamed_container_from="$EXPECTED_PROJECT-tests-cli-1"
renamed_container_to="$EXPECTED_PROJECT-tests-cli-forged"
docker rename "$renamed_container_from" "$renamed_container_to" >/dev/null
assert_fixture_rejected_with 'container/project identity did not match the repository fixture'
docker rename "$renamed_container_to" "$renamed_container_from" >/dev/null
renamed_container_from=''
renamed_container_to=''

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
portable_marker_hash="$(printf '%s' "$portable_root/.wp-env.json" | md5sum | awk '{print $1}')"
wp_env run tests-cli wp option update oras_qbo_disposable_fixture_id \
	"oras-tickets-qbo-tests-v1-${portable_marker_hash:0:16}" >/dev/null
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
