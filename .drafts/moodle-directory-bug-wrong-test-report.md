Automated test results shown for the wrong plugin
=================================================

Plugin: local_ai_course_assistant (plugin id 3808)
Version affected: 7.0.3, build 2026082200, version record id 26709
Review ticket: CONTRIB-10574
Observed: 2026-08-22

Summary
-------

The version card for our 7.0.3 upload shows an "Automated test passed" badge, but
the test-results report attached to that version is for a different plugin,
mod_playercross. The report contains no reference to our component at all.

We cannot tell from the directory UI whether this is a display-layer mixup (the
right zip was tested, the wrong report is rendered) or whether the wrong zip was
actually tested (in which case the green badge on our version is not backed by a
run against our code). That distinction is the reason we are reporting it: the
first is cosmetic, the second means a reviewer is looking at a pass that never
happened.

What the report contains
------------------------

Opening the test results for version 26709 shows a run described as "RUN PHP Lint
on mod_playercross" over 80 files, and referring to:

- mod_form.php
- playercross_add_instance
- mod/playercross:addinstance
- tests/behat/mod_playercross_*.feature

None of these can belong to our plugin. local_ai_course_assistant is a local
plugin: it has no mod_form.php, no mod/* capability namespace, no *_add_instance
function, and considerably more than 80 files.

Counting occurrences in the rendered report: 31 mentions of "playercross", 0
mentions of "ai_course_assistant".

Evidence that this is bound to our version, not a misread
---------------------------------------------------------

1. The report is in the modal with id testResultsModal26709, which is scoped to
   version id 26709.

2. Version 26709 is genuinely ours. The corresponding pluginDetailsModal26709
   shows local_ai_course_assistant, build 2026082200, release 7.0.3, repository
   tag v7.0.3, Moodle 4.5 / 5.0 / 5.1 / 5.2 - all correct for our upload.

3. The report is server-rendered into our version's page, not fetched separately.
   A same-origin fetch of the raw page HTML (71,561 bytes) contains the same 31
   occurrences of "playercross" as the rendered modal.

4. The summary line and the mismatched detail are the same block, so the summary
   is not independent of the wrong report. In the raw HTML:

     offset 38159  - "Automated test passed" badge (version card)
     offset 47084  - "0 test problems found" (inside .modal-body)
     offset 48579  - first occurrence of "playercross" (same .modal-body)

Expected behavior
-----------------

The test results shown for a version should be the results of testing that
version's zip.

Actual behavior
---------------

The results shown for version 26709 of local_ai_course_assistant are results for
mod_playercross.

Impact
------

- A reviewer working CONTRIB-10574 sees a pass badge whose supporting evidence is
  another plugin's. We are not able to represent our version as having passed
  automated testing on this basis, and we would rather say so than let it stand.
- It also means another plugin's lint output is visible on our version's page. If
  that plugin's submission is not yet public, this discloses more than intended.

What we would like to know
--------------------------

1. Was our 7.0.3 zip actually tested? If it was, we would appreciate the run being
   re-attached or re-rendered so the correct report is visible.
2. If it was not, we would like the badge cleared and the version re-queued, so
   the review is based on a real result.
3. Whether other versions or other plugins are affected by the same mismatch.

For reference: our own CI runs moodle-plugin-ci against the same commit that tag
v7.0.3 points at (729b33e8), across PHP 8.1, 8.2 and 8.3 on both MariaDB and
PostgreSQL, on MOODLE_405_STABLE, and all checks pass. So we have no reason to
expect a genuine failure hiding behind this - the concern is purely that the
result displayed is not ours.

Happy to supply the raw page HTML or anything else useful.
