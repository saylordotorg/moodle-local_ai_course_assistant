#!/usr/bin/env python3
"""Apply a batch of translations to the SOLA lang files.

Maintainer tooling for the i18n step of the release checklist. Excluded from the
plugin zip along with the rest of scripts/.

Usage:
    python3 scripts/apply_i18n.py <plugin-root> <batch.json>

The batch file is {locale: {lang_key: translation}}. For each key it replaces the
existing $string[...] line in that locale, or appends it under a batch comment if
the key is absent. Idempotent, so a re-run is safe.

Written for the 6.9.5 batch (8 missing keys plus 45 stale rerank_candidates_desc
translations across 45 locales). Two lessons from that run are worth repeating:
pass the translations as JSON via this script rather than through a shell heredoc,
because shell quoting mangles apostrophes and non-Latin scripts; and grep the
result for words from the wrong language before committing, since a copy-paste
between locale blocks is invisible in a diff of 400 strings.
"""
import json
import pathlib
import re
import sys

ROOT = pathlib.Path(sys.argv[1])
DATA = json.loads(pathlib.Path(sys.argv[2]).read_text())
BATCH_HEADER = "// i18n batch appended by scripts/apply_i18n.py."


def php_quote(value: str) -> str:
    """Single-quoted PHP string body: escape backslash then apostrophe."""
    return value.replace("\\", "\\\\").replace("'", "\\'")


total_replaced = 0
total_appended = 0
for lang, entries in DATA.items():
    path = ROOT / "lang" / lang / "local_ai_course_assistant.php"
    if not path.exists():
        print(f"  !! missing locale file: {lang}")
        continue
    src = path.read_text()
    appended = []
    for key, value in entries.items():
        if not value.strip():
            print(f"  !! skipping empty value for {lang}/{key}")
            continue
        line = f"$string['{key}'] = '{php_quote(value)}';"
        pattern = re.compile(
            r"^\$string\['" + re.escape(key) + r"'\]\s*=\s*'.*?';\s*$",
            re.M | re.S,
        )
        if pattern.search(src):
            src = pattern.sub(lambda _m: line, src, count=1)
            total_replaced += 1
        else:
            appended.append(line)
            total_appended += 1
    if appended:
        block = "\n" + ("" if BATCH_HEADER in src else BATCH_HEADER + "\n") + "\n".join(appended) + "\n"
        src = src.rstrip("\n") + "\n" + block
    path.write_text(src)
    print(f"  {lang}: {len(entries)} strings applied")

print(f"replaced={total_replaced} appended={total_appended}")
