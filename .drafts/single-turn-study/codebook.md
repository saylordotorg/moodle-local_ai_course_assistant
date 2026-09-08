# Codebook — SOLA single-turn study

Every transcript gets coded on **two observable axes** first, and a cause is
**derived** from the pair. This matters: "the answer was generic" and "the answer
was fine and the question was resolved" are the same observation plus a different
judgment. Splitting the observation from the judgment is what lets a second coder
agree with the first, and what stops the four hypotheses from being scored on vibes.

Code the axes without looking at the hypothesis list. Derive the cause afterwards.

## Axis 1 — what the learner asked (observable)

| Code | Meaning | Test |
|---|---|---|
| `Q-CLOSED` | Closed-ended, one right answer | "What does GDP stand for?" — a complete answer ends the exchange |
| `Q-OPEN` | Open-ended or conceptual | "Why does inflation hurt savers?" — invites a follow-up |
| `Q-LOGISTIC` | About the course, not its content | Deadlines, certificates, exam mechanics, re-enrolment |
| `Q-META` | About the assistant itself | "what are you", "are you AI", "hello", keyboard-mashing |
| `Q-OFFTOPIC` | Outside the course entirely | Unrelated homework, personal questions |
| `Q-UNCLEAR` | Cannot tell what was being asked | Fragment, single word, no verb |

## Axis 2 — what came back (observable)

| Code | Meaning | Test |
|---|---|---|
| `A-SPECIFIC` | Correct and grounded in *this* course's material | Names a concept, section, or module a general model could not have guessed |
| `A-GENERIC` | Correct but could have been written without the course | True, fluent, and course-agnostic |
| `A-WRONG` | Contradicts the course material or is factually false | Requires checking against the source |
| `A-DEFLECT` | Declines to answer, redirects, or says it cannot find it | Includes "open the specific page" phrasing from the truncation hint |
| `A-REFUSED` | Topic guard or safety refusal | Cross-check `offtopic_count > 0` |
| `A-NONE` | No assistant row, or an error string | From Q3; **invisible if you only read transcripts** |

## Derived cause — and the fix each one implies

The point of the study. Each cause routes to a different team and a different backlog.

| Cause | Derived from | Fix it implies |
|---|---|---|
| **Answer was weak** | `A-GENERIC` or `A-WRONG` | Retrieval quality and prompt work — reranking, chunk size, `rag_topk`. This is the expensive one. |
| **Resolved successfully** | `Q-CLOSED` + `A-SPECIFIC` | **Reporting change, not a product change.** Stop counting these as bounces; split the metric. |
| **Never intended to continue** | `Q-META` or `Q-OFFTOPIC` | Exclude from the denominator. Possibly a cheaper greeting path. |
| **Retrieval starved** | `A-DEFLECT` + low `prompt_tokens` | Indexing coverage — is the course indexed at all? Is the answer in an unindexed activity type? |
| **Wrong tool for the job** | `Q-LOGISTIC` | Routing: hand off to support or the course page. No amount of retrieval work fixes this. |
| **It broke** | `A-NONE` | Reliability bug. Highest severity, lowest visibility. |
| **Blocked by the guard** | `A-REFUSED` | Tune the off-topic threshold. |
| **Indeterminate** | anything else | If this exceeds ~15%, the codebook is wrong, not the data. |

## Per-transcript record

```
ref,course,q_code,a_code,cause,confidence,closed_ended,prompt_tokens,rag_ms,thumbs,note
```

`confidence` is `high|low`. `note` is one sentence, and must contain **no learner
text that could identify anyone** — paraphrase, never paste.

## Rules

1. **Code the contrast set blind.** Mix the 15 retained-learner transcripts (Q2)
   into the 50 and code all 65 without knowing which is which. If bounced and
   retained first turns code the same way, the first turn is not the cause and the
   entire premise needs rethinking — that is a real possible finding, not a failure.
2. **Never infer satisfaction from brevity.** A short exchange is evidence of
   nothing on its own; that assumption is what the study exists to test.
3. **Check `A-WRONG` against the course.** A wrong-answer code that was not
   verified against the actual material is an opinion. Downgrade to `low`
   confidence if the material was not checked.
4. **Report the indeterminate rate.** It is the honesty check on everything else.

## Known measurement gaps

- **Retrieved-chunk count is not stored.** `msgs` carries `rag_latency_ms` but no
  chunk count or score. Retrieval starvation is therefore inferred from
  `prompt_tokens` (Q4), which is a proxy and is only valid if that distribution is
  bimodal. **Fix forward:** persist `chunk_count` and top score on the assistant
  row. It is a small additive column and it makes this question answerable
  directly next time instead of by inference.
- **No end-of-session event.** We cannot distinguish "read the answer and left
  satisfied" from "closed the tab mid-stream". Nothing in the current schema
  separates these, and no amount of transcript reading will.
- **Thumbs are sparse**, so `rating` corroborates a code when present and means
  nothing when absent.
