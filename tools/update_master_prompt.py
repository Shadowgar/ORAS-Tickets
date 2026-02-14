#!/usr/bin/env python3
"""Update MASTER_PROMPT.md by merging a session snapshot.

Usage:
  python tools/update_master_prompt.py prompts/sessions/20260214-1430-waitlist.md

Behavior:
- Finds or creates a "## Recent Sessions" section in prompts/MASTER_PROMPT.md
- Prepends a compact session summary (date, author, goal, top bullets) to that section
- Keeps only the N most recent sessions (default 6)

This is intentionally small and dependency-free.
"""
import sys
from pathlib import Path
import re

MAX_SESSIONS = 6
ROOT = Path(__file__).resolve().parents[1]
MASTER_PATH = ROOT / 'prompts' / 'MASTER_PROMPT.md'


def extract_summary(session_text: str) -> str:
    # Try to extract Date, Author, Goal, Top bullets
    lines = session_text.strip().splitlines()
    date = None
    author = None
    goal = None
    bullets = []
    for i, l in enumerate(lines[:60]):
        low = l.lower()
        if low.startswith('date:'):
            date = l.split(':', 1)[1].strip()
        if low.startswith('author:'):
            author = l.split(':', 1)[1].strip()
        if low.startswith('goal:') or low.startswith('top-level intent:'):
            goal = l.split(':', 1)[1].strip()
        if l.strip().startswith('- '):
            bullets.append(l.strip())
        if len(bullets) >= 6:
            break
    summary_lines = ["### Session Snapshot"]
    if date:
        summary_lines.append(f"- Date: {date}")
    if author:
        summary_lines.append(f"- Author: {author}")
    if goal:
        summary_lines.append(f"- Goal: {goal}")
    if bullets:
        summary_lines.append("- Notes:")
        for b in bullets[:6]:
            summary_lines.append(f"  - {b[2:] if b.startswith('- ') else b}")
    # include link to session file path placeholder
    return "\n".join(summary_lines) + "\n"


def ensure_recent_section(master_text: str) -> (str, int, int):
    # Return (new_master_text, insert_pos_start, insert_pos_end)
    m = re.search(r'(^## Recent Sessions\s*$)', master_text, flags=re.M)
    if m:
        # find section start
        start = m.start(1)
        # find next top-level header after this
        nxt = re.search(r'^##\s', master_text[m.end(1):], flags=re.M)
        if nxt:
            end = m.end(1) + nxt.start()
        else:
            end = len(master_text)
        return master_text, start, end
    else:
        # append section at end
        if not master_text.endswith('\n'):
            master_text += '\n'
        insert = '\n## Recent Sessions\n\n'
        master_text += insert
        start = master_text.rfind('## Recent Sessions')
        end = len(master_text)
        return master_text, start, end


def read_file(p: Path) -> str:
    return p.read_text(encoding='utf8')


def write_file(p: Path, s: str) -> None:
    p.write_text(s, encoding='utf8')


def main():
    if len(sys.argv) < 2:
        print('Usage: update_master_prompt.py <session-file.md>')
        sys.exit(2)
    session_path = Path(sys.argv[1])
    if not session_path.exists():
        print('Session file not found:', session_path)
        sys.exit(2)
    session_text = read_file(session_path)
    summary = extract_summary(session_text)

    if not MASTER_PATH.exists():
        print('MASTER_PROMPT.md not found at', MASTER_PATH)
        sys.exit(2)
    master_text = read_file(MASTER_PATH)
    master_text, start, end = ensure_recent_section(master_text)
    # build current recent content
    recent_content = master_text[start:end]
    # insert new summary at top of recent_content
    new_recent = '## Recent Sessions\n\n' + summary + '\n' + recent_content.replace('## Recent Sessions\n\n','')
    # Keep only first MAX_SESSIONS occurrences of '### Session Snapshot'
    parts = new_recent.split('### Session Snapshot')
    # first element is header text
    header = parts[0]
    snapshots = parts[1:]
    trimmed = snapshots[:MAX_SESSIONS]
    rebuilt = header + ''.join('### Session Snapshot' + s for s in trimmed)
    # replace in master_text
    new_master = master_text[:start] + rebuilt + master_text[end:]
    write_file(MASTER_PATH, new_master)
    print('MASTER_PROMPT.md updated with session summary from', session_path.name)


if __name__ == '__main__':
    main()
