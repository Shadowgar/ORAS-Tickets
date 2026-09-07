#!/bin/bash -p
set -euo pipefail

export PATH='/usr/local/bin:/usr/bin:/bin'
IFS=$' \t\n'

readonly REALPATH_BIN='/usr/bin/realpath'
readonly PYTHON_BIN='/usr/bin/python3'
readonly SCRIPT_PATH="$($REALPATH_BIN "${BASH_SOURCE[0]}")"
readonly ROOT_DIR="$($REALPATH_BIN "${SCRIPT_PATH%/*}/..")"

exec "$PYTHON_BIN" - "$ROOT_DIR" <<'PY'
from __future__ import annotations

import collections
import difflib
import json
import os
from pathlib import Path, PurePosixPath
import subprocess
import sys
import tempfile
from typing import Any


class GateFailure(RuntimeError):
    """A fail-closed tooling or input error."""


root = Path(sys.argv[1]).resolve(strict=True)
git_bin = Path("/usr/bin/git")
phpcs_bin = root / "vendor/bin/phpcs"
ruleset = root / "config/phpcs.xml"
safe_env = {
    "HOME": os.environ.get("HOME", "/tmp"),
    "LANG": os.environ.get("LANG", "C.UTF-8"),
    "LC_ALL": "C.UTF-8",
    "PATH": "/usr/local/bin:/usr/bin:/bin",
}


def run(command: list[str], *, binary: bool = False) -> bytes | str:
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
        raise GateFailure(f"could not execute {command[0]}: {exc}") from exc
    if result.returncode != 0:
        stderr = result.stderr.decode("utf-8", "replace") if binary else result.stderr
        raise GateFailure(
            f"command failed ({result.returncode}): {' '.join(command)}\n{stderr.strip()}"
        )
    return result.stdout


def parse_name_status(payload: bytes) -> list[tuple[str, str | None, str]]:
    fields = payload.split(b"\0")
    if fields and fields[-1] == b"":
        fields.pop()
    parsed: list[tuple[str, str | None, str]] = []
    index = 0
    while index < len(fields):
        try:
            status = fields[index].decode("ascii")
        except UnicodeDecodeError as exc:
            raise GateFailure("git emitted a non-ASCII change status") from exc
        index += 1
        kind = status[:1]
        if kind not in {"A", "C", "D", "M", "R"}:
            raise GateFailure(f"unsupported git change status: {status or '<empty>'}")
        path_count = 2 if kind in {"C", "R"} else 1
        if index + path_count > len(fields):
            raise GateFailure(f"truncated git name-status record: {status}")
        try:
            paths = [fields[index + offset].decode("utf-8") for offset in range(path_count)]
        except UnicodeDecodeError as exc:
            raise GateFailure("git emitted a non-UTF-8 path") from exc
        index += path_count
        if kind in {"C", "R"}:
            parsed.append((kind, paths[0], paths[1]))
        else:
            parsed.append((kind, paths[0] if kind in {"D", "M"} else None, paths[0]))
    return parsed


def validate_relative_path(value: str) -> Path:
    pure = PurePosixPath(value)
    if pure.is_absolute() or ".." in pure.parts or value == "":
        raise GateFailure(f"unsafe changed path: {value!r}")
    candidate = root.joinpath(*pure.parts)
    try:
        candidate.relative_to(root)
    except ValueError as exc:
        raise GateFailure(f"changed path escapes repository: {value!r}") from exc
    return candidate


def is_php(path: str | None) -> bool:
    return path is not None and path.lower().endswith(".php")


def git_blob(path: str) -> bytes:
    validate_relative_path(path)
    command = [str(git_bin), "show", f"HEAD:{path}"]
    try:
        result = subprocess.run(
            command,
            cwd=root,
            env=safe_env,
            check=False,
            capture_output=True,
        )
    except OSError as exc:
        raise GateFailure(f"could not read HEAD version of {path}: {exc}") from exc
    if result.returncode != 0:
        raise GateFailure(
            f"could not read HEAD version of {path}: "
            + result.stderr.decode("utf-8", "replace").strip()
        )
    return result.stdout


def run_phpcs(paths: list[Path]) -> dict[str, Any]:
    if not paths:
        return {"files": {}, "totals": {"errors": 0, "warnings": 0}}
    command = [
        str(phpcs_bin),
        "-q",
        "--report=json",
        f"--standard={ruleset}",
        *[str(path) for path in paths],
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
    try:
        report = json.loads(result.stdout)
    except json.JSONDecodeError as exc:
        raise GateFailure(f"PHPCS produced invalid JSON: {exc}") from exc
    if not isinstance(report, dict) or not isinstance(report.get("files"), dict):
        raise GateFailure("PHPCS JSON report has an unsupported schema")
    for filename, file_report in report["files"].items():
        if not isinstance(filename, str) or not isinstance(file_report, dict):
            raise GateFailure("PHPCS JSON file entry has an unsupported schema")
        if not isinstance(file_report.get("messages", []), list):
            raise GateFailure("PHPCS JSON messages entry has an unsupported schema")
    return report


def diagnostics(report: dict[str, Any], path_map: dict[Path, str]) -> dict[str, list[dict[str, Any]]]:
    result: dict[str, list[dict[str, Any]]] = collections.defaultdict(list)
    resolved_map = {path.resolve(): relative for path, relative in path_map.items()}
    for filename, file_report in report["files"].items():
        resolved = Path(filename).resolve()
        relative = resolved_map.get(resolved)
        if relative is None:
            raise GateFailure(f"PHPCS reported an unexpected file: {filename}")
        for message in file_report.get("messages", []):
            required = {"line", "column", "type", "message", "source", "severity"}
            if not isinstance(message, dict) or not required.issubset(message):
                raise GateFailure(f"PHPCS diagnostic for {relative} has an unsupported schema")
            normalized = dict(message)
            normalized["line"] = int(normalized["line"])
            normalized["column"] = int(normalized["column"])
            normalized["severity"] = int(normalized["severity"])
            normalized["message"] = str(normalized["message"]).replace(filename, relative)
            result[relative].append(normalized)
    return result


def line_map(old: bytes, new: bytes) -> dict[int, int]:
    # Whitespace-only formatting must not relabel a pre-existing diagnostic as
    # introduced. Non-whitespace content, diagnostic identity, and occurrence
    # count must still match exactly.
    old_lines = [line.strip() for line in old.decode("utf-8", "surrogateescape").splitlines()]
    new_lines = [line.strip() for line in new.decode("utf-8", "surrogateescape").splitlines()]
    matcher = difflib.SequenceMatcher(None, old_lines, new_lines, autojunk=False)
    mapping: dict[int, int] = {}
    for old_start, new_start, size in matcher.get_matching_blocks():
        for offset in range(size):
            mapping[new_start + offset + 1] = old_start + offset + 1
    return mapping


def signature(message: dict[str, Any], line: int) -> tuple[Any, ...]:
    return (
        line,
        message["column"],
        message["type"],
        message["severity"],
        message["source"],
        message["message"],
    )


try:
    if not git_bin.is_file() or not os.access(git_bin, os.X_OK):
        raise GateFailure("fixed git executable is unavailable")
    if not phpcs_bin.is_file() or not os.access(phpcs_bin, os.X_OK):
        raise GateFailure("vendor/bin/phpcs is unavailable or not executable")
    if not ruleset.is_file():
        raise GateFailure("config/phpcs.xml is unavailable")

    name_status_raw = run(
        [
            str(git_bin),
            "diff",
            "--name-status",
            "-z",
            "--find-renames",
            "--find-copies-harder",
            "HEAD",
            "--",
        ],
        binary=True,
    )
    assert isinstance(name_status_raw, bytes)
    changes = parse_name_status(name_status_raw)

    untracked_raw = run(
        [str(git_bin), "ls-files", "--others", "--exclude-standard", "-z", "--", "*.php"],
        binary=True,
    )
    assert isinstance(untracked_raw, bytes)
    untracked: list[str] = []
    for raw_path in untracked_raw.split(b"\0"):
        if not raw_path:
            continue
        try:
            untracked.append(raw_path.decode("utf-8"))
        except UnicodeDecodeError as exc:
            raise GateFailure("git emitted a non-UTF-8 untracked path") from exc

    current_kind: dict[str, str] = {}
    old_path_for: dict[str, str] = {}
    for kind, old_path, new_path in changes:
        validate_relative_path(new_path)
        if old_path is not None:
            validate_relative_path(old_path)
        if not is_php(new_path):
            continue
        if kind == "D":
            continue
        current_kind[new_path] = kind
        if kind in {"M", "R"} and is_php(old_path):
            old_path_for[new_path] = str(old_path)
    for path in untracked:
        validate_relative_path(path)
        current_kind[path] = "?"

    current_paths: dict[Path, str] = {}
    current_bytes: dict[str, bytes] = {}
    for relative in sorted(current_kind):
        candidate = validate_relative_path(relative)
        if candidate.is_symlink() or not candidate.is_file():
            raise GateFailure(f"changed PHP path is not a regular file: {relative}")
        current_paths[candidate] = relative
        current_bytes[relative] = candidate.read_bytes()

    if not current_paths:
        print("Differential PHPCS: no added, copied, renamed, modified, or untracked PHP files.")
        raise SystemExit(0)

    with tempfile.TemporaryDirectory(prefix="oras-qbo-phpcs-head-", dir="/tmp") as temp_name:
        temp_root = Path(temp_name)
        old_paths: dict[Path, str] = {}
        old_bytes: dict[str, bytes] = {}
        for relative, old_relative in sorted(old_path_for.items()):
            content = git_blob(old_relative)
            destination = temp_root / relative
            destination.parent.mkdir(parents=True, exist_ok=True)
            destination.write_bytes(content)
            old_paths[destination] = relative
            old_bytes[relative] = content

        current_report = run_phpcs(list(current_paths))
        old_report = run_phpcs(list(old_paths))
        current_diagnostics = diagnostics(current_report, current_paths)
        old_diagnostics = diagnostics(old_report, old_paths)

        introduced: list[tuple[str, dict[str, Any]]] = []
        legacy: list[tuple[str, dict[str, Any]]] = []
        for relative in sorted(current_paths.values()):
            messages = current_diagnostics.get(relative, [])
            if relative not in old_path_for:
                introduced.extend((relative, message) for message in messages)
                continue

            mapping = line_map(old_bytes[relative], current_bytes[relative])
            old_counter = collections.Counter(
                signature(message, int(message["line"]))
                for message in old_diagnostics.get(relative, [])
            )
            for message in messages:
                old_line = mapping.get(int(message["line"]))
                key = signature(message, old_line or -1)
                if (
                    old_line is not None
                    and old_counter[key] > 0
                ):
                    old_counter[key] -= 1
                    legacy.append((relative, message))
                else:
                    introduced.append((relative, message))

    for relative, message in introduced:
        print(
            f"{relative}:{message['line']}:{message['column']}: "
            f"{message['type']} {message['source']}: {message['message']}"
        )
    print(f"Differential PHPCS legacy diagnostics: {len(legacy)}")
    print(f"Differential PHPCS introduced diagnostics: {len(introduced)}")
    raise SystemExit(1 if introduced else 0)
except GateFailure as exc:
    print(f"Differential PHPCS failed closed: {exc}", file=sys.stderr)
    raise SystemExit(2)
PY
