## Growing usage: where the losses actually are

Adoption is 2.88% of learners who can already see SOLA. Before proposing
remedies it is worth being precise about which step is leaking, because the two
candidate stories imply very different work.

| Step | Learn | What it means |
|---|---|---|
| Active learners in SOLA courses | 44,370 | can see it |
| Learners who used it | 1,213 | **2.73% try it** |
| Of those, sent one message and never returned | ~31% | ~376 bounced |
| Reached ten turns | 12% | ~146 |
| Reached twenty-five turns | 3% | ~36 |

Two things follow immediately.

**The trial gap is roughly a hundred times larger than the bounce gap.** About
43,000 learners who can see SOLA never send a message; about 376 send one and
leave. If the goal is raw volume, trial is where the numbers are.

**But the mean is a lie.** 4.8 turns per adopting learner describes almost
nobody: it is a small committed group averaged with a large bounce. Driving more
people into a funnel that loses a third at the first turn multiplies the bounce
along with the benefit.

### What we have already tried, and what it rules out

`auto_open` — opening the drawer automatically on a learner's first visit to a
course — was rolled out **globally on Learn**, not left off. Adoption is still
2.73%.

That is a genuinely useful negative result: **the crude visibility lever has
already been pulled.** Learners are not failing to notice SOLA. Whatever is
stopping the other 97%, it is not that the drawer is hidden. Proposals of the
form "make it more prominent" should be treated with suspicion unless they say
what is different about their prominence.

### The finding nobody has explained

Production contains **zero quiz rows and zero voice rows on both sites**, despite
both features having shipped. Not "few" — zero.

Two features that cost real engineering are reaching nobody at all. That is
either a discovery failure (learners never find the entry point) or a value
failure (they find it and do not want it), and those need opposite responses.
Finding out which is cheap and nobody has done it.

### Diagnose before treating: read the bounced first turns

The single highest-value next action costs nothing and needs no code.

**Sample fifty conversations that ended after one learner message, and read
them.** We store the transcripts. Right now we are theorising about why a third
of learners leave without having looked at what they asked and what they got
back. The plausible causes imply completely different fixes:

- the answer was wrong or generic
- the answer was fine and the question was fully resolved (a bounce that is
  actually a success, and should be counted differently)
- the learner was testing it ("what are you?") and never intended to continue
- retrieval returned nothing and it deflected

Until we know the mix, any intervention is a guess. This is the one item I would
do first regardless of everything else below.

### Interventions worth testing, ranked

Ordered by expected value per unit of effort, with the measurement each needs.

**1. Course-specific conversation starters.** The starters overlay is the first
thing a learner sees. Generic starters produce generic first answers, which is
exactly the shape of a one-turn bounce. Starters drawn from the actual course
structure — the unit they are on, the assessment coming up — change the first
question from "what can you do?" to something the tutor can answer well.
*Measure:* one-turn bounce rate, turns per adopting learner.

**2. Make the first turn demonstrate value rather than invite it.** SOLA already
has page grounding. On a content page, an opening offer of "summarise this page"
or "quiz me on this page" is concrete, immediately useful, and shows what the
tool is for in one exchange. *Measure:* second-message rate.

**3. Route the quiz feature into the conversation.** Quiz generation exists,
works, and has zero production usage. A follow-up chip after a substantive answer
("test yourself on this") costs almost nothing and puts the feature where the
learner already is. *Measure:* quiz rows above zero, and whether quiz users
return more.

**4. Decide about voice deliberately.** Zero usage. Either surface it properly
and measure, or retire it from the learner UI. Leaving a shipped feature at zero
is the worst of both: it carries maintenance and security surface — it is the
subject of two of this month's high-severity findings — with no offsetting value.

**5. Copy what Degrees does.** Degrees adopts at **2.5x** Learn: 6.70% against
2.73%. Those are admissions and preparation courses with smaller, more committed
cohorts. Before assuming the difference is cohort quality, check the cheaper
explanations: are those courses shorter, more assessment-dense, more anxious? If
any of that is reproducible on Learn, it is worth more than a UI change.

**6. Target rather than blanket.** Rolling out to 165 courses uniformly treats
every course as equally likely to benefit. The Degrees result suggests it is not.
Prioritising Degrees-like courses — high-stakes, exam-adjacent, smaller — would
likely produce more usage per course enabled than an even spread.

**7. Instructor endorsement.** An assistant a learner discovers is different from
one their instructor pointed them at. A short instructor-authored welcome in the
drawer, or a mention in the course introduction, is cheap to trial in a handful
of courses.

**8. Bring lapsed learners back.** The outreach and digest infrastructure already
exists. A learner who asked one question three weeks ago and never returned is a
better prospect than one who has never engaged — and unlike a broadcast, it can
reference what they actually asked.

### What not to do

**Do not set an adoption target and buy it with prominence.** Adoption is
achievable by making SOLA harder to ignore — auto-opening more aggressively,
interstitials, badges. That moves the number without moving the learning, and it
degrades the product for the 97% who have decided they do not want it.

**Do not let cost drive this.** The whole spend band from today's usage to a
tenfold increase is roughly $8 to $165 a month. Cost does not discriminate
between the options, so adoption planning should be driven by learning value.

### How we would know

The analytics dashboard already has an A/B readout: two course pickers and ten
engagement metrics with deltas. Experiments are cheap to run and cheap to read.

Two cautions from the last attempt. `auto_open` was rolled out globally before
the experiment ran, so the "control" courses inherited the treatment and the
comparison measured nothing — **verify the control arm is actually a control, at
the attribute delivered to the browser, not the stored setting.** And the
per-learner flag that suppresses repeat auto-opens means previously exposed
learners cannot be re-randomised.

Primary metric should be **second-message rate**, not adoption. Adoption counts
people who arrived; the second message is the first evidence that anything of
value happened.
