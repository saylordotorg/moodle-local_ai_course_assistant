Subject: learn.saylor.org database is 721 GB, and 61% of it is the log table

Hi Jose,

I was pulling numbers together for a hosting review and the size of the Academy
database stopped me. Could you help me understand what the plan is here?

WHERE IT STANDS TODAY (measured 25 September 2026)

  saylor_mdl_prod (learn)      721.26 GB
  saylor_degrees_prod          18.23 GB
  Total                        739.49 GB

  Aurora reports about 1.025 TB allocated, so there is some headroom between
  the logical size and the volume.

The single biggest item is the standard log:

  mdl_logstore_standard_log    437.97 GB    684,876,982 rows

That is 61% of the whole Academy database. The next largest are a long way
behind: question attempts at 59.58 GB, grade history at 49.37 GB, and
notifications at 41.77 GB.

MY ACTUAL QUESTION

I had it in my head that we trim the logs on a schedule, but the setting says
otherwise. loglifetime is set to 0, which in Moodle means keep forever, and the
oldest row in the table is 31 January 2025. So nothing is expiring, and the
table has grown to 685 million rows in about eight months.

Is that deliberate? I can think of good reasons it might be, and I would rather
ask than assume:

  1. Something downstream reads it and needs the history. Redash queries, the
     analytics subsystem, or a compliance requirement I am not thinking of.
  2. It was set to keep forever during an investigation and never set back.
  3. We agreed a retention period at some point and the setting did not follow.

If it is (2) or (3), I would like to agree a retention window and turn it back
on. A 180-day policy would reclaim a large fraction of 438 GB, and the log table
is also the thing that makes a restore slow, so there is a recovery-time
argument as well as a cost one.

If it is (1), that is fine and I will stop asking, but I would like to know what
depends on it so we can write it down somewhere.

TWO SMALLER ONES WHILE I HAVE YOU

  mdl_notifications is 41.77 GB across 7.3 million rows. Moodle has a cleanup
  for this and I do not know whether it is running.

  mdl_grade_grades_history is 49.37 GB across 102 million rows. I am not
  proposing we touch it, since grade history is the sort of thing you want when
  someone disputes a certificate, but I want to note it rather than have it
  surprise us later.

WHAT I AM NOT ASKING FOR

I am not asking anyone to delete anything this week. I would rather understand
the intent first, agree a policy, and then apply it during a normal maintenance
window with a backup we have actually restored from.

Thanks,
Tom
