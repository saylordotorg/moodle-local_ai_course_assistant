# SOLA analytics in Redash — corrected queries

Companion to the "Build Instructions" spec (Part 2), checked against the plugin
source at v7.3.1 rather than inferred from the rendered page. The spec's premise is
right: SOLA writes to the Moodle database and Redash data source 18 already reaches
it, so no export is needed. Two of its four queries will not reproduce the page,
for reasons that are only visible in the code.

## Correction 1 — Query A overcounts messages

`_msgs` carries `role='system'` telemetry rows (TTS, STT and Soapbox events) that
are not learner messages. The analytics page excludes them deliberately; the
comment at `classes/analytics.php:77` says so, and the code uses a role allowlist
rather than a telemetry denylist "so any future role='system' variant stays out".

The spec's `COUNT(*)` has no role filter, so it will report more messages than the
page for any course with voice or Soapbox activity. Note also that the page's
"active students" and "messages per student" figures count `role='user'` ONLY,
while total messages counts user + assistant. Three different denominators.

```sql
SELECT
    COUNT(DISTINCT m.conversationid)                                   AS conversations,
    SUM(m.role IN ('user','assistant'))                                AS messages,
    COUNT(DISTINCT CASE WHEN m.role='user' THEN m.userid END)          AS active_students,
    ROUND(SUM(m.role='user')
          / NULLIF(COUNT(DISTINCT CASE WHEN m.role='user'
                                       THEN m.userid END), 0), 1)      AS avg_msgs_per_student,
    SUM(m.tokens_used)                                                 AS total_tokens,
    SUM(m.prompt_tokens)                                               AS prompt_tokens,
    SUM(m.completion_tokens)                                           AS completion_tokens
FROM mdl_local_ai_course_assistant_msgs m
WHERE m.courseid = {{CourseId}}
  AND FROM_UNIXTIME(m.timecreated) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY);
```

Caveat on the token columns, which the spec could not have seen: voice and Soapbox
telemetry rows are written with `provider` and `model_name` NULL and no token
counts (`classes/conversation_manager.php:126-127` nulls both unless the role is
`assistant`). So `total_tokens` is chat only. It is not the whole AI spend for the
course, and nothing on the page says so. This is tracked as finding F81.

## Correction 2 — Query B will not reproduce "Common Prompts"

The spec guesses a 60-character prefix and says to tune it. No tuning will match,
because the page does something structurally different
(`classes/analytics.php:267-330`):

1. Sample the most recent **20,000** user messages only (`TEXT_SAMPLE_CAP`), not all.
2. Skip messages shorter than 5 characters.
3. Lowercase, take the **first 6 words**.
4. Truncate that to 60 chars with a trailing `...` if longer.
5. **Cluster by the first 3 words**, summing frequencies, and label each cluster
   with its longest member pattern.

So the visible 60-character truncation the spec noticed is the last cosmetic step,
not the grouping key. The grouping key is three words.

```sql
SELECT
    SUBSTRING_INDEX(LOWER(TRIM(m.message)), ' ', 3) AS cluster_key,
    SUBSTRING_INDEX(LOWER(TRIM(m.message)), ' ', 6) AS prompt_pattern,
    COUNT(*)                                        AS frequency
FROM mdl_local_ai_course_assistant_msgs m
WHERE m.courseid = {{CourseId}}
  AND m.role = 'user'
  AND CHAR_LENGTH(TRIM(m.message)) >= 5
  AND FROM_UNIXTIME(m.timecreated) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
GROUP BY cluster_key, prompt_pattern
HAVING frequency > 1
ORDER BY frequency DESC
LIMIT 40;
```

This groups slightly finer than the page (the page merges every 6-word pattern
sharing a 3-word key into one row; this returns them separately so you can see the
split). To collapse fully, group by `cluster_key` alone and take `MAX(prompt_pattern)`.

**Keep `HAVING frequency > 1`.** The spec is right that this is the privacy control,
and clustering on three words makes it stronger, not weaker.

## Queries C and D

Both are correct as written. Two notes:

- Query C groups by `provider, model_name`. Rows where both are NULL are the
  role='system' telemetry described above — they will appear as a NULL/NULL group
  with zero tokens. That group is voice and Soapbox activity, not an error.
- Query D is right that only a handful of courses have the assistant enabled, but
  it discovers courses by *message activity*, which is not the same as *enabled*.
  A course can be enabled with no traffic. The authoritative source is the config
  key `local_ai_course_assistant/sola_enabled_course_<id>` in `mdl_config_plugins`,
  combined with `default_course_mode`.

## On the "known gaps" section

- **Hotspots.** The spec is right to be suspicious of the 1-2 counts. There is a
  confirmed defect here (F49): `get_unit_usage` sums per-cmid DISTINCT-user counts
  into a section total, so one learner active in three activities of a section is
  counted three times. The comment at `classes/analytics.php:1061` acknowledges the
  re-aggregation is needed and the code sums anyway. Do not reproduce this in
  Redash — compute `COUNT(DISTINCT userid)` across the whole section instead.
- **Escalations and off-topic rate** are not in `_msgs`. Off-topic lives in
  `_convs.offtopic_count`, and the page's rate divides that unwindowed sum by a
  windowed user-message count (finding F56), so the published percentage is
  inconsistent for any range other than "all time". Compute it from `_convs`
  directly and window both sides.
- **Themes and Learning Radar** are computed at request time, not stored. The spec
  is right that these cannot be reproduced from SQL.

## Privacy

The spec's four constraints are correct and I would not soften any of them. One
addition: `_msgs.message` is the raw learner text, and the plugin's own transcript
report pseudonymizes both the learner id **and** the conversation id before export,
because `_convs` is unique on `(userid, courseid)` — which makes `conversationid` a
stable per-learner identifier. Any Redash query that emits `conversationid`
alongside message content reintroduces exactly the join the plugin removes. Treat
`conversationid` as identifying.
