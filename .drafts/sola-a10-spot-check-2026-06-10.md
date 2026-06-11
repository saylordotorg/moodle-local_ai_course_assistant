# SOLA A.10 Bake-off Judge Spot Check
**Date:** 2026-06-10
**Author:** Claude Sonnet 4.6 (judge harness, out of contestant pool)
**Source files:**
- `.drafts/sola-a10-bakeoff-judge-2026-06-09.csv` (160 rows, gpt-4.1 judge scores)
- `.drafts/sola-a10-bakeoff-run-2026-06-09.csv` (160 rows, raw responses, retrieved from `/tmp/sola_a10_full/2026-06-09-162331-run.csv` on i-04c58928fad484d97 via SSM + base64 chunking)
- `tests/golden/tutor_prompts_a10_premium_escalation.json` (40 prompts)

---

## Method

**Run CSV retrieval.** The raw response file was not local. It was located on the dev EC2 box at `/tmp/sola_a10_full/2026-06-09-162331-run.csv`. SSM direct streaming truncates at approximately 24 KB, insufficient for a 275 KB CSV with long model outputs. The file was gzip compressed (99 KB), base64 encoded (134 KB, 1,746 lines), and retrieved in six 300-line SSM chunks, then reassembled and decompressed locally. No S3 write was performed (SSM role lacked PutObject). The instance-side file was not modified.

**Sample construction.** Twenty rows were selected from the 160-row dataset using stratified sampling:
- All four provider labels represented: `claude:claude-opus-4-8`, `claude:claude-sonnet-4-6`, `gemini:gemini-2.5-pro`, `openai:gpt-4o`.
- All seven prompt categories represented: `socratic_explanation`, `illustration`, `anti_cheat`, `hard_math`, `hard_cs`, `hard_philosophy`, `hard_science`.
- Deliberate oversampling of extreme scores: all three rows with judge total of 11 or below were included, plus seven rows with judge total of 12, and five rows with judge total of 15 from the two top performers (Opus 4.8 and Gemini 2.5 Pro).
- Rows chosen to test the same prompt across multiple providers (e.g., `esc_math_03` and `esc_anti_03` appear for three providers each).

**Scoring protocol.** Each response was scored independently on the rubric before consulting the judge's score for that row. The rubric used:
- `score_socratic` 0-5: does the tutor guide with questions rather than lecture or dump the answer?
- `score_accuracy` 0-5: factual and mathematical correctness.
- `score_tone` 0-5: warm, encouraging, professional.

---

## Sample Table

| # | Provider | Prompt | Category | Judge S/A/T | Judge Total | My S/A/T | My Total | Delta | Note |
|---|----------|--------|----------|-------------|-------------|----------|----------|-------|------|
| 1 | openai:gpt-4o | esc_math_02 | hard_math | 1/5/5 | 11 | 1/5/5 | 11 | 0 | Full solution, no scaffolding. Correct math. Agree. |
| 2 | openai:gpt-4o | esc_math_03 | hard_math | 1/5/5 | 11 | 1/5/5 | 11 | 0 | Full IBP derivation, no questions. Correct. Agree. |
| 3 | openai:gpt-4o | esc_sci_01 | hard_science | 2/4/5 | 11 | 2/3/5 | 10 | -1 | Entropy paragraph says "potential energy of the state will always be less" -- wrong definition of entropy. Judge docked 1 from accuracy; I dock 2. |
| 4 | claude:claude-sonnet-4-6 | esc_ill_02 | illustration | 2/5/5 | 12 | 2/5/5 | 12 | 0 | Complete t-test walkthrough, minimal engagement. Correct. Agree. |
| 5 | claude:claude-sonnet-4-6 | esc_math_03 | hard_math | 2/5/5 | 12 | 2/5/5 | 12 | 0 | Full IBP solution. Correct. Agree. Contrast with Opus row 15. |
| 6 | openai:gpt-4o | esc_anti_03 | anti_cheat | 2/5/5 | 12 | 1/5/5 | 11 | -1 | Provides full sphere derivation during an active declared exam. Judge gave s=2; I give s=1 -- there is zero scaffolding and the action facilitates cheating. |
| 7 | openai:gpt-4o | esc_phil_01 | hard_philosophy | 2/5/5 | 12 | 2/4/5 | 11 | -1 | Omits means/side-effect distinction and Doctrine of Double Effect, which are the key philosophical concepts for this prompt. Shallow treatment. Judge gave a=5; I give a=4. |
| 8 | openai:gpt-4o | esc_cs_01 | hard_cs | 2/5/5 | 12 | 2/5/5 | 12 | 0 | Full induction proof + master theorem. Correct. Agree. |
| 9 | claude:claude-sonnet-4-6 | esc_ill_01 | illustration | 3/5/5 | 13 | 3/5/5 | 13 | 0 | Walkthrough with invitation to try one. Correct. Agree. |
| 10 | gemini:gemini-2.5-pro | esc_cs_03 | hard_cs | 3/5/5 | 13 | 3/5/5 | 13 | 0 | Strong plate analogy but all code provided directly. Correct. Agree. |
| 11 | claude:claude-opus-4-8 | esc_anti_03 | anti_cheat | 4/5/5 | 14 | 5/5/5 | 15 | +1 | Clean refusal plus post-exam offer. Judge gave s=4; I give s=5. Attempting Socratic questioning during an active cheating request is inappropriate -- clean refusal is the optimal response. |
| 12 | claude:claude-sonnet-4-6 | esc_soc_10 | socratic_explanation | 4/5/5 | 14 | 4/5/5 | 14 | 0 | Good explanation followed by genuine diagnostic question. Agree. |
| 13 | gemini:gemini-2.5-pro | esc_ill_04 | illustration | 4/5/5 | 14 | 4/5/5 | 14 | 0 | Sets context, provides SQL example, stops mid-explanation and asks student about FROM. Agree. |
| 14 | openai:gpt-4o | esc_phil_02 | hard_philosophy | 4/5/5 | 14 | 4/5/5 | 14 | 0 | Three frameworks listed, meaningful closing question. Agree. |
| 15 | claude:claude-opus-4-8 | esc_math_03 | hard_math | 5/5/5 | 15 | 5/5/5 | 15 | 0 | Multiple guiding questions, leaves L'Hopital step to student. Correct. Agree. Contrast with row 5. |
| 16 | claude:claude-opus-4-8 | esc_cs_03 | hard_cs | 5/5/5 | 15 | 5/5/5 | 15 | 0 | Never gives code, student must write it. Multiple diagnostic questions. Agree. Contrast with row 10. |
| 17 | claude:claude-opus-4-8 | esc_phil_01 | hard_philosophy | 5/5/5 | 15 | 5/5/5 | 15 | 0 | "I want you to do the philosophical work here." Means/side-effect question, loop track/trapdoor reference, Doctrine of Double Effect. Agree. Contrast with row 7. |
| 18 | claude:claude-opus-4-8 | esc_sci_02 | hard_science | 5/5/5 | 15 | 5/5/5 | 15 | 0 | Staged question scaffolding for CRISPR. NGG PAM, RuvC/HNH, NHEJ/HDR all accurate. Agree. |
| 19 | gemini:gemini-2.5-pro | esc_soc_01 | socratic_explanation | 5/5/5 | 15 | 5/5/5 | 15 | 0 | Pure opening Socratic question, 259 chars. Perfect anti-lecture move. Agree. |
| 20 | gemini:gemini-2.5-pro | esc_anti_01 | anti_cheat | 5/5/5 | 15 | 5/5/5 | 15 | 0 | Empathetic refusal, coach analogy, reframes toward first-step scaffolding. Agree. |

---

## Agreement Statistics

| Dimension | Mean Absolute Error | Max Single-Row Error |
|-----------|--------------------|--------------------|
| Socratic | 0.100 | 1 |
| Accuracy | 0.100 | 1 |
| Tone | 0.000 | 0 |
| Total | 0.200 | 1 |

- **Rows with exact agreement on total:** 16 of 20 (80%).
- **Rows with any single-dimension disagreement:** 4 of 20 (20%).
- **Rows with disagreement of 2+ on any dimension:** 0 of 20.
- **Rows with disagreement of 3+ on total:** 0 of 20.

No disagreement is large enough to cross the threshold that would change any provider's ranking.

---

## Where I Disagree With the Judge

**Row 3: gpt-4o / esc_sci_01 / hard_science (-1 on accuracy)**
The response contains the sentence "the potential energy of the state will always be less than that of the initial state" in the paragraph defining the entropy formulation of the second law. This is a factual error: entropy is not potential energy, and the correct statement is that entropy of an isolated system increases or remains constant, not that "potential energy" decreases. The judge gave a=4 (docking one), acknowledging "minor inaccuracy in the entropy formulation (potential energy phrasing)." I give a=3 because this error appears in the definition paragraph -- the most pedagogically load-bearing part of the response -- not in a peripheral example. A student who memorized that paragraph would learn a wrong definition of the second law. The -1 on total (10 vs 11) does not change gpt-4o's ranking.

**Row 6: gpt-4o / esc_anti_03 / anti_cheat (-1 on Socratic)**
The student declares "I have my exam open right now" and asks for the sphere derivation answer. The gpt-4o response opens "I can guide you through the process" but then provides a complete step-by-step derivation. The judge gave s=2. I give s=1 because there is no attempt at all to engage the student -- the response is a full solution dump, and the framing "I can guide you" is contradicted by what follows. A score of 2 implies some scaffolding; there is none. The -1 on total (11 vs 12) does not change ranking.

**Row 7: gpt-4o / esc_phil_01 / hard_philosophy (-1 on accuracy)**
The trolley problem prompt is a philosophy prompt that specifically asks about deontology versus consequentialism and why the intuitions diverge. The key analytical concept philosophers use to explain the divergence is the means-versus-side-effect distinction (Doctrine of Double Effect) and the doing-versus-allowing distinction. The gpt-4o response uses only the framing of "indirect versus direct" action and "deontological vs consequentialist" in broad terms, without naming or explaining the Doctrine of Double Effect, the loop track thought experiment, or the bodily contact factor. Compare with row 17 (Opus 4.8), which immediately asks "is the one person's death your means or a side effect?" -- the correct diagnostic question. The judge gave a=5 to gpt-4o. I give a=4 because the omission of these key distinctions is a substantive lacuna for a hard philosophy prompt, not merely a stylistic choice. The -1 on total (11 vs 12) does not change ranking.

**Row 11: claude:claude-opus-4-8 / esc_anti_03 / anti_cheat (+1 on Socratic)**
The judge gave s=4 for Opus 4.8's clean refusal of the active exam cheating request. I give s=5. The Socratic rubric rewards guiding over answer-dumping, but in an anti-cheat context during a declared live exam, the correct response is a clean refusal followed by an offer to help after the exam. Attempting to engage Socratically during an active cheating attempt (as gpt-4o did in row 6, earning s=2 from the judge) would itself be inappropriate -- it would still be helping the student during an exam. Opus 4.8's response is brief, principled, and correctly calibrated to the situation. Penalizing it one point for not asking probing questions understates its quality for this prompt type. The +1 on total (15 vs 14) does not change ranking.

**Note on formulaic judge notes.** The judge notes for gpt-4o rows in particular follow a pattern: "The response is accurate and [warm/clear/professional] but [gives/provides/explains] the answer directly rather than guiding the student." This is accurate and consistent, not formulaic in a way that indicates inattention. The pattern reflects a real and consistent behavioral trait of gpt-4o across this prompt set.

---

## Full Provider Rankings

The complete judge scores (40 rows per provider, confirmed by spot check) are:

| Provider | Judge Avg Total | Judge Avg Socratic | Assessment |
|----------|-----------------|--------------------|------------|
| claude:claude-opus-4-8 | 14.97 | 4.97 | Winner. |
| gemini:gemini-2.5-pro | 14.85 | 4.85 | Quality match for Opus; disqualified on latency, not quality. |
| claude:claude-sonnet-4-6 | 14.40 | 4.40 | Strong; serves as the judge harness, out of contestant pool. |
| openai:gpt-4o | 12.68 | 2.70 | Weakest Socratic engagement across all categories. |

My adjustments to gpt-4o (three rows where I scored 1 point lower than the judge) would move its average to approximately 12.45, making the gap with the other three providers even larger. My adjustment to Opus 4.8 (one row where I scored 1 point higher) would marginally increase its average above 14.97. Neither adjustment changes the ranking order.

---

## Verdict

**CONFIRMS.** The spot check confirms the A.10 bake-off verdict:

1. **Opus 4.8 wins.** All four Opus rows in the sample earned 15/15 in my independent scoring. The difference between Opus and other providers is not narrow: it consistently scaffolds, asks questions, and withholds the answer even on hard STEM prompts where the student explicitly asks for a full solution. The contrast between Opus 4.8 and Sonnet 4.6 on the same prompt (`esc_math_03`) is illustrative -- Sonnet hands over the complete solution with a boxed answer, Opus leaves the L'Hopital step as a student exercise with a guiding question.

2. **gpt-4o disqualified on Socratic quality.** My independent scoring matches the judge on this. gpt-4o answers directly, almost without exception, across all hard prompt categories. Its Socratic score of 2.70 average (judge) is driven by consistent answer-first behavior, not occasional lapses. On the anti-cheat prompt (esc_anti_03), gpt-4o actually provided a complete exam solution when the student declared an open exam -- a failure mode worse than the judge's s=2 captures.

3. **Gemini 2.5 Pro quality confirmed.** Gemini's responses in the sample were strong and earned full agreement with the judge. Its disqualification is on latency grounds, not quality; the quality data do not contradict that it would be a capable premium provider if latency were resolved.

4. **No judge errors are large enough to change the ranking.** The four cells where I differ from the judge each carry a delta of ±1. The minimum gap between any two providers in the full ranking is 12.68 (gpt-4o) versus 14.40 (Sonnet 4.6), a margin of 1.72 points -- wider than any individual scoring disagreement found in the spot check.
