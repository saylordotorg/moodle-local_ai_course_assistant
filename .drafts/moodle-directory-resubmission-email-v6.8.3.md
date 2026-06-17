# Moodle plugin directory resubmission email (ready to send)

To: Volodymyr Dovhan (via the Moodle Plugins team / CONTRIB-10574)
Subject: CONTRIB-10574 — AI Course Assistant (local_ai_course_assistant): all 29 issues resolved, resubmitting for review

Hi Volodymyr,

Thank you for the detailed review of AI Course Assistant. We have addressed all 29 reported issues and would like to resubmit the plugin for review.

A new release, v6.8.3, is published with the fixes:
https://github.com/saylordotorg/moodle-local_ai_course_assistant/releases/tag/v6.8.3

Every reported issue (#68 to #96) is resolved and closed, each with a per-issue note describing the fix on the corresponding GitHub issue. Summary by area:

- Security guidelines: parameter, context, and capability validation added to every external service; PARAM_RAW tightened or justified; guest guards on entry pages; no world-writable (0777) directories; external command execution for PDF and lint hardened.
- Privacy: the Privacy Provider now declares add_external_location_link() for the AI provider, the voice STT/TTS provider, Zendesk escalation, and the Learning Radar webhook; the user-related table is fully covered.
- Licensing and conventions: GPLv3 LICENSE added; global functions frankenstyle-prefixed; configuration API used instead of direct config_plugins access; class autoloading; Moodle File API for temporary files; all outbound HTTP routed through Moodle's curl wrapper; boilerplate headers; missing language strings defined.
- Architecture: the two AJAX_SCRIPT endpoints converted to External Services; raw $_SESSION moved to a MODE_SESSION cache; the $USER mutation, deprecated print_error(), and direct $DB->execute() usages removed; the admin course picker uses a bounded recordset.
- Templates, Output API, and internationalization: the admin pages that rendered HTML procedurally (including the instructor dashboard) now build a template-data array and render Mustache templates through the Output API; markup that JavaScript previously built with innerHTML now renders through core/templates; JavaScript-rendered labels are resolved through the language pack; and inline page styles moved to styles.css.

Release quality: every tag ships only after our automated gate: validator and jailbreak suites, about 550 PHPUnit tests, the Moodle Plugin CI matrix across PHP 8.1 to 8.3 with MariaDB and PostgreSQL (including codechecker, phpdoc, Mustache lint, and ESLint), and deployment plus smoke verification on five dev sites spanning Moodle 4.5 to 5.3. v6.8.3 has been through all of it.

Thank you again for the thorough review. Please let us know if anything else is needed.

Best regards,
Tom Caswell
Saylor Academy

---

## Internal notes (not part of the message)

- Resubmission also needs the v6.8.3 version uploaded to the plugin's directory page (or the directory pulls the v6.8.3 git tag) to re-enter the approval queue.
- All 29 GitHub issues (#68 to #96) are closed with per-issue fix notes, including #78 (templates / Output API: the instructor dashboard is the final page migrated) and #79 (hard-coded strings: the analytics dashboard's JavaScript labels are now resolved server-side and passed in).
- v6.8.3 is the directory release; Saylor production runs v6.8.2 (sent to Catalyst on 2026-06-17). The directory listing version and the production pin do not need to match.
