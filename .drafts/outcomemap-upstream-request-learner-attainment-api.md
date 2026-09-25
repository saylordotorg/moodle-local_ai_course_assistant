# Upstream request to local_outcomemap: a learner-safe program attainment API

**Status: ready to send. Open as an issue on `dta121/moodle-local_outcomemap`.**
Written 24 September 2026 against `local_outcomemap` 0.9.3 (`2026091100`), the
version running on both Saylor production sites and on our dev fleet.

Everything below the `---` is the sendable issue.

---

**Feature request: a learner-safe way to read pooled program attainment**

We integrate Outcome Map into SOLA, our Moodle learning assistant, and we would
like to show a learner their own standing against their program's outcomes.
Outcome Map already computes exactly that, and we can see it in the data, but we
cannot find a supported way for a **learner** to read it.

**What we tried, and why it does not work**

`local_outcomemap_get_user_program_attainment` returns precisely the shape we
want: per program, per outcome, with `state`, `percentage`, the band thresholds,
and the evidence counts. It is a genuinely well-designed contract and the `state`
enum in particular is the thing that makes it safe to render, because it
distinguishes "no evidence yet" from a low score.

The blocker is that it requires `local/outcomemap:exportattainment` at system
context. We verified on our dev site with a real learner who has `calculated`
results:

```
has_capability('local/outcomemap:exportattainment', context_system::instance()) => false
```

That is correct behavior on your side, and we are not asking you to loosen it.
The function takes an arbitrary `userid` and checks only that capability, so
granting it to students would let any student read any other student's
attainment. It is an SIS export function and it is gated like one.

`local/outcomemap:viewownresults` is student-allowed and does reach
`student_result_service::get_own_report()`, which looks exactly right for a
learner. Two things stop us using it:

1. It is **course-scoped**. We want the program-level pooling that
   `get_user_program_attainment` performs, which is the part a course-scoped tool
   cannot reproduce.
2. It lives in `classes/local/service/`. We deliberately only call
   `\local_outcomemap\api\*` and your declared external functions, so that a
   change to your internals cannot break us silently. Reaching into
   `local\service` would give up that guarantee, and on a 0.9.x plugin we would
   rather not.

**What would unblock us**

Any one of these, in the order we would find most useful:

1. **An external function for own attainment.** Something like
   `local_outcomemap_get_own_program_attainment(programcode = '')`, taking no
   user id at all, acting on `$USER`, gated on `local/outcomemap:viewownresults`,
   and returning the same structure the SIS function already returns. Because it
   cannot name another user, the capability question goes away.
2. **A user id parameter honored on the existing function when it is the caller**,
   so `exportattainment` is required only when `userid !== $USER->id`.
3. **A promotion of a learner-safe reader into `classes/api/`**, even
   course-scoped, so we can at least call something stable.

Option 1 looks smallest from the outside: your `student_result_service` already
separates `report_for()` from `report_for_attainment()`, and the existing
external function appears to have the pooling logic that would need reusing.

**What we are shipping meanwhile**

The panel is written, translated into 46 languages, and ships **disabled by
default** behind a site setting, with a note to administrators saying plainly
that it does not work for learners yet and why. If one of the above lands we can
switch it on without a code deploy.

Happy to open a PR for option 1 if you would like the work rather than the issue.
Also happy to be told we have missed an existing route, which would be the best
outcome of this issue.

---

**NOTE TO TOM, not part of the issue:**

1. The repo is `dta121/moodle-local_outcomemap`, not under `saylordotorg`, so this
   is a request to a third party rather than something we can merge ourselves.
   That is the main risk in choosing option (b): the timeline is not ours.
2. If you would rather we wrote the PR than filed the issue, say so and I will
   draft it against their `main`. Option 1 is the one I would attempt.
3. The offer to be corrected at the end is genuine, not politeness. I read the
   whole `classes/api/` surface and the external directory, but a maintainer may
   know of a route neither is obvious about.
