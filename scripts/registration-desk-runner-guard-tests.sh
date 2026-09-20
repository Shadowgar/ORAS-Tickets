#!/bin/bash -p
set -euo pipefail

readonly ROOT_DIR="$(cd "${BASH_SOURCE[0]%/*}/.." && pwd -P)"
readonly RUNNER="$ROOT_DIR/scripts/run-registration-desk-integration-checks.sh"
readonly HARNESS="$ROOT_DIR/scripts/registration-desk-integration-checks.php"
readonly HTTP_GUARD="$ROOT_DIR/scripts/fixtures/oras-registration-desk-test-guard.php"
readonly CONCURRENCY_WORKER="$ROOT_DIR/scripts/registration-desk-concurrency-worker.php"
readonly CONFIG_WORKER="$ROOT_DIR/scripts/registration-desk-config-concurrency-worker.php"

fail() {
	printf 'FAIL: %s\n' "$1" >&2
	exit 1
}

pass() {
	printf 'PASS: %s\n' "$1"
}

require_text() {
	local needle="$1" message="$2"
	/usr/bin/grep -F -- "$needle" "$RUNNER" >/dev/null || fail "$message"
	pass "$message"
}

require_file_text() {
	local file="$1" needle="$2" message="$3"
	/usr/bin/grep -F -- "$needle" "$file" >/dev/null || fail "$message"
	pass "$message"
}

reject_text() {
	local needle="$1" message="$2"
	if /usr/bin/grep -F -- "$needle" "$RUNNER" >/dev/null; then
		fail "$message"
	fi
	pass "$message"
}

[[ -f "$RUNNER" ]] || fail 'Registration Desk runner exists.'

require_text "WP_ENV_PROJECT='/home/rocco/projects/oras-wp-env'" 'Runner pins the designated source project.'
require_text "'/home/rocco/projects/ORAS-Tickets'" 'Runner pins the owner-approved main checkout.'
require_text "owner-preserved .gitignore modification" 'Runner permits only the preserved .gitignore modification outside committed code.'
require_text 'process.chdir(project)' 'wp-env resolves configuration from the designated source project.'
require_text 'wp_env install-path' 'Runner resolves the designated generated install path through wp-env.'
require_text 'TEST_COMPOSE_OVERRIDE' 'Runner creates an explicit test-service-only Compose overlay.'
require_text 'snapshot_development_state' 'Runner snapshots ordinary development services before test mutation.'
require_text 'verify_development_state' 'Runner verifies ordinary development services remain unchanged.'
require_text 'restore_test_services' 'Runner restores designated test mounts and service state on exit.'
require_text 'verify_mounted_code_identity' 'Runner verifies mounted feature code before WordPress mutation.'
require_text '--initialize-disposable-marker' 'Marker creation requires an explicit one-time mode.'
require_text 'test_volume' 'Runner verifies disposable database storage isolation before marker handling.'
require_text 'active_plugins' 'Runner captures and restores test plugin activation state.'
require_text '--skip-plugins --skip-themes' 'Pre-marker WordPress checks skip ordinary plugins and themes.'
require_text 'oras_plugin_loaded' 'Pre-marker identity proves the ORAS plugin did not bootstrap.'
require_text 'verify_test_configuration' 'Runner verifies original test configuration is restored.'
require_text 'ORAS_SYNC=0' 'Runner verifies WooCommerce compatibility synchronization is disabled.'
require_text 'verify_restored_options' 'Runner reads back restored test options.'
require_text 'verify_local_http_url' 'Runner validates loopback or a routable private host before using a LAN test URL.'
require_text "IP_BIN='/usr/sbin/ip'" 'Runner pins the local-interface discovery executable.'
reject_text 'wp_env start' 'Runner never starts or reconfigures the ordinary development environment.'
reject_text 'legacy_hash=' 'Runner does not derive a project from the feature-worktree config path.'
reject_text '.config/superpowers/worktrees/ORAS-Tickets' 'Runner does not require an obsolete detached worktree.'
reject_text "EXPECTED_URL='http://localhost:" 'Runner does not hard-code an old test-site port.'
reject_text 'wp option add oras_registration_desk_disposable_fixture_id' 'Runner never bypasses identity checks with an unconditional marker command.'
if /usr/bin/grep -F -- 'http://localhost:8895' "$HARNESS" >/dev/null; then
	fail 'Integration harness does not hard-code the old test-site URL.'
fi
pass 'Integration harness does not hard-code the old test-site URL.'
require_text 'ORAS_REGISTRATION_DESK_TEST_URL_EXPECTED' 'Runner passes its verified dynamic URL into the integration harness.'
require_file_text "$HTTP_GUARD" "'test_scope'" 'HTTP guard tags observations with the exact test process scope.'
require_file_text "$HARNESS" 'prepare_http_baseline' 'Prepare-phase desk HTTP isolation is compared within one WordPress process.'
require_file_text "$HARNESS" 'finish_http_baseline' 'Finish-phase desk HTTP isolation is compared within one WordPress process.'
require_file_text "$CONCURRENCY_WORKER" 'worker_http_baseline' 'Each concurrency worker verifies its own desk request performs no HTTP.'
require_file_text "$CONFIG_WORKER" 'worker_http_baseline' 'Each configuration worker verifies its own desk request performs no HTTP.'
if /usr/bin/grep -F -- "'http_evidence' =>" "$HARNESS" >/dev/null || /usr/bin/grep -F -- "'http_log'      =>" "$HARNESS" >/dev/null; then
	fail 'Bounded HTTP evidence is not compared across WordPress process boundaries.'
fi
pass 'Bounded HTTP evidence is not compared across WordPress process boundaries.'

printf '%s\n' 'Registration Desk runner guard checks passed.'
