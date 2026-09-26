#!/usr/bin/env python3
"""Prove a test can fail, safely, on a Moodle tree shared with other processes.

WHY THIS EXISTS
---------------
A test that cannot fail is decoration. The only way to know a test pins what its
docblock claims is to plant the defect and watch it go red. The obvious way to do
that is to edit the deployed plugin, run phpunit, and revert.

On 2026-09-25 three agents did exactly that in parallel against one Moodle tree
and it produced a FALSE PASS. One agent's `rsync --delete` revert wiped another
agent's planted defect in the window between applying it and phpunit loading the
file, so phpunit compiled clean code and reported OK. The agent nearly recorded
"this test does not catch that defect" about a test that catches it perfectly.

The failure is silent and it lies in the direction that matters: it makes a good
test look useless, and it can equally make a broken test look proven.

WHAT THIS DOES ABOUT IT
-----------------------
Three things, in order of importance:

1. An exclusive lock around the whole deploy-mutate-run-revert cycle, so two
   processes cannot interleave. flock on a lockfile; the lock is held for the
   duration and released even if phpunit dies.

2. A check that the mutation is STILL ON DISK after phpunit exits. If it is not,
   something reverted underneath us and the verdict is discarded, not reported.
   This is the belt to the lock's braces: it catches a process that did not take
   the lock at all.

3. A surgical revert. Only the mutated file is restored, from the repo, rather
   than an `rsync --delete` across the whole plugin, so a concurrent run loses at
   most its own file rather than everything.

USAGE
-----
    python3 scripts/prove_test.py \\
        --file classes/external/generate_quiz.php \\
        --find "require_capability('local/ai_course_assistant:use', $context);" \\
        --replace "" \\
        --filter quiz_learner_journey_test::test_a_learner_without_the_use_capability_is_refused_the_quiz

Exit 0 means the test CAUGHT the defect, which is the outcome you want.
Exit 1 means the test passed with the defect present, so it does not pin it.
Exit 2 means the run was inconclusive and must not be reported either way.
"""
import argparse
import fcntl
import os
import shutil
import subprocess
import sys
import time

REPO = os.path.expanduser('~/ai-projects/ai_course_assistant')
DEPLOYED = os.path.expanduser('~/Sites/moodle/local/ai_course_assistant')
MOODLE = os.path.expanduser('~/Sites/moodle')
LOCKFILE = os.path.expanduser('~/Sites/moodle/.sola-prove.lock')
PHP_BIN = '/opt/homebrew/opt/php@8.3/bin'

INCONCLUSIVE = 2


def run(cmd, **kw):
    env = dict(os.environ, PATH=PHP_BIN + ':' + os.environ.get('PATH', ''))
    return subprocess.run(cmd, shell=True, capture_output=True, text=True, env=env, **kw)


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument('--file', required=True, help='Plugin-relative path to mutate.')
    ap.add_argument('--find', required=True, help='Exact text to replace.')
    ap.add_argument('--replace', default='', help='Replacement text; empty deletes it.')
    ap.add_argument('--filter', required=True, help='phpunit --filter expression.')
    ap.add_argument('--timeout', type=int, default=900)
    args = ap.parse_args()

    source = os.path.join(REPO, args.file)
    target = os.path.join(DEPLOYED, args.file)
    if not os.path.isfile(source):
        print(f'no such file in the repo: {args.file}', file=sys.stderr)
        return INCONCLUSIVE

    with open(LOCKFILE, 'w') as lock:
        # Blocking, because waiting is correct here: the alternative is two runs
        # interleaving, which is the bug this file exists to prevent.
        fcntl.flock(lock, fcntl.LOCK_EX)
        try:
            # Start from the repo's copy of just this file, so a previous run's
            # mutation cannot be mistaken for ours.
            shutil.copy2(source, target)

            original = open(source, encoding='utf-8').read()
            if args.find not in original:
                print('the --find text is not present in the file, so the mutation '
                      'would be a no-op and the verdict meaningless', file=sys.stderr)
                return INCONCLUSIVE

            mutated = original.replace(args.find, args.replace, 1)
            open(target, 'w', encoding='utf-8').write(mutated)

            result = run(f'cd {MOODLE} && vendor/bin/phpunit --filter {args.filter!r}',
                         timeout=args.timeout)
            output = result.stdout + result.stderr

            # The mutation must still be on disk. If it is not, something reverted
            # the file mid-run and phpunit may have compiled clean code.
            still_there = open(target, encoding='utf-8').read() == mutated
            if not still_there:
                print('INCONCLUSIVE: the mutation was gone from disk when phpunit '
                      'finished, so another process reverted it mid-run. Verdict '
                      'discarded rather than reported.', file=sys.stderr)
                return INCONCLUSIVE

            if 'No tests executed' in output:
                print('INCONCLUSIVE: the filter matched no tests, which phpunit '
                      'reports as success.', file=sys.stderr)
                return INCONCLUSIVE

            caught = ('FAILURES' in output) or ('ERRORS' in output)
            summary = next((ln for ln in output.splitlines()
                            if ln.startswith('Tests:') or ln.startswith('OK')), '')
            print(('CAUGHT' if caught else 'SURVIVED') + f'  {summary}')
            print(f'  mutation: {args.file}: {args.find[:70]!r} -> {args.replace[:40]!r}')
            return 0 if caught else 1
        finally:
            shutil.copy2(source, target)
            fcntl.flock(lock, fcntl.LOCK_UN)


if __name__ == '__main__':
    sys.exit(main())
