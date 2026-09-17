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
readonly EXPECTED_CONFIG_SHA256='bee274860fdb8def0356c732fb0dfe898dba50ed8f2e3f654ab0e8981e0d2e1b'
readonly EXPECTED_GUARD_SHA256='6a6e40a8cf5162e2ebf26b25bd286ba9826deccbaeb4b6b470c1a1c3e884deaf'
readonly EXPECTED_PACKAGE_SHA256='0cf60445b6f2c2fd8d72374e0a5cf8021c871b536d9bff77e5b076086acc7725'
readonly EXPECTED_LOCK_SHA256='0f4880c3d1e1a39ac2e2698b232d3c8b387ac0c107ff010da7b1d52fb88159e7'
readonly EXPECTED_NODE_SHA256='1bec56ef7cfa9a76f3e0b7c0a87f220eb73f23102b9c0b4c7529a3f7c3ce7c31'
readonly EXPECTED_WP_ENV_PROJECT_PACKAGE_SHA256='89a46f20aaa6305e13175f894f6e0c8cdf6fb8eda1ef75b7442f1e8280631aba'
readonly EXPECTED_WP_ENV_PROJECT_LOCK_SHA256='ef015df748cb0c4d84a8b710ce90f0e7cbfeaaabdbee819c9fdfaee62314586f'
readonly EXPECTED_WP_ENV_PACKAGE_SHA256='7d2a733a1e5cea3960d39fd191f25bbb251f505a9ebf7a9084157338ef3d38ca'
readonly EXPECTED_WP_ENV_CLI_SHA256='b66a7b3b1d12afe2f94045103e1d5d01e1c1e81ef43794f80c5dba5e8fda0208'
readonly EXPECTED_DATABASE='tests-wordpress'
readonly EXPECTED_DATABASE_HOST='tests-mysql'
readonly EXPECTED_URL='http://localhost:8895'
readonly TEST_SERVICE='tests-cli'

readonly REALPATH_BIN='/usr/bin/realpath'
readonly SHA256_BIN='/usr/bin/sha256sum'
readonly MD5_BIN='/usr/bin/md5sum'
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

RUNNER_PATH="$($REALPATH_BIN -e "${BASH_SOURCE[0]}")"
ROOT_DIR="$($REALPATH_BIN "${RUNNER_PATH%/*}/..")"
CONFIG_FILE="$ROOT_DIR/.wp-env.json"
GUARD_FILE="$ROOT_DIR/scripts/fixtures/oras-registration-desk-test-guard.php"
COMMON_GIT_DIR="$($GIT_BIN -C "$ROOT_DIR" rev-parse --git-common-dir)"
if [[ "$COMMON_GIT_DIR" == /* ]]; then
	COMMON_GIT_DIR="$($REALPATH_BIN -e "$COMMON_GIT_DIR")"
else
	COMMON_GIT_DIR="$($REALPATH_BIN -e "$ROOT_DIR/$COMMON_GIT_DIR")"
fi
PRIMARY_CHECKOUT="$($REALPATH_BIN "${COMMON_GIT_DIR%/.git}")"
NODE_BIN="$HOME/.nvm/versions/node/v22.22.0/bin/node"
WP_ENV_PROJECT='/home/rocco/projects/oras-wp-env'
WP_ENV_CLI="$WP_ENV_PROJECT/node_modules/@wordpress/env/lib/cli.js"
DOCKER_CONFIG_DIR=''
WP_ENV_HOME_DIR=''
EXPECTED_PROJECT=''
DISPOSABLE_MARKER=''
INITIALIZE=0
VERIFY_ONLY=0

case "${1:-}" in
	'') ;;
	--initialize-disposable) INITIALIZE=1 ;;
	--verify-environment-only) VERIFY_ONLY=1 ;;
	*) fail 'supported arguments are --initialize-disposable or --verify-environment-only.' ;;
esac

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
		"$NODE_BIN" -e 'const root=process.argv[1];const cli=process.argv[2];const args=process.argv.slice(3);process.chdir(root);require(cli)().parse(args);' \
		"$ROOT_DIR" "$WP_ENV_CLI" "$@"
}

cleanup() {
	if [[ -n "$DOCKER_CONFIG_DIR" && "$DOCKER_CONFIG_DIR" == /tmp/oras-desk-docker.* && -d "$DOCKER_CONFIG_DIR" ]]; then
		"$FIND_BIN" "$DOCKER_CONFIG_DIR" -depth -mindepth 1 -delete
		"$RMDIR_BIN" "$DOCKER_CONFIG_DIR"
	fi
}
trap cleanup EXIT

verify_static_identity() {
	local origin legacy_hash project_dir
	[[ "$ROOT_DIR" == /home/*/.config/superpowers/worktrees/ORAS-Tickets/* ]] || fail 'runner is not in an isolated ORAS Tickets worktree.'
	[[ "$(git_cmd rev-parse --show-toplevel)" == "$ROOT_DIR" ]] || fail 'runner is not at the canonical worktree root.'
	origin="$(git_cmd remote get-url origin)"
	case "$origin" in
		"$EXPECTED_ORIGIN"|"${EXPECTED_ORIGIN%.git}") ;;
		*) fail 'origin does not identify the canonical ORAS Tickets repository.' ;;
	esac
	git_cmd merge-base --is-ancestor "$EXPECTED_BASE" HEAD || fail 'HEAD does not descend from the approved implementation base.'
	[[ "$(sha256_of "$CONFIG_FILE")" == "$EXPECTED_CONFIG_SHA256" ]] || fail '.wp-env.json identity changed.'
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

	legacy_hash="$(printf '%s' "$CONFIG_FILE" | "$MD5_BIN" | "$AWK_BIN" '{print $1}')"
	EXPECTED_PROJECT="$legacy_hash"
	WP_ENV_HOME_DIR="$HOME/wp-env/$legacy_hash"
	DISPOSABLE_MARKER="oras-registration-desk-m1a-${legacy_hash:0:16}"
}

prepare_docker_config() {
	DOCKER_CONFIG_DIR="$($MKTEMP_BIN -d /tmp/oras-desk-docker.XXXXXX)"
	"$CP_BIN" "$PRIMARY_CHECKOUT/.wp-env/docker/config.json" "$DOCKER_CONFIG_DIR/config.json"
	[[ "$(docker_cmd context show)" == 'default' ]] || fail 'Docker context is not the local default context.'
	[[ "$(docker_cmd context inspect default --format '{{.Endpoints.docker.Host}}')" == 'unix:///var/run/docker.sock' ]] || fail 'Docker is not using the local Unix socket.'
}

project_container_count() {
	docker_cmd ps -a --filter "label=com.docker.compose.project=$EXPECTED_PROJECT" --format '{{.ID}}' | /usr/bin/wc -l
}

assert_ports_available() {
	local conflicts
	conflicts="$(docker_cmd ps --format '{{.Names}}|{{.Label "com.docker.compose.project"}}|{{.Ports}}' \
		| /usr/bin/awk -F'|' -v expected="$EXPECTED_PROJECT" '$3 ~ /(^|[^0-9])(8894|8895)->/ && $2 != expected { print $0 }')"
	[[ -z "$conflicts" ]] || fail "ports 8894/8895 are already used by an active runtime: $conflicts"
}

verify_container() {
	local service="$1" id state project working_dir config_file
	id="$(docker_cmd ps --filter "label=com.docker.compose.project=$EXPECTED_PROJECT" --filter "label=com.docker.compose.service=$service" --format '{{.ID}}')"
	[[ -n "$id" && "$id" != *$'\n'* ]] || fail "expected exactly one running $service container."
	state="$(docker_cmd inspect "$id" --format '{{.State.Running}}')"
	project="$(docker_cmd inspect "$id" --format '{{index .Config.Labels "com.docker.compose.project"}}')"
	working_dir="$(docker_cmd inspect "$id" --format '{{index .Config.Labels "com.docker.compose.project.working_dir"}}')"
	config_file="$(docker_cmd inspect "$id" --format '{{index .Config.Labels "com.docker.compose.project.config_files"}}')"
	[[ "$state" == 'true' && "$project" == "$EXPECTED_PROJECT" && "$working_dir" == "$WP_ENV_HOME_DIR" && "$config_file" == "$WP_ENV_HOME_DIR/docker-compose.yml" ]] || fail "$service container identity is not repository-derived."
	printf '%s' "$id"
}

verify_runtime_identity() {
	local cli_id db_id identity mounts
	cli_id="$(verify_container tests-cli)"
	verify_container tests-wordpress >/dev/null
	db_id="$(verify_container tests-mysql)"
	mounts="$(docker_cmd inspect "$cli_id" --format '{{range .Mounts}}{{println .Source "=>" .Destination}}{{end}}')"
	printf '%s\n' "$mounts" | /usr/bin/grep -F "$ROOT_DIR/oras-tickets => /var/www/html/wp-content/plugins/oras-tickets" >/dev/null || fail 'plugin mount does not point at this worktree.'
	printf '%s\n' "$mounts" | /usr/bin/grep -F "$ROOT_DIR/scripts => /var/www/html/wp-content/oras-qbo-tests" >/dev/null || fail 'test-script mount does not point at this worktree.'
	printf '%s\n' "$mounts" | /usr/bin/grep -F "$GUARD_FILE => /var/www/html/wp-content/mu-plugins/oras-registration-desk-test-guard.php" >/dev/null || fail 'transport guard mount is missing.'
	[[ "$(docker_cmd inspect "$db_id" --format '{{range .Config.Env}}{{println .}}{{end}}' | /usr/bin/grep '^MYSQL_DATABASE=' | /usr/bin/cut -d= -f2-)" == "$EXPECTED_DATABASE" ]] || fail 'test database container has the wrong database.'

	identity="$(wp_env run "$TEST_SERVICE" wp eval 'global $wpdb; echo wp_json_encode(array("db"=>DB_NAME,"host"=>DB_HOST,"home"=>get_option("home"),"siteurl"=>get_option("siteurl"),"guard"=>defined("ORAS_REGISTRATION_DESK_TEST_GUARD_ACTIVE")&&ORAS_REGISTRATION_DESK_TEST_GUARD_ACTIVE));' 2>/dev/null | /usr/bin/grep -E '^\{.*\}$' | /usr/bin/tail -1)"
	"$PHP_BIN" -r '$v=json_decode($argv[1],true); if(!is_array($v)||($v["db"]??"")!==$argv[2]||($v["host"]??"")!==$argv[3]||($v["home"]??"")!==$argv[4]||($v["siteurl"]??"")!==$argv[4]||empty($v["guard"])) exit(1);' "$identity" "$EXPECTED_DATABASE" "$EXPECTED_DATABASE_HOST" "$EXPECTED_URL" || fail 'WordPress database, URL, or guard identity is unsafe.'
}

initialize_or_verify_marker() {
	local marker
	marker="$(wp_env run "$TEST_SERVICE" wp option get oras_registration_desk_disposable_fixture_id 2>/dev/null || true)"
	if (( INITIALIZE )); then
		[[ -z "$marker" ]] || fail 'initialization requires a database with no existing disposable marker.'
		wp_env run "$TEST_SERVICE" wp option add oras_registration_desk_disposable_fixture_id "$DISPOSABLE_MARKER" --autoload=no >/dev/null || fail 'could not establish disposable marker.'
		marker="$(wp_env run "$TEST_SERVICE" wp option get oras_registration_desk_disposable_fixture_id 2>/dev/null)"
	fi
	[[ "$marker" == "$DISPOSABLE_MARKER" ]] || fail 'missing or wrong disposable marker; initialize only a new isolated project with --initialize-disposable.'
}

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

main() {
	verify_static_identity
	prepare_docker_config
	assert_ports_available
	if (( INITIALIZE )); then
		[[ ! -e "$WP_ENV_HOME_DIR" && "$(project_container_count)" -eq 0 ]] || fail 'initialization is allowed only for a never-created project identity.'
	elif [[ ! -e "$WP_ENV_HOME_DIR" ]]; then
		fail 'disposable project does not exist; use --initialize-disposable after reviewing its derived identity.'
	fi

	wp_env start
	verify_runtime_identity
	initialize_or_verify_marker
	configure_safe_integrations
	printf 'Verified disposable runtime: %s, %s@%s, %s, marker=%s\n' "$EXPECTED_PROJECT" "$EXPECTED_DATABASE" "$EXPECTED_DATABASE_HOST" "$EXPECTED_URL" "$DISPOSABLE_MARKER"
	(( VERIFY_ONLY )) && exit 0

	ensure_dependencies
	run_eval_file registration-desk-integration-checks.php prepare
	run_http_access_probes
	run_eval_file registration-desk-integration-checks.php baseline
	run_concurrency
	run_eval_file registration-desk-integration-checks.php finish
	run_eval_file core-regression-checks.php regression
	wp_env run "$TEST_SERVICE" wp --exec="define('ORAS_REGISTRATION_DESK_DISPOSABLE_MARKER_EXPECTED','$DISPOSABLE_MARKER');" eval-file /var/www/html/wp-content/plugins/oras-tickets/tools/bootstrap-regression-checks.php
	printf '%s\n' 'Registration Desk guarded integration checks passed.'
}

main
