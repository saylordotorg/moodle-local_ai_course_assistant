#!/usr/bin/env python3
"""Create a release notes + walkthrough draft for a new SOLA version.

Writes `.drafts/v<VERSION>-release-notes-and-walkthrough.md` seeded from a
template so every release ships with notes, a feature summary, and a
20-minute admin walkthrough script in the same shape.

Usage (from repo root):
    python3 scripts/new_release_notes.py --version 3.9.6 --date "21 April 2026"
    python3 scripts/new_release_notes.py --version 3.9.6 --overwrite

The resulting file is a starting point. Edit the PART 1 headline, the
bullet list of changes, and update any drifted feature or walkthrough
sections before tagging the release.
"""
import argparse
import datetime as dt
import pathlib
import re
import sys


TEMPLATE = """# SOLA v{version}: Release Notes, Feature Summary, and 20-minute Admin Walkthrough

## Part 1: Release Notes ({date})

<!-- Write a single-sentence headline describing the theme of this release, then
     a bullet list of the user-visible changes. Mirror the style of the
     previous release file in `.drafts/`. -->

TODO: headline.

- TODO: change 1
- TODO: change 2
- TODO: change 3

### Testing

- Jailbreak suite X of 25 pass.
- PHP lint clean on every modified file.
- Deployed and verified on dev.sylr.org, dev405.sylr.org, dev500.sylr.org (Moodle 5.0), and dev501.sylr.org (Moodle 5.1 with the `public/` layout).

Plugin version `{versioncode}`, release `{version}`.

---

## Part 2: Key SOLA Features

<!-- Copy-paste the PART 2 section from the previous release's draft and
     update any lines that drifted. Keep this section parallel across releases
     so the walkthrough script stays consistent. -->

TODO: carry forward Part 2 from the prior draft.

---

## Part 3: 20-minute Admin Walkthrough Script (conversational)

<!-- Copy-paste the PART 3 walkthrough from the previous release's draft and
     update any step that changed this release (new settings, renamed fields,
     deprecated flows). Keep total target time at 20 minutes. -->

TODO: carry forward Part 3 from the prior draft.
"""


def derive_versioncode(repo_root: pathlib.Path) -> str:
    """Pull $plugin->version from version.php for the template stub."""
    vph = repo_root / "version.php"
    if not vph.exists():
        return "20260101XX"
    m = re.search(r"\$plugin->version\s*=\s*(\d+)", vph.read_text())
    return m.group(1) if m else "20260101XX"


def main() -> int:
    parser = argparse.ArgumentParser(description="Seed a SOLA release notes draft.")
    parser.add_argument("--version", required=True,
                        help="Release number, e.g. 3.9.6 (without the v prefix).")
    parser.add_argument("--date",
                        help="Release date (e.g. '21 April 2026'). Defaults to today.")
    parser.add_argument("--overwrite", action="store_true",
                        help="Overwrite the draft file if it already exists.")
    args = parser.parse_args()

    repo_root = pathlib.Path(__file__).resolve().parent.parent
    drafts_dir = repo_root / ".drafts"
    drafts_dir.mkdir(exist_ok=True)

    out = drafts_dir / f"v{args.version}-release-notes-and-walkthrough.md"
    if out.exists() and not args.overwrite:
        print(f"refusing to overwrite {out}; pass --overwrite if intentional",
              file=sys.stderr)
        return 1

    date = args.date or dt.date.today().strftime("%-d %B %Y")
    text = TEMPLATE.format(
        version=args.version,
        date=date,
        versioncode=derive_versioncode(repo_root),
    )
    out.write_text(text)
    print(f"wrote {out.relative_to(repo_root)}")
    print("next: fill in the TODOs, carry forward Part 2/3 from the prior draft,")
    print("then commit and reference this file in the GH release body.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
