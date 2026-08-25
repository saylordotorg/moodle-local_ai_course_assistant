Subject: Automated test results for another plugin shown against my version (Marketplace, plugin 3808)

Hello,

On Moodle Marketplace (marketplace.moodle.com), the automated test report shown
against one of my plugin versions is a report for a different plugin.

    Plugin:  local_ai_course_assistant (plugin id 3808)
    Review:  CONTRIB-10574
    Version: 7.0.3, build 2026082200, version record id 26709,
             uploaded 22 August 2026
    Page:    https://marketplace.moodle.com/plugins/submit/step3/3808

This is on the Marketplace, not the moodle.org Plugins Directory.

WHAT I SEE

The version card for 7.0.3 shows an "Automated test passed" badge. Opening
"View test results" for that version shows a run described as "RUN PHP Lint on
mod_playercross", over 80 files, referring to:

    mod_form.php
    playercross_add_instance
    mod/playercross:addinstance
    tests/behat/mod_playercross_*.feature

None of that can belong to my plugin. local_ai_course_assistant is a local
plugin: it has no mod_form.php, no mod/* capability namespace, no *_add_instance
function, and it contains considerably more than 80 files.

Counting occurrences in the rendered report: 31 mentions of "playercross", and
zero mentions of "ai_course_assistant".

WHY I DO NOT THINK THIS IS ME MISREADING THE PAGE

1. The report is in the element with id testResultsModal26709, which is scoped to
   version id 26709.

2. Version 26709 is genuinely mine. The corresponding pluginDetailsModal26709
   shows local_ai_course_assistant, build 2026082200, release 7.0.3, repository
   tag v7.0.3, and Moodle 4.5 / 5.0 / 5.1 / 5.2  -  all
   correct for my upload.

3. It is server-rendered into my version's page, not fetched separately. A
   same-origin fetch of the raw page HTML (71,561 bytes) contains the same 31
   occurrences of "playercross" as the rendered modal.

4. The summary line and the mismatched detail are the same block, so the summary
   is not independent of the wrong report. Offsets in the raw HTML:

       38159  "Automated test passed" badge (version card)
       47084  "0 test problems found"       (inside .modal-body)
       48579  first occurrence of "playercross" (same .modal-body)

WHAT I AM UNSURE OF, AND WHY IT MATTERS

I cannot tell from the interface which of two things happened:

  (a) My zip was tested and the wrong report is being rendered. Cosmetic.
  (b) The wrong zip was tested, in which case the pass badge on my version is not
      backed by a run against my code.

The difference matters because a reviewer working CONTRIB-10574 sees a pass badge
whose supporting evidence belongs to someone else. I would rather not have my
submission benefit from a result I cannot vouch for.

I have not re-checked whether this recurs on my later uploads (7.0.4 on 22 August
and 7.0.5 on 24 August); the most recent still shows "Automated test in progress"
at the time of writing.

ONE SIDE EFFECT WORTH FLAGGING

Another plugin's lint output is currently visible on my version's page. If that
submission is not yet public, this discloses more than was intended  -  which is a
matter for you rather than for me, but I would want to know if it were mine.

WHAT WOULD HELP

1. Whether my 7.0.3 zip was in fact tested. If it was, re-attaching or
   re-rendering the correct report would be ideal.
2. If it was not, I would prefer the badge cleared and the version re-queued
   rather than the review resting on a result that is not mine.
3. Whether other versions or other plugins are affected by the same mismatch.

For context: my own CI runs moodle-plugin-ci against the same commit that tag
v7.0.3 points at, across PHP 8.1, 8.2 and 8.3 on both MariaDB and PostgreSQL, and
all checks pass  -  so I have no reason to expect a genuine failure hiding behind
this. The concern is only that the result displayed is not mine.

Happy to supply the raw page HTML or anything else useful.

Thank you,
Tom Caswell
Saylor Academy
tom.caswell@saylor.org
