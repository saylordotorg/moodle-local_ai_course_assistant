#!/usr/bin/env python3
"""Commit and push the .wiki working tree, refusing to silently revert the remote.

Why this exists
---------------

`.wiki/` is a SEPARATE git repository (the GitHub wiki) and it is listed in the
plugin repo's .gitignore. Nothing in the plugin's own commit, tag or release flow
touches it, and `git status` in the plugin repo never mentions it. So an edit to
`.wiki/Changelog.md` can be written, released around, and forgotten.

That is not hypothetical. On 2026-09-29 the published wiki changelog was found
still reading v7.5.1 while the plugin had shipped seven more releases. Step 3 of
the release checklist ("Update .wiki/Changelog.md") had been carried out every
time, locally, and pushed none of those times.

The second failure is worse and is the reason this script refuses rather than
merges. The local .wiki working tree can be arbitrarily old. On the same day, the
local Changelog.md was a PRE-TRIM copy, while the remote carried a deliberate
edit that removed 804 lines. Committing the local file and pushing would have
restored all 804 lines and three release entries that had been removed on
purpose, and the commit would have looked like an ordinary changelog update.

So the rule this script enforces: **a push from this workspace may add lines, and
may not remove lines that exist on the remote, unless a human says otherwise.**

Usage
-----

    python3 scripts/push_wiki.py -m "Changelog: v7.5.9"
    python3 scripts/push_wiki.py -m "..." --dry-run
    python3 scripts/push_wiki.py -m "..." --allow-deletions   # only when intended

Exit codes: 0 pushed or nothing to do, 1 refused, 2 could not run.
"""

import argparse
import pathlib
import subprocess
import sys

WIKI = pathlib.Path(__file__).resolve().parent.parent / ".wiki"


def git(*args, check=True):
    """Run git inside the wiki repo and return stdout."""
    r = subprocess.run(
        ["git", "-C", str(WIKI), *args], capture_output=True, text=True
    )
    if check and r.returncode != 0:
        print("git %s failed:\n%s" % (" ".join(args), r.stderr.strip()), file=sys.stderr)
        sys.exit(2)
    return r.stdout.strip()


def main():
    ap = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    ap.add_argument("-m", "--message", help="commit message")
    ap.add_argument("--dry-run", action="store_true",
                    help="report what would happen and change nothing")
    ap.add_argument("--allow-deletions", action="store_true",
                    help="permit a push that removes lines present on the remote")
    args = ap.parse_args()

    if not (WIKI / ".git").exists():
        print("No wiki repo at %s.\n"
              "Clone it first:\n"
              "  git clone git@github.com:saylordotorg/"
              "moodle-local_ai_course_assistant.wiki.git .wiki" % WIKI,
              file=sys.stderr)
        return 2

    branch = git("rev-parse", "--abbrev-ref", "HEAD")
    git("fetch", "--quiet", "origin")
    remote = "origin/%s" % branch

    # Ask the REMOTE what differs, rather than parsing `git status --porcelain`.
    # The first version of this script parsed porcelain and sliced line[3:] for
    # the path, but the helper that ran git also stripped its output, which eats
    # the significant leading space on the first porcelain line. The path came
    # back as "hangelog.md", every diff matched nothing, and the guard cheerfully
    # reported "no deletions" on a file with 35 of them. A guard that passes
    # silently is worse than no guard, so this asks a question with no parsing in
    # it: what differs between the working tree and the remote tip?
    changed = [p for p in git("diff", "--name-only", remote).splitlines() if p.strip()]

    # Untracked files are somebody else's work in progress. Never sweep them in.
    untracked = [p for p in git("ls-files", "--others", "--exclude-standard").splitlines() if p.strip()]

    if not changed:
        behind = git("rev-list", "--count", "HEAD..%s" % remote)
        if behind != "0":
            print("Working tree matches %s, but local HEAD is %s commit(s) behind. "
                  "Run: git -C .wiki pull --ff-only" % (remote, behind))
            return 0
        print("Wiki matches %s. Nothing to do." % remote)
        return 0

    if untracked:
        print("Leaving %d untracked file(s) alone: %s"
              % (len(untracked), ", ".join(untracked)))

    # THE GUARD. Count added and removed lines against the REMOTE tip, not
    # against local HEAD, because the local tree may predate the remote's edits.
    added = removed = 0
    removed_samples = []
    for line in git("diff", remote).splitlines():
        if line.startswith("+") and not line.startswith("+++"):
            added += 1
        elif line.startswith("-") and not line.startswith("---"):
            removed += 1
            if len(removed_samples) < 8:
                removed_samples.append(line)

    print("Files differing from %s: %s" % (remote, ", ".join(changed)))
    print("Change against %s: +%d lines, -%d lines." % (remote, added, removed))

    if removed and not args.allow_deletions:
        print("\nREFUSING TO PUSH: this would remove %d line(s) that exist on %s."
              % (removed, remote))
        print("The local wiki tree is probably older than the remote. Rebuild your "
              "change on top of the remote file rather than publishing the local copy:\n")
        print("    git -C .wiki fetch origin")
        print("    git -C .wiki show %s:<file> > <file>   # start from the remote" % remote)
        print("    # re-apply only your additions, then run this script again\n")
        print("First few removals:")
        for line in removed_samples:
            print("  %s" % line[:110])
        if removed > len(removed_samples):
            print("  ... and %d more" % (removed - len(removed_samples)))
        print("\nIf the removals ARE intended, re-run with --allow-deletions.")
        return 1

    if args.dry_run:
        print("Dry run. Nothing committed or pushed.")
        return 0

    if not args.message:
        print("A commit message is required (-m). Nothing done.", file=sys.stderr)
        return 2

    for path in changed:
        git("add", path)
    if git("diff", "--cached", "--name-only"):
        git("commit", "-q", "-m", args.message)
        print("Committed: %s" % git("log", "-1", "--format=%h %s"))

    push = subprocess.run(
        ["git", "-C", str(WIKI), "push", "origin", branch],
        capture_output=True, text=True,
    )
    if push.returncode != 0:
        print("Push failed. The remote moved again; re-run this script.\n%s"
              % push.stderr.strip(), file=sys.stderr)
        return 1

    print("Pushed to %s." % remote)
    return 0


if __name__ == "__main__":
    sys.exit(main())
