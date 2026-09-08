#!/bin/bash -p
set -euo pipefail

export PATH='/usr/local/bin:/usr/bin:/bin'
IFS=$' \t\n'

readonly REALPATH_BIN='/usr/bin/realpath'
readonly PYTHON_BIN='/usr/bin/python3'
readonly SCRIPT_PATH="$($REALPATH_BIN "${BASH_SOURCE[0]}")"
readonly ROOT_DIR="$($REALPATH_BIN "${SCRIPT_PATH%/*}/..")"

if [[ "$#" -ne 2 || "$1" != '--baseline' || -z "$2" ]]; then
	echo 'PHPCS gate error: provide exactly --baseline <full immutable commit ID>.' >&2
	exit 2
fi

exec "$PYTHON_BIN" - "$ROOT_DIR" "$2" <<'PY'
from __future__ import annotations

import collections
from dataclasses import dataclass
import difflib
import json
import os
from pathlib import Path, PurePosixPath
import re
import subprocess
import sys
import tempfile
from typing import Any, Iterable


class GateFailure(RuntimeError):
    """A fail-closed tooling or input error."""


@dataclass(frozen=True)
class Change:
    """One parsed Git name-status record."""

    kind: str
    old_path: str | None
    new_path: str


@dataclass(frozen=True)
class ScanTarget:
    """A materialized Git blob and its stable report identity."""

    actual_path: Path
    report_path: str
    ruleset_path: str


@dataclass
class ScanResult:
    """Validated diagnostics from one or more PHPCS invocations."""

    errors: int
    warnings: int
    affected_files: set[str]
    diagnostics: dict[str, list[dict[str, Any]]]


root = Path(sys.argv[1]).resolve(strict=True)
baseline = sys.argv[2]
git_bin = Path("/usr/bin/git")
phpcs_bin = root / "vendor/bin/phpcs"
production_ruleset = root / "config/phpcs.xml"
test_ruleset = root / "config/phpcs-tests.xml"
safe_env = {
    "HOME": "/tmp",
    "LANG": "C.UTF-8",
    "LC_ALL": "C.UTF-8",
    "PATH": "/usr/local/bin:/usr/bin:/bin",
}


def run_git(arguments: list[str], *, binary: bool = False) -> bytes | str:
    """Run Git with deterministic inputs and fail closed on errors."""
    command = [str(git_bin), *arguments]
    try:
        result = subprocess.run(
            command,
            cwd=root,
            env=safe_env,
            check=False,
            capture_output=True,
            text=not binary,
        )
    except OSError as exc:
        raise GateFailure(f"could not execute Git: {exc}") from exc
    if result.returncode != 0:
        stderr = result.stderr.decode("utf-8", "replace") if binary else result.stderr
        raise GateFailure(
            f"Git command failed ({result.returncode}): {' '.join(command)}\n{stderr.strip()}"
        )
    return result.stdout


def parse_name_status(payload: bytes) -> list[Change]:
    """Parse NUL-delimited Git name-status output without shell quoting."""
    fields = payload.split(b"\0")
    if fields and fields[-1] == b"":
        fields.pop()
    parsed: list[Change] = []
    index = 0
    while index < len(fields):
        try:
            status = fields[index].decode("ascii")
        except UnicodeDecodeError as exc:
            raise GateFailure("Git emitted a non-ASCII change status") from exc
        index += 1
        kind = status[:1]
        if kind not in {"A", "C", "D", "M", "R"}:
            raise GateFailure(f"unsupported Git change status: {status or '<empty>'}")
        if kind in {"C", "R"}:
            if index + 2 > len(fields):
                raise GateFailure(f"truncated Git name-status record: {status}")
            try:
                old_path = fields[index].decode("utf-8")
                new_path = fields[index + 1].decode("utf-8")
            except UnicodeDecodeError as exc:
                raise GateFailure("Git emitted a non-UTF-8 path") from exc
            index += 2
        else:
            if index >= len(fields):
                raise GateFailure(f"truncated Git name-status record: {status}")
            try:
                new_path = fields[index].decode("utf-8")
            except UnicodeDecodeError as exc:
                raise GateFailure("Git emitted a non-UTF-8 path") from exc
            index += 1
            old_path = new_path if kind in {"D", "M"} else None
        parsed.append(Change(kind, old_path, new_path))
    return parsed


def validate_relative_path(value: str) -> PurePosixPath:
    """Reject paths that are empty, absolute, or escape a materialization root."""
    pure = PurePosixPath(value)
    if not value or pure.is_absolute() or ".." in pure.parts or "." in pure.parts:
        raise GateFailure(f"unsafe repository path: {value!r}")
    return pure


def is_php(path: str | None) -> bool:
    return path is not None and path.lower().endswith(".php")


def is_test_path(path: str) -> bool:
    """Route repository tests, fixtures, and tooling to the explicit test standard."""
    parts = validate_relative_path(path).parts
    if not parts:
        return False
    if parts[0] in {"scripts", "dev-tools", "tests", "test", "fixtures"}:
        return True
    return len(parts) > 1 and parts[0] == "oras-tickets" and parts[1] == "tools"


def git_blob(commit: str, path: str) -> bytes:
    """Read a verified regular-file blob from a specific commit."""
    validate_relative_path(path)
    listing = run_git(["ls-tree", "-z", commit, "--", path], binary=True)
    assert isinstance(listing, bytes)
    records = [record for record in listing.split(b"\0") if record]
    if len(records) != 1 or b"\t" not in records[0]:
        raise GateFailure(f"could not resolve a unique Git blob for {commit}:{path}")
    metadata, raw_path = records[0].split(b"\t", 1)
    try:
        listed_path = raw_path.decode("utf-8")
        mode, object_type, object_id = metadata.decode("ascii").split(" ", 2)
    except (UnicodeDecodeError, ValueError) as exc:
        raise GateFailure(f"malformed Git tree entry for {commit}:{path}") from exc
    if listed_path != path:
        raise GateFailure(f"Git tree path mismatch for {commit}:{path}")
    if object_type != "blob" or mode not in {"100644", "100755"}:
        raise GateFailure(f"unsupported Git object type or mode for {commit}:{path}")
    payload = run_git(["cat-file", "blob", object_id], binary=True)
    assert isinstance(payload, bytes)
    return payload


def materialize(commit: str, path: str, destination: Path) -> tuple[Path, bytes]:
    """Materialize one committed blob beneath a disposable root."""
    pure = validate_relative_path(path)
    payload = git_blob(commit, path)
    target = destination.joinpath(*pure.parts)
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_bytes(payload)
    return target, payload


def require_integer(value: Any, label: str, *, positive: bool = False) -> int:
    """Validate a PHPCS numeric field without accepting booleans or strings."""
    if isinstance(value, bool) or not isinstance(value, int):
        raise GateFailure(f"PHPCS JSON {label} must be an integer")
    minimum = 1 if positive else 0
    if value < minimum:
        raise GateFailure(f"PHPCS JSON {label} is out of range")
    return value


def validate_phpcs_report(
    report: Any,
    targets: list[ScanTarget],
    returncode: int,
) -> ScanResult:
    """Validate PHPCS JSON structure, paths, messages, and aggregate totals."""
    if not isinstance(report, dict):
        raise GateFailure("PHPCS JSON report has an unsupported schema")
    files = report.get("files")
    totals = report.get("totals")
    if not isinstance(files, dict) or not isinstance(totals, dict):
        raise GateFailure("PHPCS JSON report has an unsupported schema")

    total_errors = require_integer(totals.get("errors"), "totals.errors")
    total_warnings = require_integer(totals.get("warnings"), "totals.warnings")
    if returncode == 0 and (total_errors or total_warnings):
        raise GateFailure("PHPCS exit status contradicts its diagnostic totals")
    if returncode in {1, 2} and not (total_errors or total_warnings):
        raise GateFailure("PHPCS diagnostic exit status has empty totals")

    expected = {target.actual_path.resolve(): target.report_path for target in targets}
    seen: set[Path] = set()
    diagnostics: dict[str, list[dict[str, Any]]] = collections.defaultdict(list)
    affected_files: set[str] = set()
    counted_errors = 0
    counted_warnings = 0

    for filename, file_report in files.items():
        if not isinstance(filename, str) or not isinstance(file_report, dict):
            raise GateFailure("PHPCS JSON file entry has an unsupported schema")
        candidate = Path(filename)
        if not candidate.is_absolute():
            candidate = root / candidate
        resolved = candidate.resolve()
        report_path = expected.get(resolved)
        if report_path is None:
            raise GateFailure(f"PHPCS reported an unexpected file: {filename}")
        if resolved in seen:
            raise GateFailure(f"PHPCS reported a duplicate file: {filename}")
        seen.add(resolved)

        file_errors = require_integer(file_report.get("errors"), f"files[{filename}].errors")
        file_warnings = require_integer(file_report.get("warnings"), f"files[{filename}].warnings")
        messages = file_report.get("messages")
        if not isinstance(messages, list):
            raise GateFailure("PHPCS JSON messages entry has an unsupported schema")

        message_errors = 0
        message_warnings = 0
        for message in messages:
            required = {"line", "column", "type", "message", "source", "severity"}
            if not isinstance(message, dict) or not required.issubset(message):
                raise GateFailure(f"PHPCS diagnostic for {report_path} has an unsupported schema")
            diagnostic_type = message["type"]
            if diagnostic_type not in {"ERROR", "WARNING"}:
                raise GateFailure(f"PHPCS diagnostic for {report_path} has an unsupported type")
            if not isinstance(message["message"], str) or not isinstance(message["source"], str):
                raise GateFailure(f"PHPCS diagnostic for {report_path} has non-text fields")
            if not message["message"] or not message["source"]:
                raise GateFailure(f"PHPCS diagnostic for {report_path} has empty fields")

            normalized = dict(message)
            normalized["line"] = require_integer(
                message["line"], f"diagnostic line for {report_path}", positive=True
            )
            normalized["column"] = require_integer(
                message["column"], f"diagnostic column for {report_path}", positive=True
            )
            normalized["severity"] = require_integer(
                message["severity"], f"diagnostic severity for {report_path}"
            )
            normalized["message"] = (
                message["message"]
                .replace(filename, report_path)
                .replace(str(resolved), report_path)
            )
            diagnostics[report_path].append(normalized)
            if diagnostic_type == "ERROR":
                message_errors += 1
            else:
                message_warnings += 1

        if message_errors != file_errors or message_warnings != file_warnings:
            raise GateFailure(f"PHPCS message counts disagree for {report_path}")
        counted_errors += file_errors
        counted_warnings += file_warnings
        if file_errors or file_warnings:
            affected_files.add(report_path)

    if counted_errors != total_errors or counted_warnings != total_warnings:
        raise GateFailure("PHPCS file counts disagree with aggregate totals")
    return ScanResult(total_errors, total_warnings, affected_files, dict(diagnostics))


def run_phpcs(targets: list[ScanTarget], ruleset: Path) -> ScanResult:
    """Run one PHPCS ruleset and parse diagnostic exits as report data."""
    if not targets:
        return ScanResult(0, 0, set(), {})
    command = [
        str(phpcs_bin),
        "-q",
        "--report=json",
        f"--standard={ruleset}",
        *[str(target.actual_path) for target in targets],
    ]
    try:
        result = subprocess.run(
            command,
            cwd=root,
            env=safe_env,
            check=False,
            capture_output=True,
            text=True,
        )
    except OSError as exc:
        raise GateFailure(f"could not execute PHPCS: {exc}") from exc
    if result.returncode not in {0, 1, 2}:
        raise GateFailure(
            f"PHPCS command failed ({result.returncode}): {result.stderr.strip()}"
        )
    if result.stderr.strip():
        raise GateFailure(f"PHPCS emitted unexpected stderr: {result.stderr.strip()}")
    try:
        report = json.loads(result.stdout)
    except json.JSONDecodeError as exc:
        raise GateFailure(f"PHPCS produced invalid JSON: {exc}") from exc
    return validate_phpcs_report(report, targets, result.returncode)


def scan_targets(targets: Iterable[ScanTarget]) -> ScanResult:
    """Apply the explicit production or test ruleset to each target group."""
    groups: dict[Path, list[ScanTarget]] = {
        production_ruleset: [],
        test_ruleset: [],
    }
    for target in targets:
        ruleset = test_ruleset if is_test_path(target.ruleset_path) else production_ruleset
        groups[ruleset].append(target)

    combined = ScanResult(0, 0, set(), {})
    for ruleset, group in groups.items():
        partial = run_phpcs(group, ruleset)
        combined.errors += partial.errors
        combined.warnings += partial.warnings
        combined.affected_files.update(partial.affected_files)
        for report_path, messages in partial.diagnostics.items():
            combined.diagnostics.setdefault(report_path, []).extend(messages)
    return combined


def line_map(old: bytes, new: bytes) -> dict[int, int]:
    """Map aligned current lines back to committed baseline lines."""
    old_lines = [line.strip() for line in old.decode("utf-8", "surrogateescape").splitlines()]
    new_lines = [line.strip() for line in new.decode("utf-8", "surrogateescape").splitlines()]
    matcher = difflib.SequenceMatcher(None, old_lines, new_lines, autojunk=False)
    mapping: dict[int, int] = {}
    for operation, old_start, old_end, new_start, new_end in matcher.get_opcodes():
        if operation == "equal":
            paired_lines = old_end - old_start
        elif operation == "replace":
            # Pair only the common positional portion of a replacement block.
            # This lets an unchanged PHPCS diagnostic remain inherited when a
            # literal changes on its line (for example, a version bump), while
            # additional replacement lines remain unmapped and fail closed.
            paired_lines = min(old_end - old_start, new_end - new_start)
        else:
            paired_lines = 0
        for offset in range(paired_lines):
            mapping[new_start + offset + 1] = old_start + offset + 1
    return mapping


def signature(message: dict[str, Any], line: int) -> tuple[Any, ...]:
    """Identify one diagnostic while retaining its occurrence count."""
    return (
        line,
        message["column"],
        message["type"],
        message["severity"],
        message["source"],
        message["message"],
    )


def validate_baseline() -> str:
    """Resolve a full immutable commit and require it to be a proper ancestor."""
    if not re.fullmatch(r"(?:[0-9a-f]{40}|[0-9a-f]{64})", baseline):
        raise GateFailure("baseline must be a full lowercase immutable commit ID")
    try:
        resolved = run_git(["rev-parse", "--verify", "--end-of-options", f"{baseline}^{{commit}}"])
    except GateFailure as exc:
        raise GateFailure(f"could not resolve baseline commit {baseline}") from exc
    assert isinstance(resolved, str)
    resolved = resolved.strip()
    if resolved != baseline:
        raise GateFailure("baseline did not resolve to the exact supplied commit ID")

    head_output = run_git(["rev-parse", "--verify", "HEAD^{commit}"])
    assert isinstance(head_output, str)
    head = head_output.strip()
    if baseline == head:
        raise GateFailure("baseline and committed HEAD must differ")

    try:
        result = subprocess.run(
            [str(git_bin), "merge-base", "--is-ancestor", baseline, head],
            cwd=root,
            env=safe_env,
            check=False,
            capture_output=True,
            text=True,
        )
    except OSError as exc:
        raise GateFailure(f"could not validate baseline ancestry: {exc}") from exc
    if result.returncode == 1:
        raise GateFailure("baseline must be an ancestor of committed HEAD")
    if result.returncode != 0:
        raise GateFailure(
            f"Git ancestry check failed ({result.returncode}): {result.stderr.strip()}"
        )
    return head


try:
    if not git_bin.is_file() or not os.access(git_bin, os.X_OK):
        raise GateFailure("fixed Git executable is unavailable")
    if not phpcs_bin.is_file() or not os.access(phpcs_bin, os.X_OK):
        raise GateFailure("vendor/bin/phpcs is unavailable or not executable")
    if not production_ruleset.is_file():
        raise GateFailure("config/phpcs.xml is unavailable")
    if not test_ruleset.is_file():
        raise GateFailure("config/phpcs-tests.xml is unavailable")

    head = validate_baseline()

    dirty_php = run_git(
        ["status", "--porcelain=v1", "-z", "--untracked-files=all", "--", "*.php"],
        binary=True,
    )
    assert isinstance(dirty_php, bytes)
    if dirty_php:
        raise GateFailure("dirty PHP inputs are not allowed; compare committed Git trees only")

    raw_changes = run_git(
        [
            "diff",
            "--name-status",
            "-z",
            "--find-renames",
            "--find-copies-harder",
            baseline,
            head,
            "--",
            "*.php",
        ],
        binary=True,
    )
    assert isinstance(raw_changes, bytes)
    changes = parse_name_status(raw_changes)
    for change in changes:
        validate_relative_path(change.new_path)
        if change.old_path is not None:
            validate_relative_path(change.old_path)

    with tempfile.TemporaryDirectory(prefix="oras-qbo-phpcs-") as temp_name:
        temp_root = Path(temp_name)
        baseline_root = temp_root / "baseline"
        candidate_root = temp_root / "candidate"
        whole_root = temp_root / "whole-plugin"

        old_targets: list[ScanTarget] = []
        current_targets: list[ScanTarget] = []
        old_payloads: dict[str, bytes] = {}
        current_payloads: dict[str, bytes] = {}
        current_kind: dict[str, str] = {}

        for change in changes:
            if change.kind == "D" or not is_php(change.new_path):
                continue
            current_target, current_payload = materialize(head, change.new_path, candidate_root)
            current_targets.append(ScanTarget(current_target, change.new_path, change.new_path))
            current_payloads[change.new_path] = current_payload
            current_kind[change.new_path] = change.kind

            if change.kind in {"M", "R"} and is_php(change.old_path):
                assert change.old_path is not None
                old_target, old_payload = materialize(baseline, change.old_path, baseline_root)
                # A rename uses the current path as its stable diagnostic identity.
                old_targets.append(ScanTarget(old_target, change.new_path, change.old_path))
                old_payloads[change.new_path] = old_payload

        plugin_listing = run_git(
            ["ls-tree", "-r", "-z", "--name-only", head, "--", "oras-tickets"],
            binary=True,
        )
        assert isinstance(plugin_listing, bytes)
        whole_targets: list[ScanTarget] = []
        for raw_path in plugin_listing.split(b"\0"):
            if not raw_path:
                continue
            try:
                plugin_path = raw_path.decode("utf-8")
            except UnicodeDecodeError as exc:
                raise GateFailure("Git emitted a non-UTF-8 plugin path") from exc
            if not is_php(plugin_path):
                continue
            whole_target, _ = materialize(head, plugin_path, whole_root)
            whole_targets.append(ScanTarget(whole_target, plugin_path, plugin_path))

        whole_report = scan_targets(whole_targets)
        old_report = scan_targets(old_targets)
        current_report = scan_targets(current_targets)

        legacy_count = 0
        introduced: list[tuple[str, dict[str, Any]]] = []
        for path, messages in current_report.diagnostics.items():
            kind = current_kind[path]
            if kind not in {"M", "R"} or path not in old_payloads:
                introduced.extend((path, message) for message in messages)
                continue

            mapping = line_map(old_payloads[path], current_payloads[path])
            old_signatures = collections.Counter(
                signature(message, message["line"])
                for message in old_report.diagnostics.get(path, [])
            )
            for message in messages:
                old_line = mapping.get(message["line"])
                candidate_signature = signature(message, old_line) if old_line is not None else None
                if candidate_signature is not None and old_signatures[candidate_signature] > 0:
                    old_signatures[candidate_signature] -= 1
                    legacy_count += 1
                else:
                    introduced.append((path, message))

        counts = collections.Counter(change.kind for change in changes)
        print(f"PHPCS baseline: {baseline}")
        print(f"PHPCS candidate: {head}")
        print(
            "Committed PHP changes: "
            f"{len(changes)} total "
            f"(A={counts['A']}, C={counts['C']}, D={counts['D']}, "
            f"M={counts['M']}, R={counts['R']})."
        )
        print(
            "Whole-plugin PHPCS debt: "
            f"{whole_report.errors} error(s), {whole_report.warnings} warning(s), "
            f"{len(whole_report.affected_files)} affected file(s)."
        )
        print(f"Differential PHPCS legacy diagnostics: {legacy_count}")
        print(f"Differential PHPCS introduced diagnostics: {len(introduced)}")
        for path, message in sorted(
            introduced,
            key=lambda item: (
                item[0],
                item[1]["line"],
                item[1]["column"],
                item[1]["source"],
                item[1]["message"],
            ),
        ):
            print(
                f"{path}:{message['line']}:{message['column']}: "
                f"{message['type']} {message['source']}: {message['message']}"
            )

        if introduced:
            raise SystemExit(1)
except GateFailure as exc:
    print(f"PHPCS gate error: {exc}", file=sys.stderr)
    raise SystemExit(2)
PY
