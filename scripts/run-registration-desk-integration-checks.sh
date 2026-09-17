#!/bin/bash -p
set -euo pipefail

fail() {
	printf 'Refusing Registration Desk integration checks: %s\n' "$1" >&2
	exit 1
}

[[ "${BASH_SOURCE[0]}" == "$0" ]] || fail 'the safety runner cannot be sourced.'
[[ ! -v BASH_ENV && ! -v ENV && ! -v CDPATH ]] || fail 'shell startup overrides are not permitted.'

readonly EXPECTED_ORIGIN='https://github.com/Shadowgar/ORAS-Tickets.git'
readonly EXPECTED_BASE='b0ba56e33999d91c4183eaf95f363dd57f6ef19a'
readonly EXPECTED_WP_ENV_CONFIG_SHA256='dd467007ca6eb8ca19f3a99bbe90ee541e92a8e27513d4d36b0526617b195e88'
readonly EXPECTED_QBO_GUARD_SHA256='ecf2c90829b4ce2bf10bc06aaf97158ce95ede66c213bcd793596ee2ee1b3eb5'
readonly EXPECTED_GUARD_SHA256='4faf09f2916e628f324e6bd7848e5787b3b823458fb13006db1fc778ae58fe42'
readonly EXPECTED_PACKAGE_SHA256='0cf60445b6f2c2fd8d72374e0a5cf8021c871b536d9bff77e5b076086acc7725'
readonly EXPECTED_LOCK_SHA256='0f4880c3d1e1a39ac2e2698b232d3c8b387ac0c107ff010da7b1d52fb88159e7'
readonly EXPECTED_NODE_SHA256='1bec56ef7cfa9a76f3e0b7c0a87f220eb73f23102b9c0b4c7529a3f7c3ce7c31'
readonly EXPECTED_WP_ENV_PROJECT_PACKAGE_SHA256='89a46f20aaa6305e13175f894f6e0c8cdf6fb8eda1ef75b7442f1e8280631aba'
readonly EXPECTED_WP_ENV_PROJECT_LOCK_SHA256='ef015df748cb0c4d84a8b710ce90f0e7cbfeaaabdbee819c9fdfaee62314586f'
readonly EXPECTED_WP_ENV_PACKAGE_SHA256='7d2a733a1e5cea3960d39fd191f25bbb251f505a9ebf7a9084157338ef3d38ca'
readonly EXPECTED_WP_ENV_CLI_SHA256='b66a7b3b1d12afe2f94045103e1d5d01e1c1e81ef43794f80c5dba5e8fda0208'
readonly EXPECTED_DATABASE='tests-wordpress'
readonly EXPECTED_DATABASE_HOST='tests-mysql'
readonly TEST_SERVICE='tests-cli'

readonly REALPATH_BIN='/usr/bin/realpath'
readonly SHA256_BIN='/usr/bin/sha256sum'
readonly AWK_BIN='/usr/bin/awk'
readonly GIT_BIN='/usr/bin/git'
readonly DOCKER_BIN='/usr/bin/docker'
readonly ENV_BIN='/usr/bin/env'
readonly PHP_BIN='/usr/bin/php'
readonly MKTEMP_BIN='/usr/bin/mktemp'
readonly CP_BIN='/usr/bin/cp'
readonly FIND_BIN='/usr/bin/find'
readonly RMDIR_BIN='/usr/bin/rmdir'
readonly CURL_BIN='/usr/bin/curl'
readonly CMP_BIN='/usr/bin/cmp'
readonly SLEEP_BIN='/usr/bin/sleep'

RUNNER_PATH="$($REALPATH_BIN -e "${BASH_SOURCE[0]}")"
ROOT_DIR="$($REALPATH_BIN "${RUNNER_PATH%/*}/..")"
WP_ENV_PROJECT='/home/rocco/projects/oras-wp-env'
WP_ENV_CONFIG="$WP_ENV_PROJECT/.wp-env.json"
QBO_GUARD_FILE="$ROOT_DIR/scripts/fixtures/oras-qbo-http-block.php"
GUARD_FILE="$ROOT_DIR/scripts/fixtures/oras-registration-desk-test-guard.php"
COMMON_GIT_DIR="$($GIT_BIN -C "$ROOT_DIR" rev-parse --git-common-dir)"
if [[ "$COMMON_GIT_DIR" == /* ]]; then
	COMMON_GIT_DIR="$($REALPATH_BIN -e "$COMMON_GIT_DIR")"
else
	COMMON_GIT_DIR="$($REALPATH_BIN -e "$ROOT_DIR/$COMMON_GIT_DIR")"
fi
PRIMARY_CHECKOUT="$($REALPATH_BIN "${COMMON_GIT_DIR%/.git}")"
NODE_BIN="$HOME/.nvm/versions/node/v22.22.0/bin/node"
WP_ENV_CLI="$WP_ENV_PROJECT/node_modules/@wordpress/env/lib/cli.js"
DOCKER_CONFIG_DIR=''
WP_ENV_HOME_DIR=''
BASE_COMPOSE_FILE=''
TEST_COMPOSE_OVERRIDE=''
DEVELOPMENT_SNAPSHOT=''
TEST_STATE_SNAPSHOT=''
SETTINGS_SNAPSHOT=''
EXPECTED_PROJECT=''
EXPECTED_URL=''
DISPOSABLE_MARKER=''
VERIFY_ONLY=0
INITIALIZE_MARKER=0
MODE='legacy'
STORAGE_CAPTURED=0
SETTINGS_CAPTURED=0
TEST_STATE_CAPTURED=0
TEST_SERVICES_STARTED=0
ORIGINAL_HPOS=''
ORIGINAL_SYNC=''
SETTINGS_EXISTED=0

while (( $# )); do
	case "$1" in
		--initialize-disposable-marker) INITIALIZE_MARKER=1 ;;
		--verify-environment-only) VERIFY_ONLY=1 ;;
		--mode=legacy) MODE='legacy' ;;
		--mode=hpos) MODE='hpos' ;;
		*) fail 'supported arguments are --initialize-disposable-marker, --verify-environment-only, --mode=legacy, or --mode=hpos.' ;;
	esac
	shift
done

sha256_of() {
	"$SHA256_BIN" "$1" | "$AWK_BIN" '{print $1}'
}

git_cmd() {
	"$ENV_BIN" -i HOME="$HOME" PATH='/usr/bin:/bin' GIT_CONFIG_NOSYSTEM=1 GIT_CONFIG_GLOBAL=/dev/null "$GIT_BIN" -C "$ROOT_DIR" "$@"
}

docker_cmd() {
	"$ENV_BIN" -i HOME="$HOME" PATH='/usr/local/bin:/usr/bin:/bin' DOCKER_CONFIG="$DOCKER_CONFIG_DIR" COMPOSE_BAKE=false "$DOCKER_BIN" "$@"
}

wp_env() {
	"$ENV_BIN" -i HOME="$HOME" PATH='/usr/local/bin:/usr/bin:/bin' DOCKER_CONFIG="$DOCKER_CONFIG_DIR" CI=1 COMPOSE_BAKE=false \
		"$NODE_BIN" -e 'const project=process.argv[1];const cli=process.argv[2];const args=process.argv.slice(3);process.chdir(project);require(cli)().parse(args);' \
		"$WP_ENV_PROJECT" "$WP_ENV_CLI" "$@"
}

verify_static_identity() {
	local origin install_path
	[[ "$ROOT_DIR" == /home/*/.config/superpowers/worktrees/ORAS-Tickets/* ]] || fail 'runner is not in an isolated ORAS Tickets worktree.'
	[[ "$(git_cmd rev-parse --show-toplevel)" == "$ROOT_DIR" ]] || fail 'runner is not at the canonical worktree root.'
	origin="$(git_cmd remote get-url origin)"
	case "$origin" in
		"$EXPECTED_ORIGIN"|"${EXPECTED_ORIGIN%.git}") ;;
		*) fail 'origin does not identify the canonical ORAS Tickets repository.' ;;
	esac
	git_cmd merge-base --is-ancestor "$EXPECTED_BASE" HEAD || fail 'HEAD does not descend from the approved implementation base.'
	[[ -z "$(git_cmd status --porcelain=v1)" ]] || fail 'feature worktree must be clean so mounted code has a committed identity.'
	[[ "$(sha256_of "$WP_ENV_CONFIG")" == "$EXPECTED_WP_ENV_CONFIG_SHA256" ]] || fail 'designated oras-wp-env configuration identity changed.'
	[[ "$(sha256_of "$QBO_GUARD_FILE")" == "$EXPECTED_QBO_GUARD_SHA256" ]] || fail 'Intuit HTTP guard identity changed.'
	[[ "$(sha256_of "$GUARD_FILE")" == "$EXPECTED_GUARD_SHA256" ]] || fail 'transport guard identity changed.'
	[[ "$(sha256_of "$ROOT_DIR/package.json")" == "$EXPECTED_PACKAGE_SHA256" ]] || fail 'package.json identity changed.'
	[[ "$(sha256_of "$ROOT_DIR/package-lock.json")" == "$EXPECTED_LOCK_SHA256" ]] || fail 'package-lock.json identity changed.'
	[[ -f "$NODE_BIN" && "$(sha256_of "$NODE_BIN")" == "$EXPECTED_NODE_SHA256" ]] || fail 'pinned Node executable is unavailable.'
	[[ "$($REALPATH_BIN -e "$WP_ENV_PROJECT")" == '/home/rocco/projects/oras-wp-env' ]] || fail 'oras-wp-env project path is unavailable.'
	[[ "$(sha256_of "$WP_ENV_PROJECT/package.json")" == "$EXPECTED_WP_ENV_PROJECT_PACKAGE_SHA256" ]] || fail 'oras-wp-env project package identity changed.'
	[[ "$(sha256_of "$WP_ENV_PROJECT/package-lock.json")" == "$EXPECTED_WP_ENV_PROJECT_LOCK_SHA256" ]] || fail 'oras-wp-env project lock identity changed.'
	[[ "$(sha256_of "$WP_ENV_PROJECT/node_modules/@wordpress/env/package.json")" == "$EXPECTED_WP_ENV_PACKAGE_SHA256" ]] || fail 'oras-wp-env installed package identity changed.'
	[[ "$(sha256_of "$WP_ENV_CLI")" == "$EXPECTED_WP_ENV_CLI_SHA256" ]] || fail 'oras-wp-env installed CLI identity changed.'
	[[ "$(wp_env --version)" == '10.39.0' ]] || fail 'oras-wp-env version is not 10.39.0.'

	install_path="$(wp_env install-path 2>/dev/null | /usr/bin/tail -1)"
	[[ "$install_path" == "$HOME"/wp-env/* ]] || fail 'wp-env returned an unsafe generated install path.'
	WP_ENV_HOME_DIR="$($REALPATH_BIN -e "$install_path")"
	EXPECTED_PROJECT="${WP_ENV_HOME_DIR##*/}"
	[[ "$EXPECTED_PROJECT" =~ ^[a-f0-9]{32}$ ]] || fail 'wp-env returned an invalid Compose project identity.'
	BASE_COMPOSE_FILE="$WP_ENV_HOME_DIR/docker-compose.yml"
	[[ -f "$BASE_COMPOSE_FILE" ]] || fail 'designated generated Compose file does not exist.'
	DISPOSABLE_MARKER="oras-registration-desk-m1a-${EXPECTED_PROJECT:0:16}"
}

prepare_docker_config() {
	DOCKER_CONFIG_DIR="$($MKTEMP_BIN -d /tmp/oras-desk-docker.XXXXXX)"
	[[ -f "$PRIMARY_CHECKOUT/.wp-env/docker/config.json" ]] || fail 'pinned local Docker client configuration is unavailable.'
	"$CP_BIN" "$PRIMARY_CHECKOUT/.wp-env/docker/config.json" "$DOCKER_CONFIG_DIR/config.json"
	[[ "$(docker_cmd context show)" == 'default' ]] || fail 'Docker context is not the local default context.'
	[[ "$(docker_cmd context inspect default --format '{{.Endpoints.docker.Host}}')" == 'unix:///var/run/docker.sock' ]] || fail 'Docker is not using the local Unix socket.'
}

compose_base() {
	docker_cmd compose --project-name "$EXPECTED_PROJECT" --project-directory "$WP_ENV_HOME_DIR" -f "$BASE_COMPOSE_FILE" "$@"
}

compose_test() {
	docker_cmd compose --project-name "$EXPECTED_PROJECT" --project-directory "$WP_ENV_HOME_DIR" -f "$BASE_COMPOSE_FILE" -f "$TEST_COMPOSE_OVERRIDE" "$@"
}

container_id_any() {
	local service="$1" ids
	ids="$(docker_cmd ps -a --filter "label=com.docker.compose.project=$EXPECTED_PROJECT" --filter "label=com.docker.compose.service=$service" --format '{{.ID}}')"
	[[ "$ids" != *$'\n'* ]] || fail "multiple $service containers belong to the designated project."
	printf '%s' "$ids"
}

capture_development_state_to() {
	local destination="$1" service id
	: >"$destination"
	for service in mysql wordpress cli phpmyadmin; do
		id="$(container_id_any "$service")"
		if [[ -z "$id" ]]; then
			printf '%s|absent\n' "$service" >>"$destination"
		else
			docker_cmd inspect "$id" --format "$service|identity|{{.Id}}|{{.State.Running}}|{{index .Config.Labels \"com.docker.compose.project.config_files\"}}" >>"$destination"
			docker_cmd inspect "$id" --format '{{range .Mounts}}{{println .Destination "|" .Type "|" .Source "|" .Name "|" .RW}}{{end}}' \
				| /usr/bin/sort | /usr/bin/sed "s/^/$service|mount|/" >>"$destination"
			docker_cmd inspect "$id" --format '{{range .Config.Env}}{{println .}}{{end}}' \
				| /usr/bin/sort | /usr/bin/sed "s/^/$service|env|/" >>"$destination"
		fi
	done
}

snapshot_development_state() {
	DEVELOPMENT_SNAPSHOT="$($MKTEMP_BIN /tmp/oras-desk-development.XXXXXX)"
	capture_development_state_to "$DEVELOPMENT_SNAPSHOT"
	printf '%s\n' 'Snapshotted ordinary development containers, mounts, environment, and database volume identity.'
}

verify_development_state() {
	local current
	current="$($MKTEMP_BIN /tmp/oras-desk-development-current.XXXXXX)"
	capture_development_state_to "$current"
	if ! "$CMP_BIN" -s "$DEVELOPMENT_SNAPSHOT" "$current"; then
		/usr/bin/diff -u "$DEVELOPMENT_SNAPSHOT" "$current" >&2 || true
		"$FIND_BIN" "$current" -delete
		return 1
	fi
	"$FIND_BIN" "$current" -delete
	printf '%s\n' 'Verified ordinary development services are unchanged.'
}

snapshot_test_state() {
	local service id running
	TEST_STATE_SNAPSHOT="$($MKTEMP_BIN /tmp/oras-desk-test-state.XXXXXX)"
	: >"$TEST_STATE_SNAPSHOT"
	for service in tests-mysql tests-wordpress tests-cli; do
		id="$(container_id_any "$service")"
		[[ -n "$id" ]] || fail "designated disposable $service container does not already exist."
		running="$(docker_cmd inspect "$id" --format '{{.State.Running}}')"
		printf '%s|%s\n' "$service" "$running" >>"$TEST_STATE_SNAPSHOT"
	done
	TEST_STATE_CAPTURED=1
}

create_test_compose_override() {
	TEST_COMPOSE_OVERRIDE="$($MKTEMP_BIN /tmp/oras-desk-compose.XXXXXX.yml)"
	{
		printf '%s\n' 'services:'
		for service in tests-wordpress tests-cli; do
			printf '  %s:\n' "$service"
			printf '%s\n' '    volumes:'
			printf '      - %s:/var/www/html/wp-content/plugins/oras-tickets\n' "$ROOT_DIR/oras-tickets"
			printf '      - %s:/var/www/html/wp-content/oras-qbo-tests\n' "$ROOT_DIR/scripts"
			printf '      - %s:/var/www/html/wp-content/mu-plugins/oras-qbo-http-block.php\n' "$QBO_GUARD_FILE"
			printf '      - %s:/var/www/html/wp-content/mu-plugins/oras-registration-desk-test-guard.php\n' "$GUARD_FILE"
		done
	} >"$TEST_COMPOSE_OVERRIDE"
	compose_test config --services | /usr/bin/grep -Fx 'tests-wordpress' >/dev/null || fail 'test Compose overlay is invalid.'
}

start_test_services() {
	local db_id
	TEST_SERVICES_STARTED=1
	db_id="$(container_id_any tests-mysql)"
	[[ -n "$db_id" ]] || fail 'designated disposable database container disappeared before startup.'
	docker_cmd start "$db_id" >/dev/null
	compose_test up -d --no-deps --force-recreate tests-wordpress
	compose_test up -d --no-deps --force-recreate tests-cli
}

verify_container() {
	local service="$1" id state project working_dir config_file
	id="$(container_id_any "$service")"
	[[ -n "$id" && "$id" != *$'\n'* ]] || fail "expected exactly one running $service container."
	state="$(docker_cmd inspect "$id" --format '{{.State.Running}}')"
	project="$(docker_cmd inspect "$id" --format '{{index .Config.Labels "com.docker.compose.project"}}')"
	working_dir="$(docker_cmd inspect "$id" --format '{{index .Config.Labels "com.docker.compose.project.working_dir"}}')"
	config_file="$(docker_cmd inspect "$id" --format '{{index .Config.Labels "com.docker.compose.project.config_files"}}')"
	[[ "$state" == 'true' && "$project" == "$EXPECTED_PROJECT" && "$working_dir" == "$WP_ENV_HOME_DIR" ]] || fail "$service container identity is not designated-project-derived."
	[[ "$config_file" == *"$BASE_COMPOSE_FILE"* ]] || fail "$service container does not use the designated base Compose file."
	if [[ "$service" == tests-wordpress || "$service" == tests-cli ]]; then
		[[ "$config_file" == *"$TEST_COMPOSE_OVERRIDE"* ]] || fail "$service container does not use the test-only Compose overlay."
	fi
	printf '%s' "$id"
}

wait_for_test_runtime() {
	local attempt
	for attempt in {1..60}; do
		if wp_env run "$TEST_SERVICE" wp core is-installed >/dev/null 2>&1; then
			return 0
		fi
		"$SLEEP_BIN" 1
	done
	fail 'designated test WordPress did not become ready.'
}

mounts_for() {
	docker_cmd inspect "$1" --format '{{range .Mounts}}{{println .Source "=>" .Destination}}{{end}}'
}

verify_mounted_code_identity() {
	local service id mounts host_digest container_digest head
	head="$(git_cmd rev-parse HEAD)"
	for service in tests-wordpress tests-cli; do
		id="$(verify_container "$service")"
		mounts="$(mounts_for "$id")"
		printf '%s\n' "$mounts" | /usr/bin/grep -F "$ROOT_DIR/oras-tickets => /var/www/html/wp-content/plugins/oras-tickets" >/dev/null || fail "$service plugin mount does not point at this feature worktree."
		printf '%s\n' "$mounts" | /usr/bin/grep -F "$ROOT_DIR/scripts => /var/www/html/wp-content/oras-qbo-tests" >/dev/null || fail "$service test-script mount does not point at this feature worktree."
		printf '%s\n' "$mounts" | /usr/bin/grep -F "$QBO_GUARD_FILE => /var/www/html/wp-content/mu-plugins/oras-qbo-http-block.php" >/dev/null || fail "$service Intuit guard mount is missing."
		printf '%s\n' "$mounts" | /usr/bin/grep -F "$GUARD_FILE => /var/www/html/wp-content/mu-plugins/oras-registration-desk-test-guard.php" >/dev/null || fail "$service transport guard mount is missing."
	done
	host_digest="$(cd "$ROOT_DIR/oras-tickets" && "$FIND_BIN" . -type f -print0 | /usr/bin/sort -z | /usr/bin/xargs -0 "$SHA256_BIN" | "$SHA256_BIN" | "$AWK_BIN" '{print $1}')"
	container_digest="$(docker_cmd exec "$(verify_container tests-cli)" sh -c 'cd /var/www/html/wp-content/plugins/oras-tickets && find . -type f -print0 | sort -z | xargs -0 sha256sum | sha256sum' | "$AWK_BIN" '{print $1}')"
	[[ -n "$host_digest" && "$host_digest" == "$container_digest" ]] || fail 'mounted feature plugin digest does not match the committed worktree.'
	printf 'Verified mounted feature code: commit=%s digest=%s\n' "$head" "$host_digest"
}

verify_runtime_identity() {
	local cli_id wordpress_id db_id dev_db_id test_volume dev_volume identity published_port url_port
	cli_id="$(verify_container tests-cli)"
	wordpress_id="$(verify_container tests-wordpress)"
	db_id="$(verify_container tests-mysql)"
	[[ "$(docker_cmd inspect "$db_id" --format '{{range .Config.Env}}{{println .}}{{end}}' | /usr/bin/grep '^MYSQL_DATABASE=' | /usr/bin/cut -d= -f2-)" == "$EXPECTED_DATABASE" ]] || fail 'test database container has the wrong database.'
	test_volume="$(docker_cmd inspect "$db_id" --format '{{range .Mounts}}{{if eq .Destination "/var/lib/mysql"}}{{println .Name}}{{end}}{{end}}')"
	[[ "$test_volume" == "${EXPECTED_PROJECT}_tests-mysql" ]] || fail 'test database does not use the designated disposable volume.'
	dev_db_id="$(container_id_any mysql)"
	if [[ -n "$dev_db_id" ]]; then
		dev_volume="$(docker_cmd inspect "$dev_db_id" --format '{{range .Mounts}}{{if eq .Destination "/var/lib/mysql"}}{{println .Name}}{{end}}{{end}}')"
		[[ -n "$dev_volume" && "$test_volume" != "$dev_volume" ]] || fail 'test and ordinary development databases are not storage-isolated.'
	fi

	identity="$(wp_env run "$TEST_SERVICE" wp eval 'echo wp_json_encode(array("db"=>DB_NAME,"host"=>DB_HOST,"home"=>get_option("home"),"siteurl"=>get_option("siteurl"),"registration_guard"=>defined("ORAS_REGISTRATION_DESK_TEST_GUARD_ACTIVE")&&ORAS_REGISTRATION_DESK_TEST_GUARD_ACTIVE,"qbo_guard"=>defined("ORAS_QBO_HTTP_BLOCK_ACTIVE")&&ORAS_QBO_HTTP_BLOCK_ACTIVE));' 2>/dev/null | /usr/bin/grep -E '^\{.*\}$' | /usr/bin/tail -1)"
	EXPECTED_URL="$("$PHP_BIN" -r '$v=json_decode($argv[1],true);if(!is_array($v)||($v["db"]??"")!==$argv[2]||($v["host"]??"")!==$argv[3]||empty($v["registration_guard"])||empty($v["qbo_guard"])||($v["home"]??"")!==($v["siteurl"]??"")){exit(1);}echo $v["home"];' "$identity" "$EXPECTED_DATABASE" "$EXPECTED_DATABASE_HOST")" || fail 'WordPress database or transport guard identity is unsafe.'
	[[ "$EXPECTED_URL" =~ ^http://localhost:([1-9][0-9]*)$ ]] || fail 'designated test URL is not a local HTTP endpoint.'
	url_port="${BASH_REMATCH[1]}"
	published_port="$(docker_cmd port "$wordpress_id" 80/tcp | /usr/bin/tail -1)"
	[[ "$published_port" == *":$url_port" ]] || fail 'designated test URL does not match the published test container port.'
}

verify_disposable_marker() {
	local marker_state marker_status
	marker_state="$(wp_env run "$TEST_SERVICE" wp eval '
		$missing = new stdClass();
		$value = get_option("oras_registration_desk_disposable_fixture_id", $missing);
		echo wp_json_encode(array("exists" => $value !== $missing, "value" => $value !== $missing ? $value : ""));
	' 2>/dev/null | /usr/bin/grep -E '^\{.*\}$' | /usr/bin/tail -1)"
	set +e
	"$PHP_BIN" -r '$v=json_decode($argv[1],true);if(!is_array($v)){exit(2);}if(!empty($v["exists"])&&($v["value"]??"")!==$argv[2]){exit(3);}exit(!empty($v["exists"])?0:1);' "$marker_state" "$DISPOSABLE_MARKER"
	marker_status=$?
	set -e
	case "$marker_status" in
		0) ;;
		1)
			(( INITIALIZE_MARKER )) || fail 'designated test database is missing its disposable marker; use the explicit initialization mode only after reviewing the verified identity.'
			wp_env run "$TEST_SERVICE" wp eval "
				if (!add_option('oras_registration_desk_disposable_fixture_id', '$DISPOSABLE_MARKER', '', false)) {
					throw new RuntimeException('Could not initialize disposable marker.');
				}
			" >/dev/null || fail 'could not initialize the marker in the verified disposable database.'
			printf '%s\n' 'Initialized marker only after verifying designated disposable database isolation.'
			;;
		*) fail 'designated test database contains a copied or mismatched disposable marker.' ;;
	esac
	[[ "$(wp_env run "$TEST_SERVICE" wp option get oras_registration_desk_disposable_fixture_id 2>/dev/null)" == "$DISPOSABLE_MARKER" ]] || fail 'disposable marker verification failed.'
}

capture_settings() {
	SETTINGS_SNAPSHOT="$($MKTEMP_BIN /tmp/oras-desk-settings.XXXXXX.json)"
	if wp_env run "$TEST_SERVICE" wp option get oras_tickets_settings_v1 --format=json >"$SETTINGS_SNAPSHOT" 2>/dev/null; then
		SETTINGS_EXISTED=1
	else
		SETTINGS_EXISTED=0
		: >"$SETTINGS_SNAPSHOT"
	fi
	SETTINGS_CAPTURED=1
}

restore_test_options() {
	local status=0 settings_json
	if (( STORAGE_CAPTURED )); then
		wp_env run "$TEST_SERVICE" wp wc hpos sync >/dev/null 2>&1 || status=1
		if [[ "$ORIGINAL_HPOS" == '__missing__' ]]; then
			wp_env run "$TEST_SERVICE" wp option delete woocommerce_custom_orders_table_enabled >/dev/null 2>&1 || true
		else
			wp_env run "$TEST_SERVICE" wp option update woocommerce_custom_orders_table_enabled "$ORIGINAL_HPOS" >/dev/null 2>&1 || status=1
		fi
		if [[ "$ORIGINAL_SYNC" == '__missing__' ]]; then
			wp_env run "$TEST_SERVICE" wp option delete woocommerce_custom_orders_table_data_sync_enabled >/dev/null 2>&1 || true
		else
			wp_env run "$TEST_SERVICE" wp option update woocommerce_custom_orders_table_data_sync_enabled "$ORIGINAL_SYNC" >/dev/null 2>&1 || status=1
		fi
	fi
	if (( SETTINGS_CAPTURED )); then
		if (( SETTINGS_EXISTED )); then
			settings_json="$(/usr/bin/cat "$SETTINGS_SNAPSHOT")"
			wp_env run "$TEST_SERVICE" wp option update oras_tickets_settings_v1 "$settings_json" --format=json >/dev/null 2>&1 || status=1
		else
			wp_env run "$TEST_SERVICE" wp option delete oras_tickets_settings_v1 >/dev/null 2>&1 || true
		fi
	fi
	return "$status"
}

initial_test_state() {
	local service="$1"
	"$AWK_BIN" -F'|' -v service="$service" '$1 == service { print $2 }' "$TEST_STATE_SNAPSHOT"
}

restore_test_services() {
	local service expected actual id mounts status=0
	(( TEST_STATE_CAPTURED && TEST_SERVICES_STARTED )) || return 0
	id="$(container_id_any tests-mysql)"
	[[ -n "$id" ]] && docker_cmd start "$id" >/dev/null || status=1
	compose_base up -d --no-deps --force-recreate tests-wordpress >/dev/null || status=1
	compose_base up -d --no-deps --force-recreate tests-cli >/dev/null || status=1
	for service in tests-cli tests-wordpress tests-mysql; do
		expected="$(initial_test_state "$service")"
		if [[ "$expected" == 'false' ]]; then
			compose_base stop "$service" >/dev/null || status=1
		fi
	done
	for service in tests-mysql tests-wordpress tests-cli; do
		expected="$(initial_test_state "$service")"
		id="$(container_id_any "$service")"
		[[ -n "$id" ]] || status=1
		if [[ -n "$id" ]]; then
			actual="$(docker_cmd inspect "$id" --format '{{.State.Running}}')"
			[[ "$actual" == "$expected" ]] || status=1
			if [[ "$service" == tests-wordpress || "$service" == tests-cli ]]; then
				mounts="$(mounts_for "$id")"
				printf '%s\n' "$mounts" | /usr/bin/grep -F "$PRIMARY_CHECKOUT/oras-tickets => /var/www/html/wp-content/plugins/oras-tickets" >/dev/null || status=1
				[[ "$(docker_cmd inspect "$id" --format '{{index .Config.Labels "com.docker.compose.project.config_files"}}')" == "$BASE_COMPOSE_FILE" ]] || status=1
			fi
		fi
	done
	if (( status == 0 )); then
		printf '%s\n' 'Restored designated test mounts and original service states.'
	fi
	return "$status"
}

report_separate_worktree_runtime() {
	local id project service state mounts found=0
	while IFS= read -r id; do
		[[ -n "$id" ]] || continue
		project="$(docker_cmd inspect "$id" --format '{{index .Config.Labels "com.docker.compose.project"}}')"
		[[ -n "$project" && "$project" != "$EXPECTED_PROJECT" ]] || continue
		mounts="$(mounts_for "$id")"
		if printf '%s\n' "$mounts" | /usr/bin/grep -F "$ROOT_DIR/" >/dev/null; then
			service="$(docker_cmd inspect "$id" --format '{{index .Config.Labels "com.docker.compose.service"}}')"
			state="$(docker_cmd inspect "$id" --format '{{.State.Status}}')"
			printf 'Separate worktree-derived runtime retained: project=%s service=%s state=%s\n' "$project" "$service" "$state"
			found=1
		fi
	done < <(docker_cmd ps -a --filter 'label=com.docker.compose.project' --format '{{.ID}}')
	(( found )) || printf '%s\n' 'No separate worktree-derived runtime containers were found.'
}

remove_temp_file() {
	local path="$1" prefix="$2"
	[[ -z "$path" ]] && return 0
	[[ "$path" == "$prefix"* && -f "$path" ]] && "$FIND_BIN" "$path" -delete
}

cleanup() {
	local command_status=$? cleanup_status=0
	trap - EXIT
	set +e
	if (( TEST_SERVICES_STARTED )); then
		restore_test_options || cleanup_status=1
		restore_test_services || cleanup_status=1
	fi
	if [[ -n "$DEVELOPMENT_SNAPSHOT" && -f "$DEVELOPMENT_SNAPSHOT" ]]; then
		verify_development_state || cleanup_status=1
	fi
	remove_temp_file "$TEST_COMPOSE_OVERRIDE" '/tmp/oras-desk-compose.'
	remove_temp_file "$DEVELOPMENT_SNAPSHOT" '/tmp/oras-desk-development.'
	remove_temp_file "$TEST_STATE_SNAPSHOT" '/tmp/oras-desk-test-state.'
	remove_temp_file "$SETTINGS_SNAPSHOT" '/tmp/oras-desk-settings.'
	if [[ -n "$DOCKER_CONFIG_DIR" && "$DOCKER_CONFIG_DIR" == /tmp/oras-desk-docker.* && -d "$DOCKER_CONFIG_DIR" ]]; then
		"$FIND_BIN" "$DOCKER_CONFIG_DIR" -depth -mindepth 1 -delete
		"$RMDIR_BIN" "$DOCKER_CONFIG_DIR"
	fi
	if (( cleanup_status != 0 )); then
		printf '%s\n' 'Registration Desk cleanup or state restoration failed.' >&2
		command_status=1
	fi
	exit "$command_status"
}

trap cleanup EXIT

configure_safe_integrations() {
	wp_env run "$TEST_SERVICE" wp eval '
		$settings = get_option("oras_tickets_settings_v1", array());
		$settings = is_array($settings) ? $settings : array();
		$settings["quickbooks"] = array("enabled"=>false,"dry_run_mode"=>true,"sandbox"=>true,"client_id"=>"","client_secret"=>"","realm_id"=>"","access_token"=>"","refresh_token"=>"");
		update_option("oras_tickets_settings_v1", $settings, false);
	' >/dev/null
}

ensure_dependencies() {
	if ! wp_env run "$TEST_SERVICE" wp plugin is-installed the-events-calendar >/dev/null 2>&1; then
		wp_env run "$TEST_SERVICE" wp --exec="define('ORAS_REGISTRATION_DESK_ALLOW_PLUGIN_DOWNLOADS',true);" plugin install the-events-calendar --activate
	else
		wp_env run "$TEST_SERVICE" wp plugin activate the-events-calendar >/dev/null || true
	fi
	if ! wp_env run "$TEST_SERVICE" wp plugin is-installed woocommerce >/dev/null 2>&1; then
		wp_env run "$TEST_SERVICE" wp --exec="define('ORAS_REGISTRATION_DESK_ALLOW_PLUGIN_DOWNLOADS',true);" plugin install woocommerce --activate
	else
		wp_env run "$TEST_SERVICE" wp plugin activate woocommerce >/dev/null || true
	fi
	wp_env run "$TEST_SERVICE" wp plugin activate oras-tickets >/dev/null || true
	wp_env run "$TEST_SERVICE" wp eval 'if(!class_exists("WooCommerce")||!class_exists("Tribe__Events__Main")||!class_exists("ORAS\\Tickets\\Registration_Desk\\Schema")){exit(1);} echo WC_VERSION," ",Tribe__Events__Main::VERSION;'
}

read_option_or_missing() {
	local name="$1" value
	value="$(wp_env run "$TEST_SERVICE" wp option get "$name" 2>/dev/null || true)"
	[[ -n "$value" ]] && printf '%s' "$value" || printf '%s' '__missing__'
}

configure_order_storage() {
	local expected actual
	ORIGINAL_HPOS="$(read_option_or_missing woocommerce_custom_orders_table_enabled)"
	ORIGINAL_SYNC="$(read_option_or_missing woocommerce_custom_orders_table_data_sync_enabled)"
	STORAGE_CAPTURED=1
	wp_env run "$TEST_SERVICE" wp wc hpos sync >/dev/null || fail 'WooCommerce could not synchronize disposable fixtures before changing authoritative storage.'
	if [[ "$MODE" == 'hpos' ]]; then
		wp_env run "$TEST_SERVICE" wp option update woocommerce_custom_orders_table_enabled yes >/dev/null
		wp_env run "$TEST_SERVICE" wp option update woocommerce_custom_orders_table_data_sync_enabled no >/dev/null
		expected='1'
	else
		wp_env run "$TEST_SERVICE" wp option update woocommerce_custom_orders_table_enabled no >/dev/null
		wp_env run "$TEST_SERVICE" wp option update woocommerce_custom_orders_table_data_sync_enabled no >/dev/null
		expected='0'
	fi
	actual="$(wp_env run "$TEST_SERVICE" wp eval 'echo "ORAS_HPOS=" . (Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? "1" : "0");' 2>/dev/null | /usr/bin/grep '^ORAS_HPOS=' | /usr/bin/tail -1)"
	[[ "$actual" == "ORAS_HPOS=$expected" ]] || fail "WooCommerce did not enter requested $MODE authoritative order storage mode."
	printf 'Verified WooCommerce order storage mode: %s\n' "$MODE"
}

run_eval_file() {
	local file="$1" phase="$2"
	wp_env run "$TEST_SERVICE" wp --exec="define('ORAS_REGISTRATION_DESK_DISPOSABLE_MARKER_EXPECTED','$DISPOSABLE_MARKER');define('ORAS_REGISTRATION_DESK_TEST_PHASE','$phase');" eval-file "/var/www/html/wp-content/oras-qbo-tests/$file"
}

auth_cookie_for_user() {
	local user_id="$1" raw
	[[ "$user_id" =~ ^[1-9][0-9]*$ ]] || fail 'authenticated test-cookie user ID is invalid.'
	raw="$(wp_env run "$TEST_SERVICE" wp eval "
		\$user_id = $user_id;
		\$expiration = time() + HOUR_IN_SECONDS;
		\$token = WP_Session_Tokens::get_instance(\$user_id)->create(\$expiration);
		echo 'ORAS_AUTH_COOKIE=' . LOGGED_IN_COOKIE . '=' . wp_generate_auth_cookie(\$user_id, \$expiration, 'logged_in', \$token);
	" 2>/dev/null | /usr/bin/grep '^ORAS_AUTH_COOKIE=' | /usr/bin/tail -1)"
	[[ "$raw" == ORAS_AUTH_COOKIE=* ]] || fail "could not create an authenticated test cookie for user $user_id."
	printf '%s' "${raw#ORAS_AUTH_COOKIE=}"
}

dispatch_probe_count() {
	local raw
	raw="$(wp_env run "$TEST_SERVICE" wp eval 'echo "ORAS_PROBE_COUNT=" . count((array) get_option("oras_registration_desk_test_dispatch_probes", array()));' 2>/dev/null | /usr/bin/grep '^ORAS_PROBE_COUNT=' | /usr/bin/tail -1)"
	[[ "$raw" == ORAS_PROBE_COUNT=* ]] || fail 'could not read the authenticated dispatcher probe count.'
	printf '%s' "${raw#ORAS_PROBE_COUNT=}"
}

run_dispatch_probe() {
	local role="$1" user_id="$2" transport="$3" expected_status="$4"
	local cookie url status body_file count
	wp_env run "$TEST_SERVICE" wp option delete oras_registration_desk_test_dispatch_probes >/dev/null 2>&1 || true
	cookie="$(auth_cookie_for_user "$user_id")"
	body_file="$($MKTEMP_BIN /tmp/oras-desk-dispatch.XXXXXX)"
	if [[ "$transport" == 'admin_ajax' ]]; then
		url="$EXPECTED_URL/wp-admin/admin-ajax.php"
		status="$($CURL_BIN --silent --show-error --max-time 45 --output "$body_file" --write-out '%{http_code}' --cookie "$cookie" --data 'action=oras_registration_desk_probe' "$url")"
	else
		url="$EXPECTED_URL/?wc-ajax=oras_registration_desk_probe"
		status="$($CURL_BIN --silent --show-error --max-time 45 --output "$body_file" --write-out '%{http_code}' --cookie "$cookie" --data '' "$url")"
	fi
	count="$(dispatch_probe_count)"
	if [[ "$role" == 'restricted' ]]; then
		[[ "$status" == "$expected_status" && "$count" == '0' ]] || fail "$transport restricted-role request reached its protected handler (HTTP $status, probe count $count)."
		/usr/bin/grep -F 'oras_desk_ajax_forbidden' "$body_file" >/dev/null || fail "$transport restricted-role denial did not come from the Registration Desk guard."
	else
		[[ "$status" == "$expected_status" && "$count" == '1' ]] || fail "$transport $role request did not retain normal dispatch behavior (HTTP $status, probe count $count)."
	fi
	"$FIND_BIN" "$body_file" -delete
}

run_http_access_probes() {
	local ids admin_id desk_id member_id
	ids="$(wp_env run "$TEST_SERVICE" wp eval '
		$context = get_option("oras_registration_desk_integration_context", array());
		echo "ORAS_PROBE_IDS=" . (int) ($context["admin_id"] ?? 0) . ":" . (int) ($context["desk_id"] ?? 0) . ":" . (int) ($context["member_id"] ?? 0);
	' 2>/dev/null | /usr/bin/grep '^ORAS_PROBE_IDS=' | /usr/bin/tail -1)"
	[[ "$ids" == ORAS_PROBE_IDS=* ]] || fail 'could not read authenticated dispatcher probe users.'
	IFS=: read -r admin_id desk_id member_id <<<"${ids#ORAS_PROBE_IDS=}"
	[[ "$admin_id" -gt 0 && "$desk_id" -gt 0 && "$member_id" -gt 0 ]] || fail 'authenticated dispatcher probe users are invalid.'

	run_dispatch_probe restricted "$desk_id" admin_ajax 403
	run_dispatch_probe restricted "$desk_id" wc_ajax 403
	run_dispatch_probe administrator "$admin_id" admin_ajax 200
	run_dispatch_probe administrator "$admin_id" wc_ajax 200
	run_dispatch_probe subscriber "$member_id" admin_ajax 200
	run_dispatch_probe subscriber "$member_id" wc_ajax 200
	wp_env run "$TEST_SERVICE" wp eval "
		foreach (array($admin_id, $desk_id, $member_id) as \$user_id) {
			WP_Session_Tokens::get_instance(\$user_id)->destroy_all();
		}
	" >/dev/null
	printf '%s\n' 'Authenticated admin-AJAX and WC-AJAX dispatcher probes passed.'
}

run_concurrency() {
	local cli_id tmp_dir status_one status_two
	cli_id="$(verify_container tests-cli)"
	tmp_dir="$($MKTEMP_BIN -d /tmp/oras-desk-concurrency.XXXXXX)"
	set +e
	docker_cmd exec "$cli_id" wp --allow-root --path=/var/www/html --exec="define('ORAS_REGISTRATION_DESK_DISPOSABLE_MARKER_EXPECTED','$DISPOSABLE_MARKER');define('ORAS_REGISTRATION_DESK_WORKER_INDEX',1);" eval-file /var/www/html/wp-content/oras-qbo-tests/registration-desk-concurrency-worker.php >"$tmp_dir/one.out" 2>"$tmp_dir/one.err" &
	local pid_one=$!
	docker_cmd exec "$cli_id" wp --allow-root --path=/var/www/html --exec="define('ORAS_REGISTRATION_DESK_DISPOSABLE_MARKER_EXPECTED','$DISPOSABLE_MARKER');define('ORAS_REGISTRATION_DESK_WORKER_INDEX',2);" eval-file /var/www/html/wp-content/oras-qbo-tests/registration-desk-concurrency-worker.php >"$tmp_dir/two.out" 2>"$tmp_dir/two.err" &
	local pid_two=$!
	wait "$pid_one"; status_one=$?
	wait "$pid_two"; status_two=$?
	set -e
	printf '%s\n' 'Concurrency worker 1:'; /usr/bin/cat "$tmp_dir/one.out" "$tmp_dir/one.err"
	printf '%s\n' 'Concurrency worker 2:'; /usr/bin/cat "$tmp_dir/two.out" "$tmp_dir/two.err"
	"$FIND_BIN" "$tmp_dir" -depth -mindepth 1 -delete
	"$RMDIR_BIN" "$tmp_dir"
	[[ "$status_one" -eq 0 && "$status_two" -eq 0 ]] || fail 'an independent concurrency worker failed.'
}

run_config_race() {
	local mode="$1" cli_id tmp_dir status_one status_two combined
	cli_id="$(verify_container tests-cli)"
	tmp_dir="$($MKTEMP_BIN -d /tmp/oras-desk-config-race.XXXXXX)"
	set +e
	docker_cmd exec "$cli_id" wp --allow-root --path=/var/www/html --exec="define('ORAS_REGISTRATION_DESK_DISPOSABLE_MARKER_EXPECTED','$DISPOSABLE_MARKER');define('ORAS_REGISTRATION_DESK_WORKER_INDEX',1);define('ORAS_REGISTRATION_DESK_CONFIG_RACE_MODE','$mode');" eval-file /var/www/html/wp-content/oras-qbo-tests/registration-desk-config-concurrency-worker.php >"$tmp_dir/one.out" 2>"$tmp_dir/one.err" &
	local pid_one=$!
	docker_cmd exec "$cli_id" wp --allow-root --path=/var/www/html --exec="define('ORAS_REGISTRATION_DESK_DISPOSABLE_MARKER_EXPECTED','$DISPOSABLE_MARKER');define('ORAS_REGISTRATION_DESK_WORKER_INDEX',2);define('ORAS_REGISTRATION_DESK_CONFIG_RACE_MODE','$mode');" eval-file /var/www/html/wp-content/oras-qbo-tests/registration-desk-config-concurrency-worker.php >"$tmp_dir/two.out" 2>"$tmp_dir/two.err" &
	local pid_two=$!
	wait "$pid_one"; status_one=$?
	wait "$pid_two"; status_two=$?
	set -e
	combined="$(/usr/bin/cat "$tmp_dir/one.out" "$tmp_dir/two.out")"
	printf 'Configuration race %s worker 1: ' "$mode"; /usr/bin/cat "$tmp_dir/one.out" "$tmp_dir/one.err"
	printf 'Configuration race %s worker 2: ' "$mode"; /usr/bin/cat "$tmp_dir/two.out" "$tmp_dir/two.err"
	[[ "$status_one" -eq 0 && "$status_two" -eq 0 ]] || fail "$mode configuration race worker failed."
	[[ "$(printf '%s' "$combined" | /usr/bin/grep -o '"result":"success"' | /usr/bin/wc -l)" -eq 1 ]] || fail "$mode configuration race did not produce exactly one winner."
	if [[ "$mode" == 'same_event' ]]; then
		printf '%s' "$combined" | /usr/bin/grep -F '"result":"oras_desk_stale_config"' >/dev/null || fail 'same-event configuration race did not reject the stale writer.'
	else
		printf '%s' "$combined" | /usr/bin/grep -F '"result":"oras_desk_active_event_changed"' >/dev/null || fail 'different-event activation race did not reject the stale active-event writer.'
		wp_env run "$TEST_SERVICE" wp eval '
			$context=get_option("oras_registration_desk_integration_context",array());
			wp_set_current_user((int)$context["admin_id"]);
			$result=ORAS\Tickets\Registration_Desk\Config::set_active_event_id((int)$context["event_id"]);
			if(is_wp_error($result)){exit(1);}
		' >/dev/null || fail 'could not restore active event after activation race.'
	fi
	"$FIND_BIN" "$tmp_dir" -depth -mindepth 1 -delete
	"$RMDIR_BIN" "$tmp_dir"
}

main() {
	verify_static_identity
	prepare_docker_config
	snapshot_development_state
	snapshot_test_state
	create_test_compose_override
	report_separate_worktree_runtime
	start_test_services
	wait_for_test_runtime
	verify_mounted_code_identity
	verify_runtime_identity
	verify_disposable_marker
	printf 'Verified disposable runtime: %s, %s@%s, %s, marker=%s\n' "$EXPECTED_PROJECT" "$EXPECTED_DATABASE" "$EXPECTED_DATABASE_HOST" "$EXPECTED_URL" "$DISPOSABLE_MARKER"
	(( VERIFY_ONLY )) && exit 0
	capture_settings
	configure_safe_integrations

	ensure_dependencies
	configure_order_storage
	run_eval_file registration-desk-integration-checks.php prepare
	run_config_race same_event
	run_config_race activation
	run_http_access_probes
	run_eval_file registration-desk-integration-checks.php baseline
	run_concurrency
	run_eval_file registration-desk-integration-checks.php finish
	run_eval_file core-regression-checks.php regression
	wp_env run "$TEST_SERVICE" wp --exec="define('ORAS_REGISTRATION_DESK_DISPOSABLE_MARKER_EXPECTED','$DISPOSABLE_MARKER');" eval-file /var/www/html/wp-content/plugins/oras-tickets/tools/bootstrap-regression-checks.php
	printf '%s\n' 'Registration Desk guarded integration checks passed.'
}

main
