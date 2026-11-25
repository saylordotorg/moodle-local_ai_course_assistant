# SOLA answer audit, guidance rules and unsupported-answer review: design (target 8.4.0)

Status: APPROVED by Tom 2026-11-23 (with his three answers below). Nothing is built yet.

## Intent and why
Tom wants to track and audit SOLA's reasoning with students, mainly to make sure it does not hallucinate or give
inappropriate guidance or information. Two jobs at once: protect the student in the moment, and keep an audit trail.
Ideas came from two AISquare slides (ground, inject, enforce, assess, learn, fix; trustworthy agents need grounding,
explainable reasoning, human oversight, a learning loop, audit) and from the Trust and Safety Institute (TSI) TSI Score
areas (explainability, data and knowledge stewardship, runtime governance, accountability). TSI's scoring method is
unpublished and it says nothing about tutors, so TSI is a checklist for the shape of our evidence, never a claim.

## What exists today (checked in the code)
- Per assistant message: model, provider, tokens, `chunk_count`, `top_score`, `stream_outcome`, source tag.
- `runtime_guard::apply()` runs `hallucination_validator` (numbers only: years, percentages, dollar amounts, compared with
  the retrieved text) and other validators on the finished reply in `sse.php`, mode off/annotate/block (`validators_runtime_mode`).
  It sees only `input`, `userid`, `courseid`; `rag_chunks` is not passed, so the numeric check is a no-op in production (confirmed at sse.php:1106).
- Thumbs-down ratings (with an `is_hallucination` flag), off-topic and integrity flags feed `review_queue` (sources
  rating/offtopic/integrity, with a resolve action and note).
- Background work uses adhoc tasks (`classify_conversation_turn` is the model to copy). `audit_logger` writes the SOC2 audit table.
- Replies stream to the browser over SSE, so text can be on the student's screen before any check on the whole reply.

## Goals
1. Every assistant answer gets a time-stamped, append-only record: what the student asked, what the answer said, which
   sources were retrieved and cited, which rules fired, what each claim rested on, and the verdict.
2. Risky guidance is stopped or corrected before the student relies on it.
3. Answers that are unsupported by the course material are found, shown to teachers in the review queue, and fed back as
   course-content or rule fixes.
4. A teacher or admin can see, filter and export the trail.

## Non-goals
- No TSI integration, no "Trust Tag" or rating claim anywhere (SOLA, Marketplace, videos) until TSI issues one.
- No automatic rewriting of prompts or rules (the "learn" step is a human decision fed by reports).
- Voice modes: v1 records and rules-checks the text of voice turns only where the text path is shared; full voice parity later.
- No cross-site sharing of findings.

## Anti-goals (failure even if it works)
- Blocking or "correcting" good answers often enough that learners stop trusting SOLA.
- Added delay on ordinary answers; a surprise model bill.
- Storing more learner data than the conversation record already holds, or keeping it longer.

## Constraints
- Moodle plugin, PHP 8.1-8.3, Moodle 4.5-5.3, MariaDB and Postgres; AMD source and build both committed.
- Reuse the existing providers, `spend_guard` caps and cost attribution; the checker model is a setting (cheap default).
- Privacy: new table in the privacy provider (export, delete); retention follows `conversation_retention`.
- Capabilities and sesskey on every endpoint; instructor access scoped to their courses; no key or prompt text in the trail.
- Fail-safe: if the checker or a rule errors, the answer is delivered as today and the failure is recorded.

## Approaches considered
1. Gate every answer with a second model before it is shown: strongest, but doubles latency and cost on every reply and
   forces buffering of streamed text.
2. Audit after the fact only: cheap, but a student can act on a bad answer before anyone sees it.
3. Hybrid (chosen by Tom, 2026-11-23): deterministic rules before sending, only where the topic is risky; every answer's
   claims recorded and checked in the background; a serious miss triggers a correction to the student and a review item.

## Design (hybrid)
### A. Answer record (new table `local_ai_course_assistant_answer_audit`)
One row per assistant message: messageid, conversationid, userid, courseid, cmid, created time, mode (text/voice),
model/provider, retrieved chunk ids with scores, cited source tags, risk categories, rules fired and action taken,
claims (JSON: text, type, status supported/unsupported/contradicted/uncheckable, evidence chunk id and short excerpt),
verdict (ok/warn/blocked/needs_review), severity, checker model and cost, checked time, and `rowhash` = sha256 of the row
plus the previous row's hash for that course (tamper-evident chain, my call). Rows are never updated except to add the
background verdict (a separate append row with `supersedes`, my call, so the original stays as written).
Export: CSV and JSON for admins/teachers (same pattern as `spend_export`).

### B. Guidance rules (in the moment)
- A rulebook: code-defined default pack plus site/course additions in admin settings. Categories: grades and marks,
  deadlines and dates, enrollment/financial aid/policy, wellbeing and crisis, medical/legal/financial advice, academic
  integrity, and unsupported numbers.
- Each rule: category, trigger (patterns on the question and/or the reply), action: block, correct, warn, watch.
  - block: replace with a safe message that names who to ask (instructor or support; reuse the escalation path).
  - correct: one bounded retry with the failure stated to the model; if it fails again, block.
  - warn: deliver with a visible "check this with your instructor" line.
  - watch: deliver, record, queue for review.
- Timing: the question is classified before the model call (cheap, deterministic). Risky categories are buffered then
  checked, then sent; everything else streams as today and is checked right after, with a new SSE `replace` event that
  swaps in the safe text if a rule blocks (rare).
- Pass the retrieved chunks into `runtime_guard` so the existing numeric check actually runs.
- Mode per feature: off / watch (record only) / enforce, site setting with per-course override. Default off on upgrade; on in watch on dev.

### C. Claim check (background)
- Adhoc task per answer: split the reply into factual claims, check each against the chunks that were retrieved for
  that turn with a cheap judge model (structured output), write the verdict row.
- Sampling and caps: all answers that had retrieval, N% of the rest (setting), daily spend cap via `spend_guard`.
- On `contradicted` or high-severity `unsupported` after delivery: send the student a short correction message in the
  same conversation (rate-limited, wording in language strings) and queue the answer for review.

### D. Review queue
- `review_queue` gains sources `claim` and `rule`; each item shows question, answer, claims with their evidence, rules fired.
- Teacher actions: confirmed bad / false alarm / content gap / rule too strict, plus a note. Counts feed a report:
  top unsupported topics per course (the "enrich context" signal), rules by hit rate and false-alarm rate.

### E. Admin and teacher UI
Settings group "Answer safety" (modes, checker model, sampling, caps, rulebook editor, student wording); an "Answer audit"
page (filters by course, verdict, rule, date; open one answer; export). Teachers see their courses only.

## Rollout and checks
Three slices in one release (8.4.0): record + export, rules + `replace` event, claim check + review items + correction.
Tests: PHPUnit per class, a JS check for the `replace` event in source and build, planted-defect tests for each rule
action, a recorded-fixture set of known-bad and known-good answers to measure false alarms, and a live check on dev.
Measure before enforcing: false-alarm rate from teacher verdicts, added latency on streamed answers, checker cost per 1,000 answers.

## Left to the builder
Table and column names; rule pattern syntax; the judge prompt and schema; hash chain details; UI layout; language strings.

## Calls made after the builder check (mine; Tom can overrule)
- Claim verdicts are three-way: supported by retrieved course text; not in the course but true (content-gap signal only,
  no correction, judge may use its own knowledge); contradicted or likely false (review item, and a correction when severe).
  Severity comes from the rule category the claim falls in plus the claim type (a date, grade or policy claim is high).
  Answers with no retrieval are judged on the "true or likely false" question only, at the sampled rate.
- A correction is stored as an assistant message in the conversation and shows the next time the widget loads or the
  student sends a message; nothing is pushed. It does not count toward the conversation cap and is skipped if the student
  cleared the conversation.
- The rulebook runs alongside `runtime_guard`. Existing validators are recorded as rules fired. Where both act, the
  stricter action wins. A site in block mode keeps exactly today's behavior after upgrade.
- "Correct" applies only to buffered (risky-topic) answers. On a streamed answer a rule can warn or block, not retry.
- v1 rules are written by site admins only (keywords and a restricted pattern syntax with length and time limits).
  Course-level rules by teachers are later.
- The student sees nothing unless a rule trips or a correction is sent.
- Audit rows follow the conversation retention setting by default, with its own override.

## Tom's answers (2026-11-23: "Go with your recs")
1. On a student's chat clear or a privacy erasure, the audit row keeps metadata, verdicts, rules fired, sources, timestamps
   and hashes, and drops the question, answer and claim text; a hash-only tombstone keeps the chain checkable.
2. The review queue is for course teachers on their own course (existing instructor dashboard). A site-wide admin page is later.
3. The wellbeing and crisis message is fixed default text pointing to the instructor or support and to local emergency
   services for immediate danger, admin-editable, with no per-country resource list. A Saylor student-support person should
   read it before enforcement is turned on.
