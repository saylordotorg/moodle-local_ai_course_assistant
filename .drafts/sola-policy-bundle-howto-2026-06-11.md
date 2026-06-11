# SOLA Policy Bundle: Operator Instructions

Date: 2026-06-11. Feature shipped in v6.4.0; live on dev.sylr.org since today.

## What it is, in three sentences

A policy bundle is one signed JSON file that updates SOLA's behavior settings (prompts shape, model routing, escalation triggers, RAG tuning, spend policy) on any site that points at it, with no code deploy. A daily task (06:20) fetches it, verifies your Ed25519 signature, checks every key against a hard allowlist, and applies only if the bundle version is newer than the last applied one. It can never touch API keys, URLs, webhooks, emails, security settings, or code.

## Your current live setup

| Thing | Where |
|---|---|
| Private signing key | `~/.sola/sola_policy_private.key` on your Mac. Mode 0600. Never commit, never upload, never move into Dropbox. |
| Public key | `oUAviCWxTZ3ODiZmF9X/nIoo9uHO79AVniZ7oHuHFDs=` — pasted into dev's "Policy bundle public key" setting |
| Hosted bundle | `https://dev.sylr.org/sola-policy.json` (file at `/var/www/html/moodle/sola-policy.json` on the dev box) |
| Payload sources | `~/.sola/bundles/` — v3 is current (canonical posture: rerank on, pool 30, anomaly multiplier 2.0) |
| Sites syncing | dev.sylr.org only, applied version 3. Other dev sites and prod: not yet enabled. |

## Routine: publish a behavior change (about 2 minutes)

1. Copy the latest payload in `~/.sola/bundles/` to a new file. **Bump `version` by 1** — this is the one rule that bites: versions must strictly increase, same or lower is ignored by design.
2. Edit the `settings` block. Only allowlisted keys; the sign step rejects anything else. Update `issued_at` and write a useful `comment` (it lands in the audit log).
3. Sign it:
   ```
   cd ~/Sites/moodle/local/ai_course_assistant
   php admin/cli/policy_bundle_tool.php --sign \
     --payload=$HOME/.sola/bundles/v4-whatever.json \
     --key=$HOME/.sola/sola_policy_private.key \
     --out=$HOME/.sola/bundles/v4-whatever-signed.json
   ```
   If the payload has a typo, a forbidden key, or a non scalar value, it refuses to sign — errors happen on your laptop, not on sites overnight.
4. Replace the hosted file with the signed output. For the current dev hosting, easiest is to ask me ("publish bundle v4") or use SSM/scp to overwrite `/var/www/html/moodle/sola-policy.json` on the dev box.
5. Done. Sites apply it at the next 06:20 run. To apply immediately on a site:
   ```
   sudo -u www-data php admin/cli/scheduled_task.php --execute='\local_ai_course_assistant\task\policy_bundle_sync'
   ```

## Verify it worked

- Settings page, "Signed policy bundle" heading: shows "Last sync: applied version N (X settings changed)" with a timestamp.
- CLI: `php admin/cli/policy_bundle_tool.php --status`
- Audit log: a `policy_bundle_applied` row lists every changed key with old and new values.

## What a bundle can and cannot change

CAN (the 42 key allowlist): chat provider/model/temperature, prompt budgeting and section weights, history selection mode, mastery classifier routing, premium escalation (on/off, model, triggers, course tags), RAG floor and current page boost, rerank (on/off, model, pool), all spend caps and the anomaly policy.

CANNOT, ever, even correctly signed: API keys, any URL or webhook, email recipients, the SSRF allowlist, consent and privacy settings, emergency flags, voice configuration, code of any kind. A bundle containing any such key is rejected in full and nothing in it applies.

## Troubleshooting

- "skipped: bundle version N is not newer" — you forgot to bump `version`. Bump and re sign.
- "signature verification FAILED" — the hosted file was edited after signing, or signed with a different key. Re sign and re upload; never hand edit the hosted file.
- "not on the policy bundle allowlist" — remove that key; if it genuinely should be bundle controllable, it needs a code change to ALLOWED_KEYS (deliberate friction).
- Nothing happens at all — check `policy_bundle_enabled` is on and `--status` for the last error.

## Key safety

- Lost key: generate a new pair (`--keygen` to a new directory), paste the new public key into each site's setting. Old bundles stop verifying, which is correct.
- Stolen key: same rotation, one setting change per site. Until rotated, the thief can change allowlisted behavior settings only — never keys, URLs, or code. Treat it like an SSH private key.
- Back the private key up somewhere encrypted that is not Dropbox (password manager attachment works).

## Rolling it out further

- More dev sites: set the same URL + public key + enable on each. One file then drives the fleet.
- Prod (after the Catalyst upgrade lands v6.4.1): same three settings. Recommended order: enable on prod only after a couple of weeks of dev soak, and host the prod bundle at a stable Saylor controlled https location rather than the dev box (any https static host works; the signature carries the trust).
