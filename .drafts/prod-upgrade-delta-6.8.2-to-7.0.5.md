# Production upgrade: 6.8.2 to 7.0.5 — delta and options

**Prepared 24 August 2026.** Production (Learn and Degrees) runs **v6.8.2**
(build 2026061702). Head is **v7.0.5** (build 2026082400).

---

## 1. Why this is no longer a routine upgrade

All five HIGH findings from the 24 August adversarial review were checked against
the v6.8.2 source. **All five are present in production.**

| Finding | Present in 6.8.2 | What it means on Learn/Degrees today |
|---|---|---|
| Unscoped module read | **Yes** — `get_module_content(int $cmid, int $maxchars)`, byte-identical to the vulnerable form | Any learner with the plugin's `:use` capability in one course can read the text of any Page or Book on the site, including **hidden** activities in their own course, by passing that module's id to quiz or flashcard generation. Hidden pages are where staged answer keys live. |
| Voice kill switch not enforced | **Yes** — `realtime_enabled` appears zero times in the endpoint | Switching voice off in admin settings hides the button and leaves the endpoint minting live voice credentials. |
| System prompt returned to browser | **Yes** — the endpoint returns the full grounded prompt | A learner can read the jailbreak defences and their own personalisation straight out of the JSON. |
| No throttling on AI endpoints | **Yes** — no per-user limit on the provider path | Nothing but a lagging monthly spend guard, which reports under-cap when no cap is set. |
| Escalation triggerable by course content | **Yes** — bare `str_contains` on the marker | Text written into a course page can cause a learner's name, email and full transcript to be sent to the support desk. |

The exposure is real rather than theoretical: the prompt-disclosure finding was
**demonstrated live on dev** against the deployed 7.0.4 build, which returned
13,044 characters including the course name, the learner's own first name and the
security-rules section. 6.8.2 behaves the same way.

---

## 2. Size of the full upgrade

| | |
|---|---|
| Tags between | 17 (v6.8.3 … v7.0.5) |
| Files changed | 562 |
| Lines | +80,271 / −4,707 |
| Database upgrade steps | 9 savepoints |
| New tables | `sbx_assign`, `sbx_rec`, `sbx_topic`, `obj_att` (Soapbox + objective attempts) |
| Modified tables | `chunks` (RAG storage: `embed_model`, `embed_dtype`, `embedding_bin`) |
| New scheduled tasks | `soapbox_cleanup`, `unanswered_check` |
| New admin settings | 40 |
| Rebuilt AMD bundles | 28 |

**Nothing new defaults to on.** The 40 new settings ship off or unset, so the
upgrade does not switch on Soapbox, reranking, premium escalation or quantized
storage by itself.

---

## 3. Two options

### Option A — full upgrade to 7.0.5

Everything above, in one move. Catalyst-managed, same shape as the 5.4.5 → 6.8.2
upgrade that landed on 24 June.

**For:** closes all eleven findings; production lands on the version that is
tested, released and on the Marketplace; no divergent branch to maintain.

**Against:** 562 files and nine schema steps is a real change surface. Three
behavior changes need an administrator to act (below). Wants a maintenance
window and a rollback plan.

### Option B — surgical backport onto 6.8.2

Backport only the security fixes, ship as 6.8.3-security or similar.

The unscoped-read fix is genuinely small: add a required `$courseid` parameter,
two gates inside the function, and update four call sites. The four files it
touches have diverged by ~400 lines since 6.8.2, but the fix itself does not
depend on any of that divergence.

**Cheap to backport:** the unscoped module read, the voice kill switch, and the
per-user throttle. Those three are server-side only.

**Expensive to backport:** the prompt-disclosure fix requires the mint-time
instruction change *and* rebuilt JavaScript bundles, because the browser
overwrites the session otherwise — that is the defect we found and fixed twice.
The escalation and RAG-fencing fixes touch code that has moved substantially.

**For:** small, auditable change set; short window; no feature drift.

**Against:** leaves production on a branch nobody else runs, and closes maybe
three of eleven findings. The remaining eight stay live until a full upgrade
happens anyway.

---

## 4. Recommendation

**Option A, with the unscoped module read treated as the reason for the
timeline.** The backport is attractive only if a full upgrade is weeks away; if
it can be scheduled inside a fortnight, doing the work twice is not worth it —
and Option B still leaves the prompt disclosure and the content-triggered
escalation live, both of which are learner-facing.

If prod cannot move quickly, the honest interim is Option B limited to the
unscoped module read alone. That is the finding with a concrete victim: a student
reading an exam answer key out of a hidden page in their own course.

---

## 5. Administrator actions the upgrade requires

1. **Learning Radar / Redash charts** need an `Authorization: Bearer` header set
   on the data source. The credential is deliberately no longer embedded in the
   query, because it was being stored in plaintext inside Redash. Existing saved
   queries keep working until the key is rotated.
2. **Escalation intent patterns** — if learners write in a language other than
   English, add patterns (one regex per line, no delimiters). An unmatched
   message means no support ticket is opened. Production traffic is 91–97%
   English, so the built-in defaults likely suffice.
3. **Leave `voyage-context-4` unset.** It is wired into indexing only; this is
   unchanged from 7.0.4 and is tracked for a later release.

---

## 6. Verification already done on this build

- Full plugin test suite: 907 tests, 3,956 assertions, 0 failures.
- Validators: 36 passed, 0 failed.
- CI: 13/13 across PHP 8.1, 8.2 and 8.3 on both MariaDB and PostgreSQL.
- The unscoped-read fix passes an adversarial test written independently of the fix.
- The prompt-disclosure fix verified live: client payload 13,044 -> 2,104
  characters, every probe absent.
- Voice grounding verified live against the OpenAI Realtime API: the session
  instructions are sha256-identical to the server-assembled prompt and are not
  perturbed by the browser.
- Deployed and smoke-tested on all five dev sites.
