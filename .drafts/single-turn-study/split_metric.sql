-- SOLA first-turn outcomes, split by how the learner opened.
-- The headline "bounce rate" mixes four different things; this separates them.
-- Logistics detection is a KEYWORD HEURISTIC, not a classifier -- treat as indicative.
WITH t AS (
  SELECT id, userid, conversationid, role, message, timecreated
  FROM mdl_local_ai_course_assistant_msgs
  WHERE timecreated >= UNIX_TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL {{ days }} DAY))
    AND role IN ('user','assistant') AND interaction_type = 'chat'
),
first_user AS (SELECT userid, MIN(timecreated) AS ts FROM t WHERE role='user' GROUP BY userid),
opener AS (
  SELECT t.userid, t.message,
    CASE
      WHEN t.message LIKE '%plan my current study session%'         THEN 'chip: study plan'
      WHEN t.message LIKE '%focused plan for my study session%'     THEN 'chip: study plan (v2)'
      WHEN t.message LIKE 'Help me understand %key concepts%'       THEN 'chip: help me understand'
      WHEN t.message LIKE '%coach me through planning%'             THEN 'chip: project coaching'
      WHEN t.message LIKE '%summary of the key concepts%'           THEN 'chip: help lesson'
      WHEN t.message LIKE '%most important concept in this course%' THEN 'chip: explain'
      ELSE 'typed by learner'
    END AS opener_type,
    (t.message LIKE '%certificate%' OR t.message LIKE '%final exam%'
      OR t.message LIKE '%assessment%' OR t.message LIKE '%my exam%'
      OR t.message LIKE '%enrol%'      OR t.message LIKE '%enroll%'
      OR t.message LIKE '%transcript%') AS looks_logistical
  FROM t JOIN first_user f ON f.userid=t.userid AND f.ts=t.timecreated
  WHERE t.role='user'
),
tot AS (
  SELECT userid, SUM(role='user') AS msgs, SUM(role='assistant') AS replies
  FROM t GROUP BY userid
)
SELECT o.opener_type,
       COUNT(*)                                                   AS learners,
       SUM(tt.msgs > 1)                                           AS continued,
       SUM(tt.msgs = 1 AND tt.replies = 0)                        AS one_turn_no_reply,
       SUM(tt.msgs = 1 AND tt.replies > 0 AND o.looks_logistical) AS one_turn_likely_resolved,
       SUM(tt.msgs = 1 AND tt.replies > 0 AND NOT o.looks_logistical) AS one_turn_true_bounce,
       ROUND(100*SUM(tt.msgs = 1 AND tt.replies > 0 AND NOT o.looks_logistical)/COUNT(*),1)
                                                                  AS true_bounce_pct,
       ROUND(100*SUM(tt.msgs = 1)/COUNT(*),1)                     AS headline_bounce_pct,
       ROUND(AVG(tt.msgs),2)                                      AS mean_msgs
FROM opener o JOIN tot tt ON tt.userid = o.userid
GROUP BY o.opener_type
ORDER BY learners DESC

-- Saved-query setup in Redash:
--   Data source : learn.saylor.org  (id 2)
--   Parameter   : days -- Number, default 90
--   Suggested name: "SOLA first-turn outcomes by opener"
--
-- Reading it:
--   headline_bounce_pct      what we report today -- mixes all four outcomes below
--   one_turn_no_reply        asked, nothing stored (bug or abandonment; see study section 4)
--   one_turn_likely_resolved single turn, answered, keyword-matched as logistics -> a success
--   true_bounce_pct          what is actually worth worrying about
--
-- The 'chip: study plan (v2)' row exists so the rewritten chip is tracked
-- separately from the old one. Before/after within that cohort is the test
-- of whether the rewrite worked.
