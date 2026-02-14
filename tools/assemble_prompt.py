#!/usr/bin/env python3
"""Assemble a ready-to-send prompt by combining MASTER_PROMPT.md and a session file.

Usage:
  python tools/assemble_prompt.py --session prompts/sessions/20260214-1430-waitlist.md > ready_prompt.txt

Options:
  --session <path>  Path to a session file (optional). If provided, its contents will be appended under CONTEXT.
  --master <path>   Override master prompt path (default prompts/MASTER_PROMPT.md)

This script is small and dependency-free.
"""
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MASTER_DEFAULT = ROOT / 'prompts' / 'MASTER_PROMPT.md'


def read(p: Path) -> str:
    return p.read_text(encoding='utf8')


def assemble(master_path: Path, session_path: Path | None) -> str:
    master = read(master_path)
    out = []
    out.append('# READY PROMPT (auto-assembled)')
    out.append('\n')
    out.append(master)
    out.append('\n')
    if session_path:
        out.append('\n# SESSION SNAPSHOT (appended)\n')
        out.append(read(session_path))
    return '\n'.join(out)


def main():
    import argparse
    p = argparse.ArgumentParser()
    p.add_argument('--session')
    p.add_argument('--master')
    args = p.parse_args()
    master = Path(args.master) if args.master else MASTER_DEFAULT
    session = Path(args.session) if args.session else None
    if not master.exists():
        print('Master prompt not found at', master, file=sys.stderr)
        sys.exit(2)
    if session and not session.exists():
        print('Session file not found at', session, file=sys.stderr)
        sys.exit(2)
    print(assemble(master, session))


if __name__ == '__main__':
    main()
