-- SOLA single-turn study: sampling and cohort queries
-- Target: prod DBs for learn.saylor.org and degrees.saylor.org, via Redash.
-- Read-only. Table prefix assumed mdl_; window is a parameter (default 90 days).
-- Run Q0 first: it validates the premise before we spend effort reading.

SET @since := UNIX_TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 90 DAY));
SET @p := 'mdl_local_ai_course_assistant_';

-- ---------------------------------------------------------------
-- Q0. Does the 31% single-turn number still hold, and at what level?
--     Conversation-level and learner-level are DIFFERENT populations.
--     Only role IN ('user','assistant') — internal telemetry rows were
--     written into this table before v6.0.1 and would inflate counts.
-- ---------------------------------------------------------------
WITH turns AS (
  SELECT id, userid, conversationid, courseid, role, message,
         interaction_type, timecreated, prompt_tokens, completion_tokens,
         rag_latency_ms, model_name, provider
  FROM mdl_local_ai_course_assistant_msgs
  WHERE timecreated >= @since
    AND role IN ('user','assistant')
),
per_user AS (
  SELECT userid,
         SUM(role = 'user')                AS user_msgs,
         COUNT(DISTINCT conversationid)    AS convs,
         COUNT(DISTINCT interaction_type)  AS modes,
         MIN(timecreated)                  AS first_seen,
         MAX(timecreated)                  AS last_seen
  FROM turns GROUP BY userid
)
SELECT
  COUNT(*)                                             AS learners,
  SUM(user_msgs = 1)                                   AS one_msg_ever,
  ROUND(100 * SUM(user_msgs = 1) / COUNT(*), 1)        AS pct_one_msg,
  -- the honest denominator check: of those, how many used another MODE
  -- (quiz, voice, soapbox)? Those learners did not actually bounce.
  SUM(user_msgs = 1 AND modes > 1)                     AS one_msg_but_multimode,
  -- and how many came back on a later DAY within the window?
  SUM(user_msgs = 1 AND (last_seen - first_seen) > 86400) AS one_msg_but_returned
FROM per_user;

-- ---------------------------------------------------------------
-- Q1. THE SAMPLE. 50 learners whose entire SOLA history in the window
--     is one chat message. Deterministic and reproducible: ordered by a
--     hash of the id, not RAND(), so the same 50 come back on a re-run
--     and a second coder can be given the identical set.
-- ---------------------------------------------------------------
WITH turns AS (
  SELECT id, userid, conversationid, courseid, role, message,
         interaction_type, timecreated, prompt_tokens, completion_tokens,
         rag_latency_ms, model_name, provider
  FROM mdl_local_ai_course_assistant_msgs
  WHERE timecreated >= @since AND role IN ('user','assistant')
),
bounced AS (
  SELECT userid, MIN(conversationid) AS conversationid
  FROM turns
  GROUP BY userid
  HAVING SUM(role = 'user') = 1
     AND COUNT(DISTINCT interaction_type) = 1
     AND MAX(interaction_type) = 'chat'
),
picked AS (
  SELECT b.userid, b.conversationid
  FROM bounced b
  ORDER BY MD5(CONCAT('sola-single-turn-v1:', b.userid))
  LIMIT 50
)
SELECT
  -- pseudonymous handle: nothing that identifies the learner leaves the DB
  CONCAT('B', LPAD(ROW_NUMBER() OVER (ORDER BY MD5(CONCAT('k:', p.userid))), 3, '0')) AS ref,
  t.conversationid,
  t.courseid,
  c.shortname                                      AS course,
  t.role,
  t.interaction_type,
  t.message,
  t.timecreated,
  FROM_UNIXTIME(t.timecreated)                     AS at_utc,
  t.prompt_tokens,      -- proxy for how much course content was retrieved
  t.completion_tokens,
  t.rag_latency_ms,     -- non-null on assistant rows when retrieval ran
  t.model_name,
  t.provider,
  cv.offtopic_count,    -- did the topic guard fire on this conversation?
  r.rating,             -- thumbs, if the learner left one
  r.is_hallucination
FROM picked p
JOIN turns t                                   ON t.conversationid = p.conversationid
JOIN mdl_local_ai_course_assistant_convs cv    ON cv.id = t.conversationid
LEFT JOIN mdl_course c                         ON c.id = t.courseid
LEFT JOIN mdl_local_ai_course_assistant_msg_ratings r ON r.messageid = t.id
ORDER BY ref, t.timecreated, t.id;

-- ---------------------------------------------------------------
-- Q2. THE CONTRAST SET. 15 single-turn CONVERSATIONS belonging to
--     learners who kept using SOLA afterwards. This is the control that
--     makes the whole study a test rather than an impression:
--     if bounced first turns and retained first turns look the same,
--     the first turn is not what drives leaving.
-- ---------------------------------------------------------------
WITH turns AS (
  SELECT id, userid, conversationid, role, message, interaction_type,
         timecreated, prompt_tokens, completion_tokens, courseid
  FROM mdl_local_ai_course_assistant_msgs
  WHERE timecreated >= @since AND role IN ('user','assistant')
),
per_conv AS (
  SELECT conversationid, userid, SUM(role='user') AS user_msgs
  FROM turns GROUP BY conversationid, userid
),
per_user AS (
  SELECT userid, SUM(role='user') AS total_user_msgs FROM turns GROUP BY userid
),
retained_singletons AS (
  SELECT pc.conversationid, pc.userid
  FROM per_conv pc JOIN per_user pu ON pu.userid = pc.userid
  WHERE pc.user_msgs = 1 AND pu.total_user_msgs >= 5   -- they clearly stayed
  ORDER BY MD5(CONCAT('sola-contrast-v1:', pc.conversationid))
  LIMIT 15
)
SELECT CONCAT('R', LPAD(DENSE_RANK() OVER (ORDER BY rs.conversationid), 3, '0')) AS ref,
       t.conversationid, t.courseid, t.role, t.message,
       FROM_UNIXTIME(t.timecreated) AS at_utc, t.prompt_tokens, t.completion_tokens
FROM retained_singletons rs
JOIN turns t ON t.conversationid = rs.conversationid
ORDER BY ref, t.timecreated, t.id;

-- ---------------------------------------------------------------
-- Q3. The cause that reading transcripts CANNOT see: turns that broke.
--     A user row with no assistant row after it is a failed turn — the
--     learner asked and got nothing. Those conversations look identical
--     to "learner left" in any transcript sample.
-- ---------------------------------------------------------------
SELECT
  COUNT(*)                                        AS single_user_turns,
  SUM(assistant_rows = 0)                         AS no_reply_at_all,
  ROUND(100 * SUM(assistant_rows = 0)/COUNT(*),1) AS pct_broken
FROM (
  SELECT m.conversationid,
         SUM(m.role='user')      AS user_rows,
         SUM(m.role='assistant') AS assistant_rows
  FROM mdl_local_ai_course_assistant_msgs m
  WHERE m.timecreated >= @since AND m.role IN ('user','assistant')
  GROUP BY m.conversationid
  HAVING user_rows = 1
) x;

-- ---------------------------------------------------------------
-- Q4. Retrieval-starvation proxy. We do not store retrieved-chunk counts
--     on the message row (only rag_latency_ms), so chunk volume has to be
--     inferred from prompt size. RAG passages are ~66% of a full prompt,
--     so a turn where retrieval returned nothing should sit in a distinctly
--     lower prompt_tokens band. Check the distribution IS bimodal before
--     trusting the proxy — if it is unimodal, the proxy is invalid and
--     hypothesis 4 needs instrumentation instead (see codebook).
-- ---------------------------------------------------------------
SELECT
  FLOOR(prompt_tokens/500)*500 AS prompt_token_bucket,
  COUNT(*)                     AS assistant_turns,
  ROUND(AVG(rag_latency_ms))   AS avg_rag_ms
FROM mdl_local_ai_course_assistant_msgs
WHERE timecreated >= @since AND role='assistant'
  AND interaction_type='chat' AND prompt_tokens > 0
GROUP BY prompt_token_bucket ORDER BY prompt_token_bucket;
