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
WHAT THIS CANNOT PROVE
----------------------
Two limits worth knowing before trusting a verdict.

Mutating db/install.xml proves nothing about a DB-backed test. The phpunit
database is built once at init and is not rebuilt when install.xml changes, so
an advanced_testcase reading through $DB sees the schema as it was, whatever
the file now says, and reports SURVIVED regardless of what it pins. Only
source-reading tests (privacy_discovery_coverage_test, which parses the file
itself) actually see such a mutation.

Only --file is synced to the Moodle tree. If the TEST file in the repo is
newer than the deployed copy, the verdict describes the deployed test, not the
one you just wrote. rsync the plugin before proving anything you have just
edited.

"""
import argparse
import fcntl
import os
import re
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


def read_verdict(output):
    """Decide what a phpunit run actually said.

    Returns (caught, summary, why). caught is None when the run proves nothing,
    and `why` then explains it. Every branch here exists because the simpler
    version of this function reported a confident verdict about a run that had
    not tested anything.

    - A run that never reached a test prints neither FAILURES nor ERRORS.
      "PHPUnit environment is not initialised", "initialised for a different
      version" (the first run after any version.php bump), a bootstrap fatal and
      a dead database all look like this, and all used to read as SURVIVED.
    - A run whose only matching test was SKIPPED prints
      "OK, but incomplete, skipped, or risky tests!" with zero assertions. That
      is the same nothing-happened case as "No tests executed", and it used to
      read as SURVIVED.
    - FAILURES and ERRORS were matched anywhere in the output, so a test whose
      own message or stdout contained either word could flip the verdict. They
      are anchored to the start of a line now, and a run reporting both is
      refused rather than guessed at.
    """
    lines = output.splitlines()
    summary = next((ln for ln in lines
                    if ln.startswith('Tests:') or ln.startswith('OK')), '')

    caught = any(ln.startswith('FAILURES!') or ln.startswith('ERRORS!') for ln in lines)
    passed = any(ln.startswith('OK (') or ln.startswith('OK, but') for ln in lines)

    if caught and passed:
        return None, summary, ('phpunit reported both a pass and a failure, which '
                               'one run cannot do. Verdict refused.')
    if not caught and not passed:
        return None, summary, ('phpunit reported neither a clean pass nor a failure, '
                               'so it probably never ran the test.')
    if not caught:
        # A pass only counts if assertions actually ran. Read the counts rather
        # than grepping the summary string: the first line starting with 'OK' is
        # "OK, but incomplete, skipped, or risky tests!", which carries no
        # numbers, so a substring check against it never saw the Skipped count
        # sitting on the next line.
        counts = {}
        # The plain "OK (1 test, 0 assertions)" shape has no Tests: line at all,
        # so it used to skip this check entirely and report SURVIVED while the
        # docstring promised otherwise. Moodle's phpunit.xml does not treat a
        # zero-assertion test as risky, so that shape is reachable.
        plain = re.search(r'^OK \((\d+) tests?, (\d+) assertions?\)', output, re.M)
        if plain:
            counts = {'Tests': int(plain.group(1)), 'Assertions': int(plain.group(2))}
        for ln in lines:
            if ln.startswith('Tests:'):
                for part in ln.rstrip('.').split(','):
                    bits = part.strip().split(':')
                    if len(bits) == 2 and bits[1].strip().isdigit():
                        counts[bits[0].strip()] = int(bits[1].strip())
                break

        if counts:
            tests = counts.get('Tests', 0)
            inert = counts.get('Skipped', 0) + counts.get('Incomplete', 0)
            if counts.get('Assertions', 0) == 0:
                return None, summary, ('the run made no assertions at all, so nothing '
                                       'survived anything.')
            if tests and inert >= tests:
                return None, summary, ('every matching test was skipped or incomplete, '
                                       'so none of them ran against the mutation.')
    return caught, summary, ''


def syntax_ok(path):
    """Check a mutated file still parses. Returns (ok, message).

    A mutation that breaks the file makes every test that loads it error, which
    the verdict reads as CAUGHT. That says the test pins the behaviour when all
    it proved is that the file is now broken.
    """
    if path.endswith('.php'):
        lint = run(f'php -l {path!r}')
        if lint.returncode == 127:
            return False, 'php is not on PATH, so the mutation could not be checked'
        return lint.returncode == 0, (lint.stdout + lint.stderr).strip()
    if path.endswith('.json'):
        import json
        try:
            json.load(open(path, encoding='utf-8'))
            return True, ''
        except Exception as e:
            return False, f'invalid JSON: {e}'
    if path.endswith('.xml'):
        import xml.etree.ElementTree as ET
        try:
            ET.parse(path)
            return True, ''
        except Exception as e:
            return False, f'invalid XML: {e}'
    return True, ''


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument('--file', required=True, help='Plugin-relative path to mutate.')
    ap.add_argument('--find', required=True, help='Exact text to replace.')
    ap.add_argument('--replace', default='', help='Replacement text; empty deletes it.')
    ap.add_argument('--filter', required=True, help='phpunit --filter expression.')
    ap.add_argument('--timeout', type=int, default=900)
    ap.add_argument('--skip-baseline', action='store_true',
                    help='Do not run the filter on clean code first. Halves the '
                         'runtime and gives up the only check that the test '
                         'passes before the mutation.')
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
            # Read the repo file ONCE, and use this exact string for both the
            # baseline and the mutation. copy2() followed by a later read left a
            # window: the flock covers the deployed tree, not the repo, so a save
            # in an editor (or another agent) during a baseline that can run for
            # minutes meant the baseline tested version A while the mutation was
            # applied to version B.
            original = open(source, encoding='utf-8').read()

            # Anchor checks before the baseline, so a mistyped --find fails in
            # milliseconds instead of after a full clean run.
            hits = len(re.findall('(?=' + re.escape(args.find) + ')', original))
            if hits == 0:
                print('the --find text is not present in the file, so the mutation '
                      'would be a no-op and the verdict meaningless', file=sys.stderr)
                return INCONCLUSIVE
            if hits > 1:
                print(f'the --find text appears {hits} times in {args.file}. '
                      'replace() would mutate only the first, which is probably '
                      'not the one you mean, and a mutation of the wrong line '
                      'reports SURVIVED exactly like a test that does not catch '
                      'the defect. Extend --find with a neighbouring line until '
                      'it matches once.', file=sys.stderr)
                return INCONCLUSIVE

            # Start from the repo's copy of just this file, so a previous run's
            # mutation cannot be mistaken for ours.
            with open(target, 'w', encoding='utf-8') as fh:
                fh.write(original)

            # Baseline. Without it, a filter whose test ALREADY fails on clean
            # code reports CAUGHT for every mutation, including one on a line
            # the test never reaches. That is the mirror of the ambiguous-anchor
            # bug: a confident verdict about something that was never tested.
            # It is not hypothetical. On 2026-10-01 a test broken by its own
            # fixture (a duplicate key on a second insert) raised a
            # dml_write_exception, phpunit reported ERRORS, and this script
            # printed CAUGHT. The mutation was irrelevant and the proof was
            # worthless.
            if not args.skip_baseline:
                try:
                    base = run(f'cd {MOODLE} && vendor/bin/phpunit --filter {args.filter!r}',
                               timeout=args.timeout)
                except subprocess.TimeoutExpired:
                    print(f'INCONCLUSIVE: the baseline run did not finish within '
                          f'{args.timeout}s.', file=sys.stderr)
                    return INCONCLUSIVE
                baseout = base.stdout + base.stderr
                if 'No tests executed' in baseout:
                    print('INCONCLUSIVE: the filter matched no tests on clean code, '
                          'which phpunit reports as success.', file=sys.stderr)
                    return INCONCLUSIVE
                bverdict, bsummary, bwhy = read_verdict(baseout)
                if bverdict is None:
                    print('INCONCLUSIVE: the baseline run proves nothing: ' + bwhy
                          + '\n' + '\n'.join(baseout.splitlines()[-12:]), file=sys.stderr)
                    return INCONCLUSIVE
                if bverdict:
                    print('INCONCLUSIVE: the test already fails on UNMUTATED code, so '
                          'any CAUGHT here would be meaningless. Fix the test first.\n'
                          f'  baseline: {bsummary}', file=sys.stderr)
                    return INCONCLUSIVE

            mutated = original.replace(args.find, args.replace, 1)
            open(target, 'w', encoding='utf-8').write(mutated)

            ok, detail = syntax_ok(target)
            if not ok:
                print('INCONCLUSIVE: the mutated file is not valid, so every test '
                      'loading it would error and the verdict would say CAUGHT for '
                      'the wrong reason:\n' + detail, file=sys.stderr)
                return INCONCLUSIVE

            try:
                result = run(f'cd {MOODLE} && vendor/bin/phpunit --filter {args.filter!r}',
                             timeout=args.timeout)
            except subprocess.TimeoutExpired:
                # Uncaught, this propagates and Python exits 1, which is the exact
                # exit code that means SURVIVED. A run that never finished is not
                # evidence about anything.
                print(f'INCONCLUSIVE: phpunit did not finish within {args.timeout}s. '
                      'No verdict; raise --timeout or narrow --filter.', file=sys.stderr)
                return INCONCLUSIVE
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

            verdict, summary, why = read_verdict(output)
            if verdict is None:
                print('INCONCLUSIVE: ' + why + '\n'
                      + '\n'.join(output.splitlines()[-12:]), file=sys.stderr)
                return INCONCLUSIVE

            print(('CAUGHT' if verdict else 'SURVIVED') + f'  {summary}')
            print(f'  mutation: {args.file}: {args.find[:70]!r} -> {args.replace[:40]!r}')
            return 0 if verdict else 1
        finally:
            shutil.copy2(source, target)
            fcntl.flock(lock, fcntl.LOCK_UN)


if __name__ == '__main__':
    sys.exit(main())
