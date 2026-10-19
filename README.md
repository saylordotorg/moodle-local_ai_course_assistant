# AI Course Assistant - Moodle Local Plugin

A comprehensive AI-powered chat widget for Moodle 4.5+ that provides context-aware tutoring, support, and study planning for students.

## Demo video

[![A tour of SOLA: Saylor University's AI learning assistant](https://img.youtube.com/vi/BAjV5dmTCWU/maxresdefault.jpg)](https://youtu.be/BAjV5dmTCWU)

A 3-minute tour, recorded on Saylor University's live Degrees site in a hidden test course with fake demo students. The learner half is shown in student view: answers drawn only from the course's own content, Socratic guidance, Quiz Me, Study Plan, and 46 languages with automatic detection. The admin half covers mastery tracking, provider settings and guardrails, analytics, Learning Radar, and cost tracking.

## Version 7.12.0

**Release Date:** October 2026
**Plugin build:** 2026101803
**Requires:** Moodle 4.5+ (2024100700). Continuously tested against Moodle 4.5, 5.0 and 5.1. `version.php` declares support through 5.2.
**Moodle Workplace:** not tested, and not recommended on a multi-tenant site. See [Moodle Workplace](#moodle-workplace) below.
**License:** GPL v3+
**Maturity:** Stable. In production on Saylor's Learn and Degrees sites.

See the [Changelog](https://github.com/saylordotorg/moodle-local_ai_course_assistant/wiki/Changelog) for what's new and the [GitHub Releases](https://github.com/saylordotorg/moodle-local_ai_course_assistant/releases) page for the canonical version list.

## Moodle Workplace

**Not tested. On a multi-tenant Workplace site, not recommended today.**

This is stated explicitly because silence reads as tacit support. Asked in
[#246](https://github.com/saylordotorg/moodle-local_ai_course_assistant/issues/246).

CI covers Moodle LMS only: 4.5, 5.0 and 5.1, on PHP 8.1 to 8.3, with PostgreSQL
and MariaDB. There is no Workplace instance in our infrastructure, so there is no
evidence either way, which is not the same as believing it works.

The plugin contains no reference to Workplace, tenants or `tool_tenant`. It was
written for a single-tenant site because that is what Saylor runs.

**The specific risk.** Four admin pages are gated on system context and query
learner data with no tenant predicate: `analytics.php`, `audit_log.php`,
`rag_admin.php` and `prompt_metrics.php`. `analytics::get_overview(0)` means
every course on the site.

In a standard Workplace setup a tenant administrator holds their role inside the
tenant's category and so fails that system-context check, which is protective.
But it is an accident of how the gating was written rather than a boundary anyone
designed, and it inverts badly: if a deployment grants a tenant admin any
system-level manager role, they would see learner conversations, prompt text and
AI spend from every tenant. Chat messages are stored in full.

Course-scoped surfaces are sound by comparison. `instructor_dashboard.php`
resolves a `courseid` and checks `context_course`, which is correct under
multi-tenancy as well.

Workspaces, dynamic rules and shared spaces are all untested. The widget renders
from a `before_footer_html_generation` hook and bails when the course is
`SITEID`; whether a workspace presents as a course context that passes that check
is unknown.

**If you evaluate it**, start on a non-production tenant, open those four admin
pages as whichever role your tenant admins actually hold, and confirm what they
can see before any learner uses it. Findings are welcome in an issue and will be
documented here. A tenant-scoping contribution would be considered on its merits:
the four pages need a tenant predicate and the analytics queries need to accept
one.

## Credits

Originally built by Tom Caswell and David Ta at Saylor University, open-sourced under GPLv3 for any institution to deploy and brand. See [CREDITS.md](CREDITS.md) for details.

## Features

### Core Functionality
- **Floating Chat Widget:** Non-intrusive, always-available AI tutor on course pages
- **Context-Aware:** Ingests course structure to provide relevant, course-specific guidance
- **Streaming Responses:** Real-time SSE (Server-Sent Events) for responsive user experience
- **Multi-Provider Support:** Moodle's own core_ai subsystem, Anthropic Claude, OpenAI, Google Gemini, DeepSeek, Together AI, Mistral, xAI, OpenRouter, Ollama, MiniMax, and any OpenAI-compatible endpoint including self-hosted models. The shipped default is "Auto", which uses core_ai when no key of your own is configured
- **Role-Based Behavior:** Adapts responses for students, academic support staff, and administrators

### Student Features
- **Socratic Tutoring:** Guides students to answers rather than giving direct solutions
- **FAQ Support:** Centralized knowledge base for common questions
- **Study Planning:** Personalized study schedules based on available hours per week
- **Reminders:** Opt-in email/WhatsApp study reminders with country restrictions
- **AI Literacy:** Teaches students how to use AI effectively
- **Multilingual:** Ships in 46 languages, interface and speech included. Detects the language a learner writes in and replies in it, per message, with no configuration
- **Conversation Persistence:** Continues conversations where students left off
- **Audio Playback:** Listen to AI responses using Text-to-Speech
- **Copy Conversations:** Export chat history to clipboard
- **Data Management:** Self-service data deletion from user settings

### Practice and Assessment
- **Practice Quizzes:** Generated from the course's own material, answered inside the drawer, with per-question feedback and a score summary. These are practice only and separate from the course's Moodle quizzes
- **Flashcards:** Generated from a page or activity, with spaced-repetition review
- **Spoken Presentation Practice (Soapbox):** Learners record a talk against an instructor-configured assignment; it is transcribed and scored against a rubric with per-criterion feedback. Audio is deleted on a retention schedule, and only the transcript and score are kept. Four speaking levels including three ESL tiers
- **Essay Feedback:** Rubric-based scoring with per-criterion commentary
- **Academic Integrity:** The assistant is unavailable while a learner has a Moodle quiz attempt in progress. On by default for every quiz, checked on the server against the learner's live attempts rather than against whatever page the browser reports, and applied to every surface including voice. A teacher can exempt an individual quiz

### Voice
- **Speech Input:** Dictate questions instead of typing, in the same 46 languages as the interface
- **Spoken Responses:** Text-to-speech playback of answers
- **Live Voice Mode:** Optional two-way spoken conversation using OpenAI's Realtime API, with a live transcript and ELL coaching
- **Self-hosted Speech-to-Text:** Point at any OpenAI-compatible Whisper server for transcription at no per-minute cost
- **Recording Size Limit:** Configurable (default 25 MB). Note that PHP's own `post_max_size` (default 8 MB) and `upload_max_filesize` (default 2 MB) are both below that, and the smaller limit always wins. The setting is under **Advanced > Prompt, voice and display**, and it prints your server's real values next to it, so you can see which number is actually in force before a learner finds out for you. Raise both to at least one megabyte above whatever you set here, in `php.ini`, for learners to get the full amount

### Retrieval (RAG)
- **Semantic Retrieval:** Answers are grounded in the actual course content rather than general web knowledge. Indexes pages, books, files, PDF, DOCX, PPTX, H5P and SCORM content, plus transcripts for embedded video
- **Index Size Control:** Stored vectors can be kept at full precision or compressed to roughly a quarter or a thirtieth of the space, so a large catalogue does not force a large disk
- **Optional Re-ranking:** Two-stage retrieval with a cross-encoder for harder queries

### Course Backup and Restore
- Per-course configuration, learning objectives, rubrics and Soapbox assignments travel with a course backup, so duplicating or restoring a course keeps its AI setup
- Learner conversations, study plans, streaks, practice scores and flashcards are included only when the backup includes users
- Per-course API keys are deliberately excluded from backup files, and the RAG index is rebuilt by reindexing rather than carried inside every backup

### Academic Support Features
- **Analytics Dashboard:** Monitor student engagement and trends
- **Course Hotspots:** Identify sections where students struggle
- **Common Prompts:** See frequently asked questions
- **Usage Statistics:** Track adoption and activity
- **Off-Topic Detection:** Automatic identification of non-course-related conversations

### Administrative Features
- **Model Registry:** One page listing every model the assistant can bill for, the price it bills at, and where that price came from -- built-in default, admin correction, or a vendor feed -- with who set it and when. Prices are corrected through a form, so keeping up with vendor pricing needs no code change and no deploy
- **Price-Drift Checking:** Pricing sources are configurable rows with a declarative parse spec, so a new vendor feed is an admin form rather than new code. A daily task compares each source against the registry and *proposes* corrections; it never rewrites a price on its own, and its highest-severity finding is a model appearing in real traffic with no price at all -- the case that silently reports spend as $0.00
- **Model Lifecycle / End-of-Life Warnings:** A registry entry can record an announced retirement date alongside its price, and the daily drift check reports any model still appearing in real traffic whose end of life has passed or falls inside a configurable horizon (60 days by default). Price checks cannot see a retirement -- a model keeps working and keeps billing right up until it stops, and then every call fails at once -- so without this a genuine shutoff arrives as an outage rather than a warning. Each date records **which API surface** it was announced for, and only surfaces the site actually calls raise an alert: a retirement announced for one vendor surface frequently does not apply to the one a deployment uses, and paging an administrator about a non-event once teaches them to ignore the next page
- **Model Benchmarks and Recommendations:** Benchmark any registered model from the admin interface and compare it against the configured one on measured quality, cost per call and latency. Recommendations state their reasoning, and decline to compare when the sample is too small or spans a plugin release rather than guessing
- **Model Capability Profiles and Self-Healing Requests:** Which parameters each model accepts (the output-token parameter, temperature, how reasoning is controlled, the output limit) is data, not code: a rules table per provider, plus facts the assistant learns. When a provider rejects a request because a model no longer takes a parameter, the assistant changes that one parameter, retries once (on a streamed answer only before the learner has seen any of it), and remembers the fix, listed on the Model Registry page with the provider's own words and a Forget button. A new model works from its first call instead of failing until the next release. OpenAI reasoning models (GPT-5, GPT-6, o-series) get a **Reasoning effort** setting (low by default) and room for their thinking on top of the answer, so thinking can no longer cut an answer short
- **Automatic Model Upgrades:** Every day the assistant lists the models its providers offer and marks candidates for each role (chat, premium tier, failover, and the quiz, classifier, safety and Soapbox models): same provider, a comparable list price, and a price the registry knows for exactly that model. Candidates for the tutoring roles are evaluated against the current model in the same run: the tutor prompts answered under the course's real system prompt (built for the guest identity, so no learner data is sent) and judged, the jailbreak suite three times with a canary leak detector, truncation, errors, latency, and measured cost per answer with thinking included. A candidate that is the same price or cheaper, within 0.3 points on quality, has zero jailbreak failures, errors or leaks, and truncates and errors no more, twice in a row, is switched in for the site default (courses with their own model keep it), emailed to the spend alert recipients, watched for 48 hours on live traffic, and rolled back automatically if errors, cut-off answers, refusals or cost per answer get worse. Modes: Automatic (default), Recommend (email only) and Off. Testing is capped at a monthly budget (10 USD by default), checked before each evaluation and counted from actual spend. Nothing switches while an emergency control is engaged, for a setting the signed policy bundle manages, or (for the site-wide reasoning setting) while another role, failover model or course uses a thinking model it would change, and the failover model is only ever recommended, because it lives in a setting that holds an API key
- **Failover Checks:** The backups in the failover chain are tested instead of trusted. A daily task and `admin/cli/verify_failover.php` send each chat backup a tiny real request built exactly as a real failover builds it (the row's model goes out as typed), and the settings are checked without any network call for a chain line with no row or key, per-call failover with no backup, and a model written as a display name. Results are under Reports > Status, with a notice after the settings are saved.
- **Scoped Circuits and Failure Detail:** A failing provider is paused only for the key and model that failed, never for a request it called malformed, and only on a second failure in a row for a passing fault. The audit log records what the provider actually said, with credentials removed. A refused long or malformed conversation is retried repaired, then shortened, before failing over.
- **Course Key Guard:** A course cannot save, or keep, an API key that clearly belongs to a different provider than the one it uses. Existing mismatches are listed under Reports > Status.
- **Failure Alerts:** The unanswered-question alert counts failed replies as failures (they used to look like answers) and also fires when a set number of replies fail anywhere on the site, naming the most common provider message. Off by default.
- **Read-Only Audit Access:** `local/ai_course_assistant:viewaudit` opens the audit log without `moodle/site:config`. No role has it by default.
- **Spend Reporting:** An optional token-authenticated endpoint reports per-provider monthly spend for external dashboards, and reports how many rows it could not price so a real zero is distinguishable from a silent one. Off by default
- **Settings Organized by Task (v7.10.0):** The settings menu has five task pages (Essentials, Models and keys, Features, Safety and privacy, Cost and reliability) and an **Advanced** group of five pages (Retrieval; Reranking and documents; Prompt, voice and display; Integrations and governance; Soapbox, avatars and legacy), instead of the 11 flat pages of earlier releases. Nothing was renamed, deleted or given a new default, so a site that changes nothing behaves the same. Old settings URLs redirect to the page that replaced them
- **Settings Usage Report:** `admin/cli/settings_usage_report.php` lists every setting as default, changed, secret set, secret unset or not stored, so you can see which settings your site has never touched. It never prints a stored value or a secret. `--json` for machines, `--per-course` for per-course overrides, `--tables` for row counts
- **Find and Replace a Key Everywhere (v7.11.0):** `admin/cli/rotate_key.php --list` shows every place an AI key is stored (Providers and keys rows, the pasted-key settings, voice provider rows, the legacy `apikey` copy and per-course keys) by its last four characters and a fingerprint, never the key. Name the old key by its last four, give the new key in an environment variable or on standard input (never on the command line), and the command is a dry run until you add `--apply`, which replaces the key in every place in one transaction. It refuses an ambiguous old key, a new key whose prefix belongs to another vendor, and a key forced in `config.php`. A Reports > Status check lists keys stored in two or more places, and a course settings row with no key (as after a restore) no longer shows a saved key
- **Off-Topic Management:** Configure limits and lockout duration
- **Providers and Keys:** One table, on the **Models and keys** page, holds every AI provider's key, models, temperature and base URL. Add or change a key without touching the default provider, tell keys apart by their last four characters, and let Embedding, Rerank and Voice use a row's key instead of a pasted copy. The default provider's setting only chooses which row chat uses
- **Provider Configuration:** Choose the default AI provider
- **Zendesk Integration:** Auto-escalate unresolved issues to support tickets
- **Audit Logging:** Full SOC2-compliant audit trail
- **Rate Limiting:** Protect against abuse and API cost overruns

### Security & Compliance
- **SOC2 Compliant:** Audit logging, encryption, access controls
- **GDPR Ready:** Full Privacy API implementation
- **API Key Handling:** Keys are never sent to the browser and, from v7.7.3, are encrypted at rest with Moodle's own encryption (`\core\encryption`), using a site key kept in moodledata. A database backup alone no longer reveals them. From v7.9.0 every provider key lives in one **Providers and keys** table and is encrypted there too (the old comparison providers table stored its keys as plain text), the two anomaly-digest webhook URLs are encrypted, and no settings page ever shows a key, token, webhook URL or the encrypted form of one: a saved key reads "Saved, ends in ab12" with a Change button, and a key you typed into a form that failed to save comes back as an empty box. The settings-changed notice and Moodle's configuration log hide secrets too, and `admin/cli/audit_config_log_secrets.php` finds and can redact older log rows that don't. Two consequences of the encryption: back up moodledata's key along with the database, and a database restored onto another site (a staging copy, say) can't decrypt the keys, so enter them again there. Rolling back to 7.8.x means restoring the database from before the upgrade, because 7.8.x doesn't understand encrypted rows and Moodle blocks a code-only downgrade
- **Rate Limiting:** User and IP-based throttling
- **Input Validation:** XSS and SQL injection protection
- **Session Security:** Moodle-integrated authentication

## Installation

1. Download or clone this repository
2. Extract to `{moodle_root}/local/ai_course_assistant/`
3. Visit Site Administration → Notifications to complete installation
4. Configure settings at Site Administration → Plugins → Local plugins → AI Course Assistant, or go directly to `{moodle_root_url}/admin/category.php?category=local_ai_course_assistant`

### Finding the admin pages

All admin pages live under one hub: **Site administration → Plugins → Local plugins → AI Course Assistant**. Bookmark the hub directly: `{moodle_root_url}/admin/category.php?category=local_ai_course_assistant`. It lists the five task pages and the tools (Analytics, RAG Index, Prompt Debug Log, Emergency Controls, the editors), and an **Advanced** link that opens the second group. The Site administration search box finds every setting and page by name (try "AI Course"), including the ones on Advanced pages. Course staff additionally get **Course AI Settings** and **AI Tutor Analytics** links in each course's "More" menu (v6.6.1+).

**The menu (v7.10.0).**

| Page | What is on it |
|------|---------------|
| **Essentials** | Switch SOLA on, default course mode, auto-open, branding and avatar, contact and privacy details, FAQ, footer links. Opens with the version banner and the quick links to the tool pages |
| **Models and keys** | The Providers and keys table, the default provider, model and system prompt, model roles (quiz, essay, mastery classifier), the premium tier, and failover and retries |
| **Features** | Learner-facing features: mastery and learning paths, study plans and reminders, practice scoring, surveys, usability testing, active learners, outreach |
| **Safety and privacy** | Quiz lock, hiding on quizzes, off-topic limits, wellbeing, integrity checks and data retention |
| **Cost and reliability** | Spend caps, cost and answer-rate alerts, the anomaly digest, and automatic model upgrades |
| **Advanced > Retrieval** | Switch retrieval on, external resources, embeddings and search tuning |
| **Advanced > Reranking and documents** | Reranking, switching embedding models, which document types are indexed |
| **Advanced > Prompt, voice and display** | Model call details, prompt budget and debugging, history, voice and speech providers, widget appearance |
| **Advanced > Integrations and governance** | Support and Zendesk, analytics export, CDN, remote configuration, signed policy bundles, vendor data and price drift, benchmarks, optimizer weights |
| **Advanced > Soapbox, avatars and legacy** | Deprecated Soapbox, talking-avatar vendors and other parked settings |

Every page opens with a row of links to the other pages. A bookmark to an older page name (for example `admin/settings.php?section=local_ai_course_assistant_ai`) redirects to the page that replaced it, and the old Safety and moderation page is now Safety and privacy. Course-level settings (base URL, system prompt, temperature and monthly spend cap) are on each course's AI settings page, under a **More options** section that stays closed until one of them has a value.

**Change settings from the individual pages, not the hub's combined form.** Opening the hub's category URL shows the settings of all five task pages (about 130) in one very large form, and the **Advanced** link does the same for the Advanced pages (about 160). Save rewrites all of a form's settings at once, so a stray click on one field changes it without any sign. Use the page links under **AI Course Assistant**; each saves only its own fields. Since v7.8.1 every save, from either place, ends with a notice listing the settings it changed. Note: Moodle does not show a "Settings" link for local plugins on the Plugins overview page (admin/plugins.php), because that column only exists for other plugin types, so use the hub bookmark instead.

## Configuration

### Required Settings
1. **Enable Plugin:** Turn on the AI Course Assistant (**Essentials**)
2. **Providers and keys** (**Models and keys**): Add a row for your provider and paste its API key. Keys are encrypted at rest and never shown again, only their last four characters (see API Key Handling)
3. **AI Provider** (**Models and keys**): Choose the default provider (Claude, OpenAI, etc.). Chat uses the key of that provider's row only when the row's base URL is the same as **API Base URL** (both compared with spaces trimmed; both empty is the normal case). If you give the row a base URL of its own that differs, and no keyed row matches, the next admin page after a save warns that chat still uses the previous default key
4. **Model** (**Models and keys**): Select the model (e.g., gemini-2.5-flash, gpt-4o-mini, claude-sonnet-5-5)

### Recommended Settings
- **System Prompt:** Customize the AI's personality and behavior (**Models and keys**)
- **Max History:** Limit conversation context, default 20 message pairs (**Advanced > Prompt, voice and display**)
- **Avatar:** Choose from 10 included avatars (**Essentials**)
- **Position:** Bottom-right or bottom-left (**Advanced > Prompt, voice and display**)

### Optional Integrations
- **FAQ:** Add common questions and answers (**Essentials**)
- **Zendesk:** Configure for automatic ticket creation (**Advanced > Integrations and governance**)
- **Study Reminders:** Enable email/WhatsApp notifications (**Features**)
- **Off-Topic Detection:** Set limits and actions (**Safety and privacy**)

## Usage

### For Students
1. Open any course page
2. Click the floating avatar button (bottom-right by default)
3. Start asking questions about the course
4. Use Shift+Enter for new lines, Enter to send
5. Click copy button to export conversation
6. Click play button on AI messages to hear them aloud
7. Visit your user settings to manage your data

### For Academic Support
1. Access analytics dashboard from chat widget header (if enabled)
2. Review student engagement trends
3. Identify course content hotspots needing clarification
4. See common prompt patterns

### For Administrators
1. Configure plugin settings at Site Administration
2. Set off-topic limits and lockout duration
3. Review audit logs for security and usage
4. Monitor API costs and usage patterns

## Architecture

### Frontend
- **AMD Modules:** Modern JavaScript with ES6+ features
- **SSE Client:** Efficient streaming with ReadableStream API
- **Markdown Rendering:** Lightweight, secure markdown parser: headings, lists, tables, block quotes, code, links, bold, italic and strikethrough, with every piece of text escaped before markup is added
- **Audio Player:** Web Speech API for TTS (no external dependencies)
- **Responsive Design:** Mobile-first CSS with breakpoints

### Backend
- **Provider Layer:** Interface → Base → Specific implementations
- **Context Builder:** Course structure ingestion with caching
- **Conversation Manager:** Message persistence and history management
- **Rate Limiter:** Sliding window algorithm with caching
- **Audit Logger:** SOC2-compliant activity tracking

### Database
- **4 Main Tables:**
  - `local_ai_course_assistant_convs`: Conversations
  - `local_ai_course_assistant_msgs`: Messages
  - `local_ai_course_assistant_plans`: Study plans
  - `local_ai_course_assistant_reminders`: Reminder subscriptions
  - `local_ai_course_assistant_audit`: Security audit log

### Caching
- **System Prompts:** 1 hour TTL, invalidates on course changes
- **Rate Limits:** 2 minutes TTL, sliding window tracking

## File Structure

```
ai_course_assistant/
├── version.php                    # Plugin metadata
├── settings.php                   # Admin settings: builds every setting once, then places it
├── settings_user.php              # Student data management page
├── analytics.php                  # Analytics dashboard
├── unsubscribe.php                # Reminder opt-out page
├── sse.php                        # SSE streaming endpoint
├── lib.php                        # Legacy compatibility
├── README.md                      # This file
├── SECURITY.md                    # Security documentation
├── db/
│   ├── install.xml                # Database schema
│   ├── upgrade.php                # Upgrade scripts
│   ├── access.php                 # Capability definitions
│   ├── hooks.php                  # Hook registrations
│   ├── services.php               # External function definitions
│   ├── tasks.php                  # Scheduled tasks
│   ├── messages.php               # Message providers
│   └── caches.php                 # Cache definitions
├── lang/en/
│   └── local_ai_course_assistant.php    # Language strings
├── classes/
│   ├── hook_callbacks.php         # Widget injection
│   ├── conversation_manager.php   # Message CRUD
│   ├── context_builder.php        # System prompt builder
│   ├── faq_manager.php            # FAQ parsing
│   ├── zendesk_client.php         # Zendesk API
│   ├── study_planner.php          # Study plan management
│   ├── reminder_manager.php       # Reminder delivery
│   ├── analytics.php              # Usage analytics
│   ├── rate_limiter.php           # Rate limiting
│   ├── audit_logger.php           # Audit logging
│   ├── settings_layout_map.php    # Which settings page each setting lives on (data)
│   ├── settings_layout.php        # Places settings on the five task pages and Advanced group
│   ├── provider/
│   │   ├── provider_interface.php
│   │   ├── base_provider.php
│   │   ├── claude_provider.php
│   │   ├── openai_compatible_provider.php
│   │   ├── openai_provider.php
│   │   ├── ollama_provider.php
│   │   ├── minimax_provider.php
│   │   └── custom_provider.php
│   ├── external/                  # External API functions
│   ├── task/
│   │   └── send_reminders.php
│   └── privacy/
│       └── provider.php           # GDPR implementation
├── templates/
│   ├── chat_widget.mustache       # Main widget
│   ├── chat_message.mustache      # Message bubble
│   ├── analytics_dashboard.mustache
│   └── user_settings.mustache
├── amd/src/
│   ├── chat.js                    # Main controller
│   ├── ui.js                      # DOM manipulation
│   ├── sse_client.js              # SSE streaming
│   ├── markdown.js                # MD to HTML
│   ├── audio_player.js            # TTS playback
│   └── repository.js              # AJAX wrappers
├── styles.css                     # Widget styles
├── pix/
│   ├── icon.svg                   # Plugin icon
│   └── avatars/                   # 10 avatar SVGs
└── tests/
    ├── context_builder_test.php
    ├── conversation_manager_test.php
    └── behat/
        └── chat_widget.feature
```

## API Providers

### Supported Providers

1. **Claude (Anthropic)**
   - Models: claude-sonnet-5-5, claude-opus-5-5, claude-haiku-4-5 (and earlier)
   - API: `https://api.anthropic.com`

2. **OpenAI**
   - Models: gpt-4o-mini, gpt-4o, gpt-5-mini (and earlier)
   - API: `https://api.openai.com`

3. **Ollama (Self-Hosted)**
   - Models: llama3, mistral, codellama, etc.
   - API: `http://localhost:11434` (default)

4. **MiniMax**
   - Models: MiniMax-Text-01
   - API: `https://api.minimax.chat`

5. **Custom OpenAI-Compatible**
   - Any API following OpenAI's chat completions format

### Cost Considerations

- Configure max history to control token usage
- Use rate limiting to prevent runaway costs
- Monitor usage in analytics dashboard
- Consider self-hosted options (Ollama) for unlimited use

## Performance

### Optimizations Implemented
- ✅ System prompt caching (1 hour)
- ✅ Rate limiting (user + IP)
- ✅ Gzip compression for responses
- ✅ Database query optimization with indexes
- ✅ Lazy loading of chat widget JS
- ✅ Efficient SSE streaming
- ✅ Session write-close to prevent blocking

### Benchmarks
- Widget load: <100ms
- Message send (cached prompt): ~200ms + AI provider latency
- SSE streaming: Real-time token delivery
- Analytics dashboard: <500ms (typical course)

## Troubleshooting

### Widget not appearing
- Check plugin is enabled in settings
- Verify you have `local/ai_course_assistant:use` capability
- Ensure you're on a course page (not site home)
- Check browser console for JavaScript errors

### Streaming not working
- Verify web server doesn't buffer SSE responses
- For nginx: Add `X-Accel-Buffering: no` (already in code)
- For Apache: Disable mod_deflate for SSE endpoint
- Check firewall/proxy settings

### Rate limit errors
- Increase limits in rate_limiter.php defaults
- Clear rate limit cache: Purge caches → All caches
- Check for API rate limits from provider

### Audio not working
- Web Speech API not supported in all browsers
- Try Chrome, Edge, or Safari (best support)
- Check browser permissions for audio

## Development

### Testing
```bash
# PHPUnit
php admin/tool/phpunit/cli/init.php
vendor/bin/phpunit local/ai_course_assistant/tests/

# Behat
php admin/tool/behat/cli/init.php
vendor/bin/behat --tags=@local_ai_course_assistant
```

### Code Style
- Follow Moodle coding style guidelines
- Use `phpcbf` for automatic fixes
- Run `phpcs` before committing

## Roadmap

### v0.4.0
- [ ] Mobile app support
- [ ] Voice input
- [ ] Image analysis
- [ ] PDF document ingestion
- [ ] Collaborative study groups

### v0.5.0
- [ ] Advanced analytics (sentiment analysis)
- [ ] A/B testing framework
- [ ] Custom prompt templates per course
- [ ] Integration with H5P activities

## Contributing

Contributions welcome! Please:
1. Fork the repository
2. Create a feature branch
3. Follow Moodle coding standards
4. Add tests for new functionality
5. Submit a pull request

## Support

- **Documentation:** See SECURITY.md for compliance details
- **Issues:** [GitHub Issues](https://github.com/[your-repo]/issues)
- **Email:** [Your support email]

## Credits

**Original authors:** Tom Caswell and David Ta, Saylor University
**License:** GNU GPL v3 or later
**Copyright:** 2025-2026 Tom Caswell & David Ta / Saylor University

See [CREDITS.md](CREDITS.md) for the full attribution.

## Acknowledgments

- Moodle community for the excellent platform
- Anthropic for Claude API
- OpenAI for GPT models
- All contributors and testers

---

**Note:** This plugin is in BETA. Use in production environments at your own risk. Always test thoroughly in a staging environment first.
