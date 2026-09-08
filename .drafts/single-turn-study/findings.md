Why a third of SOLA learners stop after one message

Read of 50 single-turn conversations on Learn, with the key result replicated on Degrees
90 days to 24 August 2026. Prepared 24 August 2026; revised same day to add Degrees.


STATUS AS OF 25 AUGUST

Everything this study recommended has shipped or is queued:
  - study-plan chip rewritten on both production sites (config, 24 Aug)
  - Redash query 2332 splits the bounce metric by opener (24 Aug)
  - "Explain This Page" scoped to activity pages, and the page-grounding wiring
    bug behind it fixed, in v7.1.0
  - quiz spend made visible in v7.1.0
  - dropped turns and Quiz Me chip instrumentation: still open

One conclusion in section 3b was revised after implementation proved the stated
cause wrong. That revision is marked inline.


BOTTOM LINE

The premise holds on both sites: 1,306 of 3,813 learners (34.3%) sent exactly one message
and never came back. But three of the four hypotheses we have been weighing are not what
is driving it, and the largest cause was not on the list.

Most learners who bounce never typed a question. They clicked a canned starter chip.
Learners who open with a chip bounce at 39-44%; learners who type their own question
bounce at 24%. Since 54% of all learners open with a chip, that gap accounts for roughly
ten of the thirty-four points. Close it and the bounce rate is 24%, not 34%.

The single worst offender is our own copy. The "study plan" chip literally instructs the
assistant to ask the learner two questions before doing anything. It works exactly as
written, and the learner has to compose a substantive reply to get any value. 910 learners
across the two sites opened with it and 44% never sent that reply.

Nothing was wrong with the answers. In 50 transcripts there were zero wrong answers and
zero cases of retrieval coming up empty and the assistant deflecting.


ALREADY DONE (24 August 2026)

The study-plan chip has been rewritten on both Learn and Degrees. It now proposes a plan
before asking anything:

  "Suggest a focused plan for my study session in this course right now. Propose a
   concrete first step and a realistic 30-minute sequence based on where I am in the
   course, and cite the specific activities to work through. Then ask if I want to
   adjust the time or the focus."

This was a configuration change through the existing admin page, not a code deploy --
both sites run v6.8.2 and neither needed to be touched by Catalyst.

Redash query 2332, "SOLA first-turn outcomes by opener", is published against Learn and
tracks the rewritten chip as its own cohort, so the before/after runs inside the
study-plan population rather than across a shifting traffic mix.


1. THE PREMISE, VERIFIED ON BOTH SITES

                                          Learn      Degrees     Combined
  Learners in window                      3,446          367        3,813
  Sent exactly one message, ever          1,181          125        1,306
  As a percentage                         34.3%        34.1%        34.3%
  Of those, returned on a later day           0            0            0
  Reached five or more messages             846          104          950
  Mean messages per learner                4.38         5.11

Two separate sites with different learner populations land within 0.2 points of each
other. "A third" is right, and "never returned" is literally true within the window.


2. THE FOUR HYPOTHESES, SCORED

The answer was wrong or generic -- NOT SUPPORTED
0 of 50 wrong. Generic only in a specific, fixable case (see section 3b).

The answer was fine and the question was fully resolved, a bounce that is actually a
success -- PARTLY SUPPORTED
About half the sample. Seven logistics questions were answered correctly and completely;
nothing was left to follow up on.

The learner was testing it and never intended to continue -- WEAKLY SUPPORTED
3 of 50 (6%, CI 2-16%). Real but small. Not a third of anything.

Retrieval returned nothing and it deflected -- REFUTED
0 of 50. Across all 14,425 replies on Learn only 52 contain deflection language (0.36%),
and on Degrees 8 of 1,755 (0.46%). This is not happening on either site.

The fourth hypothesis is worth retiring explicitly. It implied expensive work --
reranking, chunk tuning, index coverage -- and the data does not support spending on it
for this problem.


3. WHAT IS ACTUALLY HAPPENING

36 of the 50 bounced learners (72%, CI 58-83%) never typed anything. They clicked one of
three canned starter chips defined in classes/starter_manager.php:

  "I'd like to plan my current study session. Please ask me: (1) what I want to
   accomplish today, and (2) how much time I have available..."                 15 of 50

  "Help me understand {page}. What are the key concepts, and can you explain
   them with examples?"                                                         19 of 50

  "I'd like help with a project or assignment for this course. Can you coach me
   through planning, structuring, and completing it? Ask me what I'm working
   on first."                                                                    2 of 50

Because the chip text is byte-identical every time, this is measurable across the whole
population rather than the sample -- and it replicates independently on Degrees.

                              LEARN                          DEGREES
  Opener              Learners  Bounced  Rate       Learners  Bounced  Rate
  ------------------  --------  -------  -----      --------  -------  -----
  Typed own question     1,588      385  24.2%           159       41  25.8%
  Chip: help me und.       955      399  41.8%           111       44  39.6%
  Chip: study plan         826      363  43.9%            84       35  41.7%
  Chip: project coach       77       34  44.2%            13        5  38.5%

Stripping out dropped turns and resolved logistics questions -- the two categories that
should not count as bounces at all -- the gap widens rather than narrows:

  True bounce rate      Learn    Degrees
  Typed own question    19.7%      22.0%
  Chips                 38.7-41.2% 38.5-40.5%

Chip openers bounce at roughly 1.8x the rate of typed openers on both sites. On Learn,
796 of the 1,181 bounces (67%) began with a chip; if chips converted at the typed rate
there would be 835 bounces instead of 1,181 -- 346 fewer, and a bounce rate of 24.2%.

Two distinct failures are inside that number.

3a. Chips that answer with a question instead of an answer

17 of 50 transcripts (34%, CI 22-48%) ended with the assistant asking the learner to
supply something before it could help -- what are your goals, how much time do you have,
which assignment is it. The model is not misbehaving; the study-plan chip's text
instructed it to do precisely this. The interaction was designed to require a second
learner turn, and 44% of the time it did not get one.

The learner clicked a button because they did not want to type. The reply asked them to
type two answers. This is the failure the 24 August rewrite addresses.

3b. A chip that produces a generic answer because of where it was clicked

The chip named "Explain This Page" sends the prompt "Help me understand {page}...", which
substitutes the current page title. On an activity page that is a real topic. On the
course landing page it is the entire course name, and the answer is a course-catalogue
summary -- so the button's own name is inaccurate exactly where it performs worst.

  "Explain This Page" chip                  Sample   Cited a source   Avg reply
  Fired from the course home page               13          2 (15%)   1,381 ch
  Fired from an actual activity page             5          4 (80%)   1,246 ch

Fired from an activity page this chip is the best thing in the sample: specific,
grounded, citing the course material. Fired from the course home page -- which is where
most learners meet it -- it produces a fluent overview that any general model could have
written without our content. That is the only place the "generic answer" hypothesis is
true, and it is a targeting problem, not a retrieval problem. Fixing it needs a new
display condition, which means code, which means the 7.0.5 upgrade.

REVISED 25 August. The above is right about the symptom and wrong about the cause.
Implementing the fix turned up a second, larger defect: the current activity's
content was never reaching the prompt on ANY page. hook_callbacks computed the
page's course-module id inside a branch gated on empty($PAGE->cm), which is always
true -- moodle_page serves cm through __get() and defines no __isset(), so isset()
and empty() report it unset whatever it holds. So currentpageid stayed 0
everywhere, chat.js never sent pageid at all, and sse.php never page-scoped
retrieval or injected page content.

Which means the five activity-page answers that cited course material got that
from RAG retrieval, not from page content. The chip was broken twice: {page}
expanded to the course name on the course home page, AND the page's text never
arrived regardless. The second is the larger of the two, and it is a wiring bug
rather than a targeting problem. Both are fixed in v7.1.0. The numbers above
describe behavior with page grounding entirely absent, so the chip should improve
by more than the targeting fix alone would predict.


4. THE SECOND CAUSE: ANSWERS THAT WERE NEVER STORED

4 of the 50 conversations (8%, CI 3-19%) have a learner message and no reply at all.
Across the Learn window, 9.5% of conversations contain at least one turn where the
learner asked and nothing was saved. 112 of the 1,181 bounced learners on Learn, and 7
of the 125 on Degrees, never received a single stored reply.

This cause is invisible to transcript reading -- it looks identical to a learner who
simply left -- which is why it is worth stating separately.

What we cannot currently tell is whether these are failures or abandonments. sse.php
saves the learner's message before calling the model (line 268) and the reply only after
the stream finishes (line 885), and the plugin never calls ignore_user_abort(). So if a
learner closes the tab mid-answer, PHP aborts and the reply is never written. A provider
error produces the same empty result. Both look the same in the database.

This is a gap in our instrumentation, not a finding. It should be closed before the next
study, because 9.5% is large enough to matter either way.


5. TWO SMALLER FINDINGS

Language. 3 of 50 learners wrote in Spanish and were answered in English. The cause is a
one-line client bug: amd/src/ui.js sends lang=en whenever the learner has not explicitly
chosen a language, while sse.php treats an empty value as "auto-detect from the student's
writing". Auto-detection is therefore dead code in practice. Before fixing it, note that
all three cases were in ESL courses, where replying in English is arguably correct -- the
assistant said as much. This is a policy question for whoever owns ESL, not simply a bug.

Logistics questions are a success story we are currently counting as a failure. Seven
learners asked about certificates, exams, and assessments. All seven were answered
correctly and completely -- one was properly routed to contact@saylor.org. There was
nothing left to ask. These are hypothesis 2, and they are a reporting problem rather than
a product problem.


6. WHAT TO DO

  DONE 24 Aug   Rewrite the study-plan chip so it proposes before it asks.
                Addresses 3a. 910 learners across both sites, ~44% bounce. Applied to
                Learn and Degrees as configuration; no deploy required.

  DONE 24 Aug   Split the metric. Redash query 2332 reports chip-opened and typed-opened
                separately and separates dropped turns and resolved logistics questions
                from true bounces. A Degrees twin still needs creating.

  NEXT, needs the 7.0.5 upgrade
                Scope the "Explain This Page" chip to real content -- suppress it on the
                course home page, or point it at the learner's next incomplete activity.
                Addresses 3b. 1,066 learners across both sites, ~41% bounce. Grounding
                goes from 15% to 80% on the evidence here.

                Make dropped turns visible. Add ignore_user_abort(true) and persist a
                partial or error row so failure and abandonment stop looking identical.

                Instrument the Quiz Me chip. It is type "quiz" and opens a panel rather
                than sending a message, and generate_quiz.php writes nothing to the
                message table -- so it produced zero rows in 90 days on either site. We
                have no evidence about it at all, in either direction.

                Persist chunk_count and top retrieval score on the assistant row, so the
                retrieval question is answerable directly next time.

  PARKED        Answer in the language the learner wrote in. Pending the ESL policy call
                above.

Do not fund reranking or chunk tuning on the strength of this problem. Hypothesis 4 was
the case for that work and it is refuted on both sites.

Hold chip reordering until the study-plan rewrite has been measured. Reordering changes
which chips get clicked, which moves cohort composition and confounds the read.


7. METHOD

Source. Production databases for learn.saylor.org and degrees.saylor.org via the Redash
data sources, read-only. 90-day window to 24 August 2026. Rows restricted to
role IN ('user','assistant') and interaction_type = 'chat'; internal telemetry rows
written to the same table are excluded.

Sample. 50 learners on Learn whose entire SOLA history in the window is a single chat
message, selected by MD5 hash of the user id rather than RAND(), so the same 50 are
reproducible and a second coder can be given the identical set. All 50 were read in full.
Degrees was not sampled qualitatively; it was used to test whether the quantitative
result replicates, which it does.

Population checks. Opener classification, deflection scan, and dropped-turn rates were
computed over all 3,813 learners and all 16,180 replies, so those figures carry no
sampling error. Only the codes that required reading are subject to the confidence
intervals shown.

Privacy. Learner names, ids, and message text stay in the database and on a local private
volume outside the git repository. No learner is identifiable from this document; quoted
fragments are either our own product copy or generic phrases.

Correction to our own method. We initially planned to detect retrieval starvation from
prompt_tokens, on the reasoning that retrieved passages dominate the prompt. That proxy
is invalid: several replies recorded 8 to 53 prompt tokens while returning the most
thoroughly grounded answers in the sample, complete with source citations. The field is
recorded differently by different providers. Any analysis resting on it would have been
wrong.


8. WHAT THIS CANNOT TELL YOU

The chip effect is a correlation. Learners who click a button may simply be less
motivated than learners who type a question, and that selection effect could account for
some of the gap. Replication across two sites makes it less likely to be the whole story,
but does not rule it out. The rewrite is measurable as a before/after inside the
study-plan cohort, which does control for it -- that reading is the real test, and it is
not in yet.

We have no end-of-session event, so we cannot distinguish a learner who read an answer
and left satisfied from one who closed the tab immediately. That distinction is the
difference between hypothesis 2 being a success and being a failure, and no amount of
transcript reading resolves it. If it matters -- and for judging the 18 course-overview
answers it does -- the way to settle it is to ask learners.

The qualitative texture comes from Learn, and 60% of that sample falls in four ESL
courses. Degrees confirms the numbers, not the reading.

Expect the next read to be slow. The rewritten chip only affects learners who click it
from 24 August onward, and at n=50 the confidence interval is still around 13 points
either way. Give it a few hundred learners before drawing conclusions.

One conclusion has already been revised. Section 3b attributed the "Explain This
Page" chip's generic answers to where it was clicked. Implementing the fix showed
the deeper cause was that page content never reached the prompt at all. Reading
transcripts told us the chip underperformed, and roughly where; it could not tell
us why, and the mechanism we inferred from the transcripts alone was wrong. Worth
remembering the next time a study like this produces a confident causal story.

Reproducing this. Queries, codebook, and the transcript-pairing script are in
.drafts/single-turn-study/ in the plugin repo. Raw transcripts are deliberately not
there; they are outside the repo, which is public.
