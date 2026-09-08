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
# Exported IFS and optional loader/runtime variables are rejected outright.
# PATH is used only to locate Node, whose canonical binary and hash are pinned;
# every child process otherwise receives an explicit minimal environment.
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
		IFS)
			early_fail 'IFS overrides are not permitted.'
			;;
		COMPOSER_PROCESS_TIMEOUT)
			[[ "${COMPOSER_PROCESS_TIMEOUT:-}" == '0' ]] \
				|| early_fail 'COMPOSER_PROCESS_TIMEOUT must be the exact workflow value 0.'
			;;
		COMPOSER_NO_INTERACTION)
			[[ "${COMPOSER_NO_INTERACTION:-}" == '1' ]] \
				|| early_fail 'COMPOSER_NO_INTERACTION must be the exact workflow value 1.'
			;;
		COMPOSER_NO_AUDIT)
			[[ "${COMPOSER_NO_AUDIT:-}" == '1' ]] \
				|| early_fail 'COMPOSER_NO_AUDIT must be the exact workflow value 1.'
			;;
		GIT_PAGER)
			[[ "${GIT_PAGER:-}" == 'cat' ]] \
				|| early_fail 'GIT_PAGER must be the exact non-interactive value cat.'
			;;
		LD_*|PHP_*|COMPOSER_*|WP_CLI_*|DOCKER_*|NODE_*|NPM_CONFIG_*|npm_config_*|GIT_*)
			early_fail "$ambient_name overrides are not permitted."
			;;
	esac
done < <(compgen -e)

IFS=$' \t\n'

readonly REALPATH_BIN='/usr/bin/realpath'
readonly SHA256_BIN='/usr/bin/sha256sum'
readonly MD5_BIN='/usr/bin/md5sum'
readonly AWK_BIN='/usr/bin/awk'
readonly MKTEMP_BIN='/usr/bin/mktemp'
readonly CP_BIN='/usr/bin/cp'
readonly FIND_BIN='/usr/bin/find'
readonly RMDIR_BIN='/usr/bin/rmdir'
readonly PHP_BIN='/usr/bin/php'
readonly GIT_BIN='/usr/bin/git'
readonly GETENT_BIN='/usr/bin/getent'
readonly ID_BIN='/usr/bin/id'
readonly READLINK_BIN='/usr/bin/readlink'
readonly GREP_BIN='/usr/bin/grep'
readonly TAIL_BIN='/usr/bin/tail'
readonly WC_BIN='/usr/bin/wc'
readonly BASENAME_BIN='/usr/bin/basename'
readonly ENV_BIN='/usr/bin/env'

EXPECTED_REPOSITORY_URL='https://github.com/Shadowgar/ORAS-Tickets.git'
EXPECTED_BASELINE_COMMIT='1440cab86c1b7cb3de311764f0c28886f2da4a4b'
EXPECTED_CONFIG_SHA256='d3efa1ac7c2d0124c7cd1ba1912c36ff7db9758edeff838327eeb5026dccb393'
EXPECTED_DOCKER_CONFIG_SHA256='ca3d163bab055381827226140568f3bef7eaac187cebd76878e0b63e9e442356'
EXPECTED_HTTP_BLOCK_SHA256='ecf2c90829b4ce2bf10bc06aaf97158ce95ede66c213bcd793596ee2ee1b3eb5'
EXPECTED_PACKAGE_JSON_SHA256='0cf60445b6f2c2fd8d72374e0a5cf8021c871b536d9bff77e5b076086acc7725'
EXPECTED_PACKAGE_LOCK_SHA256='0f4880c3d1e1a39ac2e2698b232d3c8b387ac0c107ff010da7b1d52fb88159e7'
EXPECTED_WP_ENV_PACKAGE_SHA256='be31d3b4345970933b4a9f51dc6b67e788c7dc30b446492f36b8e4e9ccc59c9a'
EXPECTED_COMPOSE_NORMALIZED_SHA256='5c3a1c6cc27a279044c892c12ae18873ea23218dddb889836652d4ee79803abe'
EXPECTED_DATABASE='tests-wordpress'
EXPECTED_DATABASE_HOST='tests-mysql'
EXPECTED_HOME='http://localhost:8895'
EXPECTED_NODE_VERSION='v22.22.0'
EXPECTED_NODE_SHA256='1bec56ef7cfa9a76f3e0b7c0a87f220eb73f23102b9c0b4c7529a3f7c3ce7c31'
EXPECTED_WP_ENV_VERSION='11.6.0'
EXPECTED_WP_ENV_SHA256='c3ad55a8eb7c006a58b5133cea146e8b5afc9c755dfb09dd2d631e2bc2264ef3'
EXPECTED_WP_ENV_LINK_TARGET='../@wordpress/env/bin/wp-env'
DISPOSABLE_MARKER_OPTION='oras_qbo_disposable_fixture_id'
DISPOSABLE_MARKER_VALUE=''

RUNNER_PATH="$($REALPATH_BIN -e "${BASH_SOURCE[0]}")"
ROOT_DIR="$($REALPATH_BIN "${RUNNER_PATH%/*}/..")"
CONFIG_FILE="$ROOT_DIR/.wp-env.json"
DOCKER_CONFIG_SOURCE_DIR="$ROOT_DIR/.wp-env/docker"
DOCKER_CONFIG_FILE="$DOCKER_CONFIG_SOURCE_DIR/config.json"
DOCKER_CONFIG_DIR=''
HTTP_BLOCK_FILE="$ROOT_DIR/scripts/fixtures/oras-qbo-http-block.php"
PACKAGE_JSON_FILE="$ROOT_DIR/package.json"
PACKAGE_LOCK_FILE="$ROOT_DIR/package-lock.json"
WP_ENV_LINK="$ROOT_DIR/node_modules/.bin/wp-env"
EXPECTED_WP_ENV_REALPATH="$ROOT_DIR/node_modules/@wordpress/env/bin/wp-env"
WP_ENV_PACKAGE_FILE="$ROOT_DIR/node_modules/@wordpress/env/package.json"
WP_ENV_BIN=''
NODE_BIN=''
VERIFIED_HOME=''
VERIFIED_USERNAME=''
VERIFIED_UID=''
VERIFIED_GID=''
EXPECTED_PROJECT=''
WP_ENV_CACHE_ROOT=''
WP_ENV_DIRECTORY_NAME=''
WP_ENV_HOME_DIR=''
COMPOSE_FILE_PATH=''
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

git_cmd() {
	"$ENV_BIN" -i \
		HOME="$VERIFIED_HOME" \
		PATH='/usr/bin:/bin' \
		GIT_CONFIG_NOSYSTEM=1 \
		GIT_CONFIG_GLOBAL=/dev/null \
		"$GIT_BIN" -C "$ROOT_DIR" "$@"
}

guard_php() {
	"$ENV_BIN" -i \
		HOME="$VERIFIED_HOME" \
		PATH='/usr/local/bin:/usr/bin:/bin' \
		"$PHP_BIN" -n "$@"
}

test_php() {
	"$ENV_BIN" -i \
		HOME="$VERIFIED_HOME" \
		PATH='/usr/local/bin:/usr/bin:/bin' \
		"$PHP_BIN" "$@"
}

docker_cmd() {
	[[ -n "$DOCKER_CONFIG_DIR" ]] || fail 'isolated runtime Docker configuration is unavailable.'
	"$ENV_BIN" -i \
		HOME="$VERIFIED_HOME" \
		PATH='/usr/local/bin:/usr/bin:/bin' \
		DOCKER_CONFIG="$DOCKER_CONFIG_DIR" \
		COMPOSE_BAKE=false \
		"$DOCKER_BIN" "$@"
}

verify_account_identity() {
	local passwd_record
	local passwd_marker
	local passwd_gecos
	local passwd_shell
	local current_uid

	current_uid="$($ID_BIN -u)"
	passwd_record="$($GETENT_BIN passwd "$current_uid")"
	[[ -n "$passwd_record" && "$passwd_record" != *$'\n'* ]] \
		|| fail 'operating-system account identity could not be established.'

	local IFS=':'
	read -r VERIFIED_USERNAME passwd_marker VERIFIED_UID VERIFIED_GID passwd_gecos VERIFIED_HOME passwd_shell <<< "$passwd_record"
	unset passwd_marker passwd_gecos passwd_shell

	[[ "$VERIFIED_USERNAME" =~ ^[a-z_][a-z0-9_-]*$ \
		&& "$VERIFIED_UID" =~ ^[0-9]+$ \
		&& "$VERIFIED_GID" =~ ^[0-9]+$ \
		&& "$VERIFIED_UID" == "$current_uid" \
		&& "$VERIFIED_HOME" == /* \
		&& -d "$VERIFIED_HOME" \
		&& ! -L "$VERIFIED_HOME" \
		&& "$($REALPATH_BIN -e "$VERIFIED_HOME")" == "$VERIFIED_HOME" ]] \
		|| fail 'operating-system account home is not canonical.'
	[[ "${HOME:-}" == "$VERIFIED_HOME" ]] \
		|| fail 'HOME does not match the verified operating-system account.'
	case "$ROOT_DIR/" in
		"$VERIFIED_HOME/"*) ;;
		*) fail 'repository checkout must be inside the verified account workspace.' ;;
	esac
}

verify_repository_identity() {
	local git_root
	local origin_url

	git_root="$(git_cmd rev-parse --show-toplevel 2>/dev/null)" \
		|| fail 'runner is not inside a Git checkout.'
	[[ "$git_root" == "$ROOT_DIR" ]] \
		|| fail 'runner path is not the canonical Git top level.'
	origin_url="$(git_cmd remote get-url origin 2>/dev/null)" \
		|| fail 'canonical origin remote is unavailable.'
	case "$origin_url" in
		"$EXPECTED_REPOSITORY_URL"|"${EXPECTED_REPOSITORY_URL%.git}") ;;
		*) fail 'Git origin does not identify the canonical ORAS Tickets repository.' ;;
	esac
	git_cmd cat-file -e "$EXPECTED_BASELINE_COMMIT^{commit}" 2>/dev/null \
		|| fail 'immutable ORAS Tickets history anchor is unavailable.'
	git_cmd merge-base --is-ancestor "$EXPECTED_BASELINE_COMMIT" HEAD 2>/dev/null \
		|| fail 'checkout HEAD does not descend from the immutable ORAS Tickets history anchor.'
}

verify_workflow_overrides() {
	local physical_pwd
	physical_pwd="$(pwd -P)"

	if [[ -v ORAS_WP_ENV_DIR ]]; then
		case "$ORAS_WP_ENV_DIR" in
			.)
				[[ "$physical_pwd" == "$ROOT_DIR" ]] \
					|| fail 'ORAS_WP_ENV_DIR=. requires the canonical checkout as the working directory.'
				;;
			"$ROOT_DIR") ;;
			*) fail 'ORAS_WP_ENV_DIR must identify only the canonical checkout.' ;;
		esac
	fi
	if [[ -v ORAS_WP_ENV_CMD ]]; then
		case "$ORAS_WP_ENV_CMD" in
			./node_modules/.bin/wp-env|"$WP_ENV_LINK") ;;
			*) fail 'ORAS_WP_ENV_CMD must identify only the repository-local wp-env entry point.' ;;
		esac
	fi

	if [[ "${GITHUB_ACTIONS:-}" == 'true' ]]; then
		[[ "${CI:-}" == 'true' && "${GITHUB_WORKSPACE:-}" == "$ROOT_DIR" ]] \
			|| fail 'GitHub Actions workspace identity does not match the canonical checkout.'
	elif [[ -v GITHUB_WORKSPACE ]]; then
		fail 'GITHUB_WORKSPACE is trusted only inside GitHub Actions.'
	fi
}

verify_repository_files_and_toolchain() {
	local directory
	local wp_env_version
	local package_identity
	local node_candidate

	for directory in \
		"$ROOT_DIR/node_modules" \
		"$ROOT_DIR/node_modules/.bin" \
		"$ROOT_DIR/node_modules/@wordpress" \
		"$ROOT_DIR/node_modules/@wordpress/env" \
		"$ROOT_DIR/node_modules/@wordpress/env/bin" \
		"$DOCKER_CONFIG_SOURCE_DIR"; do
		[[ -d "$directory" && ! -L "$directory" ]] \
			|| fail "required repository directory is missing or redirected: $directory"
	done

	[[ -f "$CONFIG_FILE" && ! -L "$CONFIG_FILE" && "$(sha256_of "$CONFIG_FILE")" == "$EXPECTED_CONFIG_SHA256" ]] \
		|| fail 'repository-controlled .wp-env.json does not match its approved identity.'
	[[ ! -e "$ROOT_DIR/.wp-env.override.json" && ! -L "$ROOT_DIR/.wp-env.override.json" ]] \
		|| fail 'repository-local .wp-env overrides are not permitted.'
	[[ -f "$DOCKER_CONFIG_FILE" && ! -L "$DOCKER_CONFIG_FILE" \
		&& "$(sha256_of "$DOCKER_CONFIG_FILE")" == "$EXPECTED_DOCKER_CONFIG_SHA256" ]] \
		|| fail 'repository-controlled Docker configuration does not match its approved identity.'
	[[ -f "$HTTP_BLOCK_FILE" && ! -L "$HTTP_BLOCK_FILE" \
		&& "$(sha256_of "$HTTP_BLOCK_FILE")" == "$EXPECTED_HTTP_BLOCK_SHA256" ]] \
		|| fail 'repository-controlled Intuit HTTP blocker does not match its approved identity.'
	[[ -f "$PACKAGE_JSON_FILE" && ! -L "$PACKAGE_JSON_FILE" \
		&& "$(sha256_of "$PACKAGE_JSON_FILE")" == "$EXPECTED_PACKAGE_JSON_SHA256" ]] \
		|| fail 'repository-controlled package.json does not match its approved identity.'
	[[ -f "$PACKAGE_LOCK_FILE" && ! -L "$PACKAGE_LOCK_FILE" \
		&& "$(sha256_of "$PACKAGE_LOCK_FILE")" == "$EXPECTED_PACKAGE_LOCK_SHA256" ]] \
		|| fail 'repository-controlled package-lock.json does not match its approved identity.'

	[[ -L "$WP_ENV_LINK" && "$($READLINK_BIN "$WP_ENV_LINK")" == "$EXPECTED_WP_ENV_LINK_TARGET" ]] \
		|| fail 'repository-local wp-env link identity does not match.'
	WP_ENV_BIN="$($REALPATH_BIN -e "$WP_ENV_LINK")" \
		|| fail 'repository-local wp-env entry point cannot be resolved.'
	[[ "$WP_ENV_BIN" == "$EXPECTED_WP_ENV_REALPATH" \
		&& -f "$WP_ENV_BIN" \
		&& ! -L "$WP_ENV_BIN" \
		&& -x "$WP_ENV_BIN" \
		&& "$(sha256_of "$WP_ENV_BIN")" == "$EXPECTED_WP_ENV_SHA256" ]] \
		|| fail 'repository-local wp-env executable identity does not match.'
	[[ -f "$WP_ENV_PACKAGE_FILE" && ! -L "$WP_ENV_PACKAGE_FILE" \
		&& "$(sha256_of "$WP_ENV_PACKAGE_FILE")" == "$EXPECTED_WP_ENV_PACKAGE_SHA256" ]] \
		|| fail 'installed wp-env package identity does not match the repository lock.'

	package_identity="$(guard_php -r '
		$package = json_decode(file_get_contents($argv[1]), true);
		if (!is_array($package)) { exit(2); }
		echo ($package["name"] ?? ""), "@", ($package["version"] ?? ""), "\n";
	' "$WP_ENV_PACKAGE_FILE")" || fail 'installed wp-env package metadata is malformed.'
	[[ "$package_identity" == "@wordpress/env@$EXPECTED_WP_ENV_VERSION" ]] \
		|| fail "installed wp-env package is not version $EXPECTED_WP_ENV_VERSION."

	node_candidate="$(command -v node || true)"
	[[ -n "$node_candidate" ]] || fail 'Node executable is unavailable.'
	NODE_BIN="$($REALPATH_BIN -e "$node_candidate")" \
		|| fail 'Node executable cannot be resolved.'
	[[ -f "$NODE_BIN" && -x "$NODE_BIN" && "$(sha256_of "$NODE_BIN")" == "$EXPECTED_NODE_SHA256" ]] \
		|| fail 'Node executable content does not match the approved release.'
	[[ "$("$ENV_BIN" -i HOME="$VERIFIED_HOME" PATH='/usr/bin:/bin' "$NODE_BIN" --version)" == "$EXPECTED_NODE_VERSION" ]] \
		|| fail "Node executable is not $EXPECTED_NODE_VERSION."

	wp_env_version="$(wp_env_version)" || fail 'repository-local wp-env version check failed.'
	[[ "$wp_env_version" == "$EXPECTED_WP_ENV_VERSION" ]] \
		|| fail "wp-env version does not match $EXPECTED_WP_ENV_VERSION."
}

derive_disposable_identity() {
	local legacy_project
	local descriptive_project
	local project_directory

	legacy_project="$(printf '%s' "$CONFIG_FILE" | "$MD5_BIN" | "$AWK_BIN" '{print $1}')"
	[[ "$legacy_project" =~ ^[a-f0-9]{32}$ ]] || fail 'could not derive the exact wp-env path identity.'
	DISPOSABLE_MARKER_VALUE="oras-tickets-qbo-tests-v1-${legacy_project:0:16}"

	if [[ -e /snap ]]; then
		WP_ENV_CACHE_ROOT="$VERIFIED_HOME/wp-env"
	else
		WP_ENV_CACHE_ROOT="$VERIFIED_HOME/.wp-env"
	fi
	[[ ! -L "$WP_ENV_CACHE_ROOT" ]] || fail 'wp-env cache root may not be a symlink.'
	if [[ -e "$WP_ENV_CACHE_ROOT" ]]; then
		[[ -d "$WP_ENV_CACHE_ROOT" && "$($REALPATH_BIN -e "$WP_ENV_CACHE_ROOT")" == "$WP_ENV_CACHE_ROOT" ]] \
			|| fail 'wp-env cache root is not canonical.'
	fi

	project_directory="${ROOT_DIR##*/}"
	descriptive_project="$(guard_php -r '
		$name = preg_replace("/[^a-zA-Z0-9._]+/", "-", $argv[1]);
		$name = trim((string) $name, "-");
		if ($name === "") { exit(2); }
		echo "wp-env-", $name, "-", $argv[2], "\n";
	' "$project_directory" "${legacy_project:0:8}")" \
		|| fail 'could not derive the descriptive wp-env project identity.'

	if [[ -e "$WP_ENV_CACHE_ROOT/$legacy_project" || -L "$WP_ENV_CACHE_ROOT/$legacy_project" ]]; then
		[[ -d "$WP_ENV_CACHE_ROOT/$legacy_project" && ! -L "$WP_ENV_CACHE_ROOT/$legacy_project" ]] \
			|| fail 'legacy wp-env project path is not a canonical directory.'
		WP_ENV_DIRECTORY_NAME="$legacy_project"
	else
		WP_ENV_DIRECTORY_NAME="$descriptive_project"
	fi
	EXPECTED_PROJECT="$(guard_php -r '
		$name = strtolower($argv[1]);
		$name = preg_replace("/[^a-z0-9_-]+/", "", $name);
		$name = ltrim((string) $name, "_- ");
		if ($name === "") { exit(2); }
		echo $name, "\n";
	' "$WP_ENV_DIRECTORY_NAME")" \
		|| fail 'could not derive Docker Compose project identity from the wp-env directory.'
	[[ "$EXPECTED_PROJECT" =~ ^[a-z0-9][a-z0-9_-]*$ ]] || fail 'derived wp-env project identity is malformed.'
	WP_ENV_HOME_DIR="$WP_ENV_CACHE_ROOT/$WP_ENV_DIRECTORY_NAME"
	COMPOSE_FILE_PATH="$WP_ENV_HOME_DIR/docker-compose.yml"
	[[ ! -L "$WP_ENV_HOME_DIR" && ! -L "$COMPOSE_FILE_PATH" ]] \
		|| fail 'derived wp-env project or Compose path is redirected by a symlink.'
}

verify_static_identity() {
	verify_account_identity
	verify_repository_identity
	verify_workflow_overrides
	verify_repository_files_and_toolchain
	derive_disposable_identity
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
			HOME="$VERIFIED_HOME" \
			PATH='/usr/local/bin:/usr/bin:/bin' \
			DOCKER_CONFIG="$DOCKER_CONFIG_DIR" \
			CI=1 \
			COMPOSE_BAKE=false \
			"$NODE_BIN" "$WP_ENV_BIN" "$@"
	)
}

wp_env_version() {
	"$ENV_BIN" -i \
		HOME="$VERIFIED_HOME" \
		PATH='/usr/local/bin:/usr/bin:/bin' \
		"$NODE_BIN" "$WP_ENV_BIN" --version
}

container_id_for_service() {
	local service="$1"
	local ids
	ids="$(docker_cmd ps \
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
	inspect_json="$(docker_cmd inspect "$container_id")"

	guard_php -r '
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

	cli_json="$(docker_cmd inspect "$cli_container_id")"
	database_json="$(docker_cmd inspect "$database_container_id")"

	guard_php -r '
		$container = json_decode($argv[1], true)[0] ?? array();
		$mounts = $container["Mounts"] ?? array();
		$required = array(
			$argv[2] => "/var/www/html/wp-content/plugins/oras-tickets",
			$argv[3] => "/var/www/html/wp-content/oras-qbo-tests",
			$argv[4] => "/var/www/html/wp-content/mu-plugins/oras-qbo-http-block.php",
			$argv[5] => "/var/www/html",
			$argv[6] => "/wordpress-phpunit",
		);
		$found = array();
		foreach ($mounts as $mount) {
			$source = $mount["Source"] ?? "";
			$destination = $mount["Destination"] ?? "";
			$type = $mount["Type"] ?? "";
			if (isset($required[$source]) && $required[$source] === $destination) {
				if ($type !== "bind") {
					fwrite(STDERR, "Required repository mount is not a bind mount.\n"); exit(1);
				}
				$found[$source] = true;
			}
			if ($type === "bind" && !isset($required[$source])) {
				fwrite(STDERR, "Unexpected bind mount: {$source}\n"); exit(1);
			}
		}
		if (count($found) !== count($required)) {
			fwrite(STDERR, "Required repository mounts are missing.\n"); exit(1);
		}
		$environment = $container["Config"]["Env"] ?? array();
		if (!in_array("WORDPRESS_DB_NAME=" . $argv[7], $environment, true)
			|| !in_array("WORDPRESS_DB_HOST=" . $argv[8], $environment, true)) {
			fwrite(STDERR, "Unexpected WordPress database environment.\n"); exit(1);
		}
	' "$cli_json" "$ROOT_DIR/oras-tickets" "$ROOT_DIR/scripts" "$HTTP_BLOCK_FILE" \
		"$WP_ENV_HOME_DIR/tests-WordPress" "$WP_ENV_HOME_DIR/tests-WordPress-PHPUnit/tests/phpunit" \
		"$EXPECTED_DATABASE" "$EXPECTED_DATABASE_HOST" \
		|| fail "mounted project identity or disposable database configuration did not match."

	guard_php -r '
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

normalized_compose_sha256() {
	guard_php -r '
		$compose = file_get_contents($argv[1]);
		if ($compose === false) { exit(2); }
		$replacements = array(
			$argv[2] => "@WP_ENV_WORKDIR@",
			$argv[3] => "@ROOT@",
			$argv[4] => "@HOME@",
			"HOST_USERNAME: " . $argv[5] => "HOST_USERNAME: @USER@",
			"HOST_UID: \x27" . $argv[6] . "\x27" => "HOST_UID: \x27@UID@\x27",
			"HOST_GID: \x27" . $argv[7] . "\x27" => "HOST_GID: \x27@GID@\x27",
			"APACHE_RUN_USER: \x27#" . $argv[6] . "\x27" => "APACHE_RUN_USER: \x27#@UID@\x27",
			"APACHE_RUN_GROUP: \x27#" . $argv[7] . "\x27" => "APACHE_RUN_GROUP: \x27#@GID@\x27",
			"user: \x27" . $argv[6] . ":" . $argv[7] . "\x27" => "user: \x27@UID@:@GID@\x27",
		);
		foreach ($replacements as $from => $to) {
			$compose = str_replace($from, $to, $compose);
		}
		echo hash("sha256", $compose), "\n";
	' "$COMPOSE_FILE_PATH" "$WP_ENV_HOME_DIR" "$ROOT_DIR" "$VERIFIED_HOME" \
		"$VERIFIED_USERNAME" "$VERIFIED_UID" "$VERIFIED_GID"
}

verify_compose_identity() {
	[[ -f "$COMPOSE_FILE_PATH" && ! -L "$COMPOSE_FILE_PATH" ]] \
		|| fail 'generated Compose configuration is missing or redirected.'
	[[ "$(normalized_compose_sha256)" == "$EXPECTED_COMPOSE_NORMALIZED_SHA256" ]] \
		|| fail 'generated Compose configuration does not match its pinned normalized identity.'
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

	guard_php -r '
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

	if guard_php -r '
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
				wp_env run "$TEST_SERVICE" wp eval "
					if ( ! add_option( \"oras_qbo_disposable_fixture_id\", \"$DISPOSABLE_MARKER_VALUE\", \"\", false ) ) {
						throw new RuntimeException( \"Could not initialize disposable database marker.\" );
					}
				" >/dev/null || fail "could not initialize the repository-controlled disposable database marker."
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

	guard_php -r '
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
	assert_http_block_result "$http_block_result"
}

assert_http_block_result() {
	local http_block_result="$1"
	if [[ "$http_block_result" != 'oras_qbo_disposable_http_blocked' ]]; then
		fail 'HTTP interception fell through instead of blocking unmocked Intuit traffic.'
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

	if [[ -v ORAS_QBO_DISPOSABLE_TEST_SENTINEL ]]; then
		fail 'legacy disposable sentinel is not trusted.'
	fi

	for variable_name in \
		WP_ENV_HOME WP_ENV_PORT WP_ENV_MYSQL_PORT WP_ENV_TESTS_PORT WP_ENV_TESTS_MYSQL_PORT \
		WP_ENV_PHPMYADMIN_PORT WP_ENV_TESTS_PHPMYADMIN_PORT WP_ENV_CORE WP_ENV_PHP_VERSION \
		WP_ENV_LIFECYCLE_SCRIPT_AFTER_START WP_ENV_LIFECYCLE_SCRIPT_AFTER_CLEAN \
		WP_ENV_LIFECYCLE_SCRIPT_AFTER_RESET WP_ENV_LIFECYCLE_SCRIPT_AFTER_CLEANUP \
		WP_ENV_LIFECYCLE_SCRIPT_AFTER_DESTROY COMPOSE_FILE COMPOSE_PROJECT_NAME \
		DOCKER_HOST DOCKER_CONTEXT DOCKER_CONFIG; do
		reject_environment_override "$variable_name"
	done

	verify_static_identity

	case "${1:-}" in
		--verify-static-identity-only)
			[[ $# -eq 1 ]] || fail 'static identity mode accepts no additional arguments.'
			exit 0
			;;
		--guard-test-http-fallthrough)
			[[ $# -eq 1 ]] || fail 'HTTP guard test mode accepts no additional arguments.'
			assert_http_block_result 'network-fallthrough'
			fail 'HTTP guard fall-through test unexpectedly returned.'
			;;
		--verify-environment-only|'') ;;
		*) fail "unknown runner argument: $1" ;;
	esac
	[[ $# -le 1 ]] || fail 'runner accepts at most one argument.'

	trap cleanup_runner EXIT
	prepare_runtime_docker_config
	[[ -x "$DOCKER_BIN" ]] || fail 'fixed Docker executable is unavailable.'
	[[ "$(docker_cmd context show)" == 'default' ]] || fail 'Docker context must be default.'
	[[ "$(docker_cmd context inspect default --format '{{.Endpoints.docker.Host}}')" == 'unix:///var/run/docker.sock' ]] \
		|| fail 'Docker context must use the local Unix socket.'

	for check_file in "${CHECK_FILES[@]}"; do
		[[ -f "$check_file" ]] || fail "QBO check script is missing: $check_file"
	done
	for check_file in "${HOST_CHECK_FILES[@]}"; do
		[[ -f "$check_file" ]] || fail "QBO host check script is missing: $check_file"
	done
	[[ -f "$HPOS_CHECK_FILE" ]] || fail "QBO HPOS check script is missing: $HPOS_CHECK_FILE"

	if [[ -e "$COMPOSE_FILE_PATH" || -L "$COMPOSE_FILE_PATH" ]]; then
		verify_compose_identity
	fi

	if [[ "$(docker_cmd ps \
		--filter "label=com.docker.compose.project=$EXPECTED_PROJECT" \
		--filter 'label=com.docker.compose.service=tests-cli' \
		--format '{{.ID}}' | "$WC_BIN" -l)" -ne 1 ]]; then
		echo 'Starting the pinned repository-local disposable wp-env definition...'
		wp_env start
	fi

	verify_compose_identity

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

	RUNNER_TEST_CLEANUP_ACTIVE=1
	for check_file in "${HOST_CHECK_FILES[@]}"; do
		echo "Running $("$BASENAME_BIN" "$check_file")"
		test_php "$check_file"
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
		wp_env run "$TEST_SERVICE" wp \
			--exec="define( 'ORAS_QBO_DISPOSABLE_MARKER_EXPECTED', '$DISPOSABLE_MARKER_VALUE' );" \
			eval-file "/var/www/html/wp-content/oras-qbo-tests/$("$BASENAME_BIN" "$check_file")"
	done

	configure_hpos_for_test
	echo "Running $("$BASENAME_BIN" "$HPOS_CHECK_FILE")"
	wp_env run "$TEST_SERVICE" wp \
		--exec="define( 'ORAS_QBO_DISPOSABLE_MARKER_EXPECTED', '$DISPOSABLE_MARKER_VALUE' );" \
		eval-file "/var/www/html/wp-content/oras-qbo-tests/$("$BASENAME_BIN" "$HPOS_CHECK_FILE")"
	restore_hpos_settings

	reset_disposable_quickbooks_controls
	verify_quickbooks_safety
	RUNNER_TEST_CLEANUP_ACTIVE=0
}

main "$@"
