#!/usr/bin/env python3
"""Generate an automatic session snapshot from the latest Git commit.

Creates a session file under prompts/sessions/ named auto-<timestamp>-<sha>.md
with minimal metadata (Date, Author, Goal derived from commit message, Files changed).

Usage:
  python tools/generate_auto_session.py

This script is safe to run locally and in CI. It requires `git` to be available.
"""
from pathlib import Path
import subprocess
from datetime import datetime, timezone
import sys

ROOT = Path(__file__).resolve().parents[1]
SESSIONS_DIR = ROOT / 'prompts' / 'sessions'
SESSIONS_DIR.mkdir(parents=True, exist_ok=True)


def run(cmd):
    return subprocess.check_output(cmd, shell=True).decode().strip()


def main():
    try:
        sha = run('git rev-parse --short HEAD')
        author = run('git log -1 --pretty=format:%an')
        email = run('git log -1 --pretty=format:%ae')
        date = datetime.now(timezone.utc).isoformat().replace('+00:00', 'Z')
        msg = run('git log -1 --pretty=format:%s')
        files = run('git diff-tree --no-commit-id --name-only -r HEAD').splitlines()
    except Exception as e:
        print('Error reading git data:', e, file=sys.stderr)
        sys.exit(1)

    filename = f"auto-{datetime.now(timezone.utc).strftime('%Y%m%d-%H%M%S')}-{sha}.md"
    path = SESSIONS_DIR / filename

    # run quick static checks if available (phpstan, phpcs)
    checks = []
    def try_run_check(cmd, label):
        try:
            # run command, capture output and exit code
            proc = subprocess.run(cmd, shell=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)
            out = proc.stdout.strip()
            status = 'OK' if proc.returncode == 0 else f'FAIL (exit {proc.returncode})'
            # keep only first 12 lines of output for brevity
            preview = '\n'.join(out.splitlines()[:12]) if out else ''
            checks.append((label, status, preview))
        except Exception as e:
            checks.append((label, 'UNAVAILABLE', str(e)))

    # prefer composer phpstan if available
    try_run_check('composer phpstan', 'phpstan')
    # try phpcs from vendor
    try_run_check('./vendor/bin/phpcs --standard=phpcs.xml --report=summary', 'phpcs')

    lines = []
    lines.append(f"Date: {date}")
    lines.append(f"Author: {author} <{email}>")
    lines.append("")
    lines.append("Top-level intent: Auto-update MASTER_PROMPT from commit")
    lines.append("")
    lines.append("Master prompt note: generated automatically from latest commit metadata.")
    lines.append("")
    lines.append(f"Goal: {msg}")
    lines.append("")
    lines.append(f"Commit: {sha}")
    lines.append(f"Commit message: {msg}")
    lines.append("")
    lines.append("Files referenced:")
    for f in files:
        lines.append(f"- {f}")
    lines.append("")
    lines.append("Assistant reply: (auto session, no assistant reply)")
    lines.append("")
    lines.append("Quick checks summary:")
    for label, status, preview in checks:
        lines.append(f"- {label}: {status}")
        if preview:
            lines.append("  - output-preview:")
            for pl in preview.splitlines():
                lines.append(f"    {pl}")
    lines.append("")

    content = "\n".join(lines)
    path.write_text(content, encoding='utf8')
    print(str(path))


if __name__ == '__main__':
    main()
