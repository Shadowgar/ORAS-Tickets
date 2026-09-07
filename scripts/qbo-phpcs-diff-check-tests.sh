#!/bin/bash -p
set -euo pipefail

export PATH='/usr/local/bin:/usr/bin:/bin'
IFS=$' \t\n'

readonly REALPATH_BIN='/usr/bin/realpath'
readonly SCRIPT_PATH="$($REALPATH_BIN "${BASH_SOURCE[0]}")"
readonly SOURCE_ROOT="$($REALPATH_BIN "${SCRIPT_PATH%/*}/..")"
readonly SOURCE_CHECKER="$SOURCE_ROOT/scripts/qbo-phpcs-diff-check.sh"
readonly TEST_ROOT="$(mktemp -d /tmp/oras-qbo-phpcs-tests.XXXXXX)"

CASE_INDEX=0
CASE_ROOT=''
CASE_OUTPUT=''
CASE_STATUS=0

cleanup() {
	if [[ -n "$TEST_ROOT" && -d "$TEST_ROOT" ]]; then
		chmod -R u+w "$TEST_ROOT" 2>/dev/null || true
		rm -rf -- "$TEST_ROOT"
	fi
}

trap cleanup EXIT

fail() {
	echo "FAIL: $1" >&2
	if [[ -n "$CASE_OUTPUT" ]]; then
		echo "$CASE_OUTPUT" >&2
	fi
	exit 1
}

pass() {
	echo "PASS: $1"
}

write_file() {
	local path="$1"
	local content="$2"
	mkdir -p "${path%/*}"
	printf '%s' "$content" > "$path"
}

install_fake_phpcs() {
	local target="$1/vendor/bin/phpcs"
	mkdir -p "${target%/*}"
	/usr/bin/python3 - "$target" <<'PY'
from pathlib import Path
import sys

target = Path(sys.argv[1])
target.write_text(r'''#!/usr/bin/python3
from __future__ import annotations

import json
from pathlib import Path
import sys

root = Path.cwd()
mode_path = root / ".fake-phpcs-mode"
mode = mode_path.read_text(encoding="utf-8").strip() if mode_path.exists() else "normal"

if mode == "execution-failure":
    print("fixture PHPCS execution failure", file=sys.stderr)
    raise SystemExit(7)
if mode == "malformed-json":
    print("{not-json")
    raise SystemExit(0)
if mode == "invalid-schema":
    print(json.dumps({"totals": [], "files": {}}))
    raise SystemExit(0)

paths: list[Path] = []
standards = [value.split("=", 1)[1] for value in sys.argv[1:] if value.startswith("--standard=")]
if len(standards) != 1:
    print("fixture expected exactly one explicit ruleset", file=sys.stderr)
    raise SystemExit(7)
for value in sys.argv[1:]:
    if value.startswith("-"):
        continue
    candidate = Path(value)
    if candidate.is_dir():
        paths.extend(sorted(candidate.rglob("*.php")))
    elif candidate.suffix.lower() == ".php":
        paths.append(candidate)

test_markers = ("/scripts/", "/dev-tools/", "/tests/", "/test/", "/fixtures/", "/oras-tickets/tools/")
uses_test_path = any(any(marker in path.as_posix() for marker in test_markers) for path in paths)
expected_ruleset = "phpcs-tests.xml" if uses_test_path else "phpcs.xml"
if not standards[0].endswith(expected_ruleset):
    print(f"fixture expected {expected_ruleset}, got {standards[0]}", file=sys.stderr)
    raise SystemExit(7)

files: dict[str, dict[str, object]] = {}
error_count = 0
warning_count = 0
for path in paths:
    resolved = path.resolve()
    lines = resolved.read_text(encoding="utf-8").splitlines()
    messages: list[dict[str, object]] = []
    for index, line in enumerate(lines, start=1):
        if "PHPCS_ERROR" in line:
            messages.append({
                "message": "Fixture error",
                "source": "Fixture.Rule.Error",
                "severity": 5,
                "fixable": False,
                "type": "ERROR",
                "line": index,
                "column": 1,
            })
            error_count += 1
        if "PHPCS_WARNING" in line:
            messages.append({
                "message": "Fixture warning",
                "source": "Fixture.Rule.Warning",
                "severity": 5,
                "fixable": False,
                "type": "WARNING",
                "line": index,
                "column": 1,
            })
            warning_count += 1
        if "TRIGGER_NEIGHBOR_ERROR" in line:
            messages.append({
                "message": "Neighboring-line fixture error",
                "source": "Fixture.Rule.Neighbor",
                "severity": 5,
                "fixable": False,
                "type": "ERROR",
                "line": min(index + 1, max(1, len(lines))),
                "column": 1,
            })
            error_count += 1
    if messages:
        files[str(resolved)] = {
            "errors": sum(1 for message in messages if message["type"] == "ERROR"),
            "warnings": sum(1 for message in messages if message["type"] == "WARNING"),
            "messages": messages,
        }

print(json.dumps({
    "totals": {
        "errors": error_count,
        "warnings": warning_count,
        "fixable": 0,
    },
    "files": files,
}))
if error_count:
    raise SystemExit(2)
if warning_count:
    raise SystemExit(1)
raise SystemExit(0)
''', encoding="utf-8")
target.chmod(0o755)
PY
}

new_case() {
	CASE_INDEX=$((CASE_INDEX + 1))
	CASE_ROOT="$TEST_ROOT/case-$CASE_INDEX"
	mkdir -p "$CASE_ROOT/scripts" "$CASE_ROOT/config" "$CASE_ROOT/oras-tickets"
	cp "$SOURCE_CHECKER" "$CASE_ROOT/scripts/qbo-phpcs-diff-check.sh"
	chmod 755 "$CASE_ROOT/scripts/qbo-phpcs-diff-check.sh"
	write_file "$CASE_ROOT/config/phpcs.xml" '<ruleset name="production" />'
	write_file "$CASE_ROOT/config/phpcs-tests.xml" '<ruleset name="tests" />'
	install_fake_phpcs "$CASE_ROOT"
	git -C "$CASE_ROOT" init -q
	git -C "$CASE_ROOT" config user.name 'ORAS PHPCS Fixture'
	git -C "$CASE_ROOT" config user.email 'fixture@example.invalid'
}

commit_case() {
	local message="$1"
	git -C "$CASE_ROOT" add --all
	git -C "$CASE_ROOT" commit -q -m "$message"
}

run_case() {
	set +e
	CASE_OUTPUT="$(cd "$CASE_ROOT" && bash scripts/qbo-phpcs-diff-check.sh "$@" 2>&1)"
	CASE_STATUS=$?
	set -e
}

assert_status() {
	local expected="$1"
	local label="$2"
	if [[ "$CASE_STATUS" -ne "$expected" ]]; then
		fail "$label (expected exit $expected, got $CASE_STATUS)"
	fi
}

assert_output() {
	local expected="$1"
	local label="$2"
	if [[ "$CASE_OUTPUT" != *"$expected"* ]]; then
		fail "$label (missing output: $expected)"
	fi
}

baseline_for_simple_change() {
	write_file "$CASE_ROOT/oras-tickets/plugin.php" $'<?php\n$value = "before";\n'
	commit_case 'baseline'
	BASELINE_SHA="$(git -C "$CASE_ROOT" rev-parse HEAD)"
	write_file "$CASE_ROOT/oras-tickets/plugin.php" $'<?php\n$value = "after";\n'
	commit_case 'candidate'
}

new_case
write_file "$CASE_ROOT/oras-tickets/legacy.php" $'<?php\n$value = "before"; // PHPCS_ERROR\n'
commit_case 'baseline'
BASELINE_SHA="$(git -C "$CASE_ROOT" rev-parse HEAD)"
write_file "$CASE_ROOT/oras-tickets/legacy.php" $'<?php\n$value = "after"; // PHPCS_ERROR\n'
commit_case 'candidate'
run_case --baseline "$BASELINE_SHA"
assert_status 0 'inherited diagnostics do not block'
assert_output 'Whole-plugin PHPCS debt: 1 error(s), 0 warning(s), 1 affected file(s).' 'whole-plugin debt is reported'
assert_output 'Differential PHPCS legacy diagnostics: 1' 'changed-file legacy diagnostic is reported'
assert_output 'Differential PHPCS introduced diagnostics: 0' 'changed-file legacy diagnostic is not introduced'
pass 'inherited debt reporting'

new_case
baseline_for_simple_change
write_file "$CASE_ROOT/oras-tickets/plugin.php" $'<?php\n$value = "after"; // PHPCS_ERROR\n'
commit_case 'introduce violation'
run_case --baseline "$BASELINE_SHA"
assert_status 1 'modified-file violation blocks'
assert_output 'oras-tickets/plugin.php:2:1: ERROR Fixture.Rule.Error: Fixture error' 'modified-file violation is identified'
pass 'modified-file violation'

new_case
write_file "$CASE_ROOT/oras-tickets/plugin.php" $'<?php\nfunction fixture() {\n\treturn 1;\n}\n'
commit_case 'baseline'
BASELINE_SHA="$(git -C "$CASE_ROOT" rev-parse HEAD)"
write_file "$CASE_ROOT/oras-tickets/plugin.php" $'<?php\nfunction fixture() { // TRIGGER_NEIGHBOR_ERROR\n\treturn 1;\n}\n'
commit_case 'trigger neighboring diagnostic'
run_case --baseline "$BASELINE_SHA"
assert_status 1 'neighboring-line violation blocks'
assert_output 'oras-tickets/plugin.php:3:1: ERROR Fixture.Rule.Neighbor' 'neighboring-line violation is identified'
pass 'neighboring-line diagnostic'

new_case
write_file "$CASE_ROOT/oras-tickets/old-name.php" $'<?php // PHPCS_ERROR\n// stable one\n// stable two\n// stable three\n// stable four\n$value = "before";\n'
commit_case 'baseline'
BASELINE_SHA="$(git -C "$CASE_ROOT" rev-parse HEAD)"
git -C "$CASE_ROOT" mv oras-tickets/old-name.php oras-tickets/new-name.php
write_file "$CASE_ROOT/oras-tickets/new-name.php" $'<?php // PHPCS_ERROR\n// stable one\n// stable two\n// stable three\n// stable four\n$value = "after";\n'
commit_case 'rename candidate'
run_case --baseline "$BASELINE_SHA"
assert_status 0 'renamed-file inherited diagnostic does not block'
assert_output 'Differential PHPCS legacy diagnostics: 1' 'renamed-file baseline is compared'
pass 'renamed-file comparison'

new_case
write_file "$CASE_ROOT/oras-tickets/old-name.php" $'<?php\n// stable one\n// stable two\n// stable three\n// stable four\n$value = "before";\n'
commit_case 'baseline'
BASELINE_SHA="$(git -C "$CASE_ROOT" rev-parse HEAD)"
git -C "$CASE_ROOT" mv oras-tickets/old-name.php oras-tickets/new-name.php
write_file "$CASE_ROOT/oras-tickets/new-name.php" $'<?php // PHPCS_ERROR\n// stable one\n// stable two\n// stable three\n// stable four\n$value = "after";\n'
commit_case 'rename with violation'
run_case --baseline "$BASELINE_SHA"
assert_status 1 'renamed-file introduced violation blocks'
assert_output 'oras-tickets/new-name.php:1:1: ERROR Fixture.Rule.Error' 'renamed-file violation is identified'
pass 'renamed-file introduced violation'

new_case
write_file "$CASE_ROOT/oras-tickets/plugin.php" $'<?php\n'
commit_case 'baseline'
BASELINE_SHA="$(git -C "$CASE_ROOT" rev-parse HEAD)"
write_file "$CASE_ROOT/scripts/new-test.php" $'<?php // PHPCS_ERROR\n'
commit_case 'add violating file'
run_case --baseline "$BASELINE_SHA"
assert_status 1 'added-file violation blocks'
assert_output 'scripts/new-test.php:1:1: ERROR Fixture.Rule.Error' 'added file receives full-file PHPCS'
pass 'added-file full scan'

new_case
write_file "$CASE_ROOT/oras-tickets/plugin.php" $'<?php\n'
commit_case 'baseline'
BASELINE_SHA="$(git -C "$CASE_ROOT" rev-parse HEAD)"
write_file "$CASE_ROOT/fixtures/new-fixture.php" $'<?php // PHPCS_ERROR\n'
commit_case 'add violating fixture'
run_case --baseline "$BASELINE_SHA"
assert_status 1 'added-fixture violation blocks'
assert_output 'fixtures/new-fixture.php:1:1: ERROR Fixture.Rule.Error' 'fixture receives full-file test PHPCS'
pass 'added-fixture full scan'

new_case
write_file "$CASE_ROOT/oras-tickets/plugin.php" $'<?php\n'
write_file "$CASE_ROOT/scripts/source.php" $'<?php // PHPCS_ERROR\n// copied fixture\n'
commit_case 'baseline'
BASELINE_SHA="$(git -C "$CASE_ROOT" rev-parse HEAD)"
cp "$CASE_ROOT/scripts/source.php" "$CASE_ROOT/scripts/copied.php"
commit_case 'copy violating file'
run_case --baseline "$BASELINE_SHA"
assert_status 1 'copied-file violation blocks'
assert_output 'scripts/copied.php:1:1: ERROR Fixture.Rule.Error' 'copied file receives full-file PHPCS'
pass 'copied-file full scan'

new_case
baseline_for_simple_change
run_case
assert_status 2 'missing baseline fails closed'
assert_output 'baseline' 'missing baseline explains failure'
pass 'missing baseline'

run_case --baseline "${BASELINE_SHA:0:12}"
assert_status 2 'abbreviated baseline fails closed'
assert_output 'full' 'abbreviated baseline explains immutable-ID requirement'
pass 'abbreviated baseline'

run_case --baseline '0000000000000000000000000000000000000000'
assert_status 2 'unknown baseline fails closed'
assert_output 'resolve' 'unknown baseline explains resolution failure'
pass 'unknown baseline'

HEAD_SHA="$(git -C "$CASE_ROOT" rev-parse HEAD)"
run_case --baseline "$HEAD_SHA"
assert_status 2 'HEAD baseline fails closed'
assert_output 'differ' 'HEAD baseline explains comparison failure'
pass 'HEAD baseline'

new_case
write_file "$CASE_ROOT/oras-tickets/plugin.php" $'<?php\n'
commit_case 'shared root'
git -C "$CASE_ROOT" switch -q -c unrelated
write_file "$CASE_ROOT/unrelated.txt" 'unrelated branch'
commit_case 'unrelated baseline'
UNRELATED_SHA="$(git -C "$CASE_ROOT" rev-parse HEAD)"
git -C "$CASE_ROOT" switch -q master
write_file "$CASE_ROOT/main.txt" 'candidate branch'
commit_case 'candidate head'
run_case --baseline "$UNRELATED_SHA"
assert_status 2 'non-ancestor baseline fails closed'
assert_output 'ancestor' 'non-ancestor baseline explains ancestry failure'
pass 'non-ancestor baseline'

new_case
baseline_for_simple_change
write_file "$CASE_ROOT/.fake-phpcs-mode" 'malformed-json'
commit_case 'malformed output mode'
run_case --baseline "$BASELINE_SHA"
assert_status 2 'malformed PHPCS output fails closed'
assert_output 'invalid JSON' 'malformed output explains parser failure'
pass 'malformed PHPCS output'

new_case
baseline_for_simple_change
write_file "$CASE_ROOT/.fake-phpcs-mode" 'invalid-schema'
commit_case 'invalid schema mode'
run_case --baseline "$BASELINE_SHA"
assert_status 2 'invalid PHPCS schema fails closed'
assert_output 'unsupported schema' 'invalid schema explains parser failure'
pass 'invalid PHPCS schema'

new_case
baseline_for_simple_change
write_file "$CASE_ROOT/.fake-phpcs-mode" 'execution-failure'
commit_case 'execution failure mode'
run_case --baseline "$BASELINE_SHA"
assert_status 2 'PHPCS execution failure fails closed'
assert_output 'command failed' 'execution failure is reported'
pass 'PHPCS execution failure'

echo 'QBO differential PHPCS checker tests passed.'
