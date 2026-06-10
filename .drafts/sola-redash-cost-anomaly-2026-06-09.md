# SOLA Daily Cost Anomaly Query (Redash)

**Author:** Tom Caswell. **Date:** 2026-06-09. **Status:** ready to paste into Redash; tune the alert threshold + email recipient before saving.

Daily-running query that catches cost spikes early. Compares today's SOLA spend against the rolling 7-day median; emits an alert when today's spend exceeds the median by a configurable multiplier. Useful for catching three failure modes that the existing 80%/95% spend-cap thresholds miss:

1. **Runaway course.** A single course suddenly drives 10x its usual volume (viral ELL traffic, copy-paste bot, debugging loop). The site-wide cap is still well below its ceiling, so the existing alert path stays quiet.
2. **Accidental premium-tier enable.** Someone flips `premium_escalation_enabled` to 1 with the default 5% rate, but the trigger regex matches more turns than expected. Spend climbs sharply for a day before the site-wide cap notices.
3. **Provider misroute.** A failover chain misconfiguration sends all chat to Claude Haiku ($0.39/learner/mo) instead of Gemini Flash ($0.042). 10x cost spike that the spend cap eventually catches; the anomaly query catches it on day 1.

---

## Query (paste into Redash)

```sql
-- SOLA daily cost anomaly: today's spend vs rolling 7-day median.
-- Alert when today > MULTIPLIER * median (default 2.0).
-- Tune the MULTIPLIER + the date window in the WHERE clause to taste.

WITH rate_card AS (
    SELECT 'gpt-4o-mini' AS prefix,                          0.15  AS input_per_1m,   0.60 AS output_per_1m UNION ALL
    SELECT 'gpt-4o',                                          2.50,                   10.00 UNION ALL
    SELECT 'gpt-5',                                           1.25,                   10.00 UNION ALL
    SELECT 'claude-haiku',                                    0.80,                    4.00 UNION ALL
    SELECT 'claude-sonnet',                                   3.00,                   15.00 UNION ALL
    SELECT 'claude-opus',                                    15.00,                   75.00 UNION ALL
    SELECT 'gemini-2.5-flash',                                0.075,                   0.30 UNION ALL
    SELECT 'gemini-2.5-pro',                                  1.25,                    5.00 UNION ALL
    SELECT 'gemini-1.5-flash',                                0.075,                   0.30 UNION ALL
    SELECT 'mistral-small',                                   0.20,                    0.60 UNION ALL
    SELECT 'meta-llama/llama-3.1-8b',                         0.18,                    0.18 UNION ALL
    SELECT 'meta-llama/llama-3.1-70b',                        0.88,                    0.88 UNION ALL
    SELECT 'voyage-3.5',                                      0.06,                    0.00 UNION ALL
    SELECT 'text-embedding-3-small',                          0.02,                    0.00 UNION ALL
    SELECT 'rerank-2.5',                                      0.05,                    0.00
),
costed_msgs AS (
    SELECT
        DATE(FROM_UNIXTIME(m.timecreated)) AS day,
        m.courseid,
        m.model_name,
        m.provider,
        m.interaction_type,
        SUM(COALESCE(m.prompt_tokens,     0)) AS prompt_tokens,
        SUM(COALESCE(m.completion_tokens, 0)) AS completion_tokens,
        -- Pick the longest matching prefix from rate_card (mirrors token_cost_manager).
        (SELECT rc.input_per_1m  FROM rate_card rc
          WHERE LOWER(m.model_name) LIKE CONCAT(rc.prefix, '%')
          ORDER BY LENGTH(rc.prefix) DESC LIMIT 1) AS input_rate,
        (SELECT rc.output_per_1m FROM rate_card rc
          WHERE LOWER(m.model_name) LIKE CONCAT(rc.prefix, '%')
          ORDER BY LENGTH(rc.prefix) DESC LIMIT 1) AS output_rate
    FROM mdl_local_ai_course_assistant_msgs m
    WHERE m.role = 'assistant'
      AND m.model_name IS NOT NULL
      AND m.timecreated >= UNIX_TIMESTAMP() - (8 * 86400)  -- 8 days: today + 7-day window
    GROUP BY day, m.courseid, m.model_name, m.provider, m.interaction_type
),
daily_total AS (
    SELECT
        day,
        SUM(prompt_tokens     * COALESCE(input_rate,  0) / 1000000.0
          + completion_tokens * COALESCE(output_rate, 0) / 1000000.0) AS spend_usd
    FROM costed_msgs
    GROUP BY day
),
windowed AS (
    SELECT
        d1.day,
        d1.spend_usd AS today_usd,
        -- Median of the prior 7 days (exclusive of today).
        (SELECT AVG(s.spend_usd) FROM (
            SELECT spend_usd
              FROM daily_total d2
             WHERE d2.day <  d1.day
               AND d2.day >= d1.day - INTERVAL 7 DAY
             ORDER BY spend_usd
             LIMIT 2 OFFSET 2  -- middle two of seven; AVG = median
        ) s) AS median_7d_usd
    FROM daily_total d1
)
SELECT
    day,
    ROUND(today_usd,    4) AS today_usd,
    ROUND(median_7d_usd, 4) AS median_7d_usd,
    ROUND(today_usd / NULLIF(median_7d_usd, 0), 2) AS ratio,
    CASE
        WHEN median_7d_usd IS NULL          THEN 'insufficient_history'
        WHEN today_usd > 2.0 * median_7d_usd THEN 'ANOMALY'
        WHEN today_usd > 1.5 * median_7d_usd THEN 'watch'
        ELSE 'ok'
    END AS status
FROM windowed
WHERE day = CURDATE()
ORDER BY day DESC;
```

**Alert configuration in Redash:**

- **Trigger:** `status` column equals the string `ANOMALY`.
- **Recipient:** email distribution list configured in SOLA's `spend_notify_emails` setting (or Tom's personal address for testing).
- **Schedule:** daily at 09:00 site-time (after the cron has had time to flush yesterday's metrics).
- **Body template:** `SOLA spend anomaly: today = ${today_usd}; rolling 7-day median = ${median_7d_usd}; ratio = ${ratio}x. Open the per-course breakdown query for the source course.`

**Companion per-course breakdown query (Redash linked dashboard):**

```sql
-- Per-course spend today, sorted desc. Run when the anomaly query fires
-- to identify the source course.
SELECT
    c.shortname,
    c.idnumber,
    m.model_name,
    COUNT(*) AS turns,
    SUM(COALESCE(m.prompt_tokens, 0))     AS prompt_tokens,
    SUM(COALESCE(m.completion_tokens, 0)) AS completion_tokens
FROM mdl_local_ai_course_assistant_msgs m
JOIN mdl_course c ON c.id = m.courseid
WHERE m.role = 'assistant'
  AND m.model_name IS NOT NULL
  AND DATE(FROM_UNIXTIME(m.timecreated)) = CURDATE()
GROUP BY c.shortname, c.idnumber, m.model_name
ORDER BY (SUM(COALESCE(m.prompt_tokens,0)) + SUM(COALESCE(m.completion_tokens,0))) DESC
LIMIT 25;
```

---

## Tuning notes

- **MULTIPLIER 2.0 is conservative.** Lower to 1.5 if you want earlier warnings at the cost of more false positives during the first week of a new course. Raise to 3.0 if Saylor's enrollment is bursty enough that 2x spikes are normal.
- **Median uses the middle 4 of the prior 7 days** (`LIMIT 2 OFFSET 2`, then AVG). For a true median on Postgres you can use `PERCENTILE_CONT(0.5)`. MySQL doesn't have that natively; the AVG-of-middle-N trick is the standard workaround.
- **Insufficient-history handling:** the first 7 days after the query is deployed will show `insufficient_history` instead of `ANOMALY`. Don't alert on those days.
- **Rate-card drift:** the inline CTE is a frozen snapshot. When you bump prices in `classes/token_cost_manager.php`, mirror the change here. Out-of-date rates only affect the absolute spend numbers, not the anomaly ratio (which compares spend to spend in the same units), so drift is graceful.
- **Premium-tier visibility:** the rate-card includes `claude-opus` so the premium escalation tier shows up correctly when it's enabled. Without an Opus row in the rate card, a runaway escalation would silently price at $0.

---

## What this does NOT cover

- **Within-day spikes.** This is a daily query; a 6-hour cost spike at 2 AM may not flag until the next morning. If sub-day visibility matters, run the query hourly with a 24-hour rolling window instead of daily-vs-7-day.
- **Rate-card omissions.** Any model that doesn't match a prefix in the CTE is silently priced at $0. Keep the CTE in sync with `token_cost_manager` as new providers are added.
- **Anthropic / OpenAI cache discounts.** v5.10+ captures cache_read / cache_creation tokens but this query doesn't subtract the discount. Spend numbers will be slightly overstated when prompt-caching is firing; the anomaly ratio is unaffected.
- **Voice / TTS / STT.** Currently deferred per the Saylor baseline. If voice ships, add the `gpt-4o-realtime` and `whisper` rates to the CTE.

---

## Related

- `classes/spend_guard.php` — existing site-wide / per-capability / per-course (v5.13+) cap thresholds (80% / 95% / 100% notification emails). Anomaly query is the early-warning complement, not a replacement.
- `classes/token_cost_manager.php` — authoritative rate card; mirror price updates into the CTE above.
- `.drafts/sola-vendor-optimization-by-mau-2026-06-09.md` — at 100K MAU, the procurement levers (Vertex committed-use, Anthropic/OpenAI enterprise commits) shave ~$400/mo on top of any anomaly-driven savings.
