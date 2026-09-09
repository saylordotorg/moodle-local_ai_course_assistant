## Part 6 — Soapbox speaking levels (~20 min) — NEW

**What this covers.** Each course can be set to a speaking level, and that one
setting is supposed to change *two* things: the rubric a speech is scored
against, and how the AI talks to the learner. It is easy for a change like that
to move one and not the other, so most of these steps ask you to check both.

**Where the setting lives:** open a course → SOLA course settings (the wrench in
the chat header, or the course "More" menu) → **Course type / speaking level**.
The four choices are **General speech** (the default), **ESL beginner**, **ESL
intermediate**, and **ESL advanced**.

**One thing to know before you start:** each level has its own five scoring
criteria, and they are *named differently*. That is the easiest way to tell
which level actually took effect — you do not have to judge the AI's wording.

| Level | The five criteria you should see |
|:--|:--|
| General speech | Delivery & Fluency · Structure & Organization · Content & Relevance · Language & Vocabulary · Time Management |
| ESL beginner | Pronunciation & Intelligibility · Fluency & Pace · Basic Grammar · Core Vocabulary · Task Completion |
| ESL intermediate | Pronunciation & Clarity · Fluency & Pace · Grammar · Vocabulary · Organization & Coherence |
| ESL advanced | Fluency & Naturalness · Pronunciation & Stress · Grammatical Range & Accuracy · Vocabulary & Idiom · Coherence & Development |

You will need a course with Soapbox enabled and a speech assignment set up, and
you will record a short talk several times. **30–40 seconds of ordinary speech
is plenty** — you are checking which rubric comes back, not trying to score
well. Saying the same thing each time actually makes the comparison easier.

| # | Do this | You should see | Result | Notes |
|:-:|:--|:--|:-:|:--|
| 33 | In SOLA course settings, confirm **Course type / speaking level** is present, and note what it is currently set to | The dropdown exists with all four options; note the starting value so you can put it back | [ ] | |
| 34 | Set it to **ESL beginner** and save | Saves cleanly and still reads "ESL beginner" when you reload | [ ] | |
| 35 | As a student, record a short speech in that course and let it score | Feedback comes back against the **ESL beginner** criteria in the table above (Pronunciation & Intelligibility, Basic Grammar, Core Vocabulary…) — *not* the General set | [ ] | |
| 36 | Read the AI's written feedback for that attempt | The wording is pitched for a beginner learner of English — simpler sentences, encouraging, focused on being understood. If it reads like feedback for a fluent presenter, the level reached the rubric but not the coaching | [ ] | |
| 37 | Change the level to **ESL advanced**, save, and record again | Now the **ESL advanced** criteria (Grammatical Range & Accuracy, Vocabulary & Idiom, Coherence & Development). The rubric must actually change between attempts | [ ] | |
| 38 | Compare the two sets of written feedback side by side | The advanced feedback is noticeably more demanding — idiom, stress, naturalness — rather than the same text with different headings | [ ] | |
| 39 | Set the level to **ESL intermediate**, save, record again | The **ESL intermediate** criteria. This level was added later than the others, so it is the most worth confirming | [ ] | |
| 40 | Set the level back to **General speech**, save, record again | The **General speech** criteria (Delivery & Fluency, Structure & Organization, Time Management…) | [ ] | |
| 41 | Open **Rubric editor** (Site administration → …AI Course Assistant → Rubric Editor) and use **Load a sample rubric** | You can load each of the four presets, and what loads matches the criteria in the table above | [ ] | |
| 42 | Load the **ESL beginner** sample, edit one criterion's wording, and save it for your test course | It saves as that course's own rubric | [ ] | |
| 43 | Record one more speech in that course | Your **edited** criterion appears in the feedback — a rubric you saved for the course takes precedence over the level's built-in one | [ ] | |
| 44 | Now set the course level to **ESL advanced** while your saved course rubric is still in place, and record again | Your saved course rubric still wins. **This is the subtle one:** a rubric saved for the course should outrank the level, but a generic site-wide rubric must *not* silently replace a course's ESL criteria. If changing the level wiped out your edited rubric, that is a bug worth reporting in detail | [ ] | |
| 45 | Check **My speeches** after all this | Every attempt is listed with a real date and a status, and the scores belong to the right attempts | [ ] | |
| 46 | Put the course's speaking level back to whatever you noted in step 33 | Restored | [ ] | |

### Notes for whoever reads the results

- **Audio is never stored.** Only the transcript's score and metadata are kept,
  so there is no recording to go back and re-listen to. If an attempt scores
  oddly, the useful thing to capture is the transcript text shown on screen plus
  which level was set.
- **If a level's feedback looks right but its criteria are wrong** (or the other
  way round), say which one was wrong. Those are two different code paths and
  knowing which half broke is most of the diagnosis.
- **Steps 35–40 are the core of this section.** If you are short on time, do
  those four and step 44.
