<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_ai_course_assistant;

/**
 * Meta-test: every plugin lang key referenced from a mustache template
 * or a PHP file must be defined in lang/en/local_ai_course_assistant.php
 * (v5.3.19).
 *
 * Catches the class of bug where new code references a string that was
 * never added (the v5.3.17 missing `messageprovider:study_reminder` is
 * a concrete example — caught only because Moodle's own test happened
 * to run on it). Generalises that check to every key the plugin uses.
 *
 * Scans:
 *   - All `*.mustache` templates for `{{#str KEY, local_ai_course_assistant}}`.
 *   - All `*.php` files for `get_string('KEY', 'local_ai_course_assistant')`.
 *
 * Skips dynamically-built keys (e.g. `'foo:' . $bar`) since those need
 * runtime context to resolve.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class lang_completeness_test extends \basic_testcase {
    /**
     * Plugin root.
     *
     * @return string
     */
    private function plugin_root(): string {
        global $CFG;
        return $CFG->dirroot . '/local/ai_course_assistant';
    }

    /**
     * Load the EN string set.
     *
     * @return array<string, string>
     */
    private function load_en_strings(): array {
        $string = [];
        include($this->plugin_root() . '/lang/en/local_ai_course_assistant.php');
        return $string;
    }

    /**
     * Walk a directory and return every file matching the extension list.
     *
     * @param string $dir
     * @param array $exts list of file extensions to include (e.g. ['.php', '.mustache'])
     * @return array list of absolute file paths matching one of $exts
     */
    private function walk(string $dir, array $exts): array {
        $out = [];
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iter as $f) {
            $path = $f->getPathname();
            // Skip the test dir itself, vendor, build artefacts, drafts.
            if (preg_match('#/(\.git|node_modules|vendor|amd/build|tests/|\.drafts/|\.wiki/)#', $path)) {
                continue;
            }
            foreach ($exts as $ext) {
                if (str_ends_with($path, $ext)) {
                    $out[] = $path;
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * Extract every static lang key referenced from mustache + PHP files.
     *
     * @return array<int, array{key: string, file: string}>
     */
    private function referenced_keys(): array {
        $refs = [];
        $component = 'local_ai_course_assistant';

        // Mustache: `{{#str KEY, local_ai_course_assistant}}`
        foreach ($this->walk($this->plugin_root() . '/templates', ['.mustache']) as $f) {
            $body = file_get_contents($f);
            if (
                preg_match_all(
                    '/\{\{#str\}\}\s*([a-zA-Z0-9_:]+)\s*,\s*' . preg_quote($component, '/') . '\s*\{\{\/str\}\}/',
                    $body,
                    $m
                )
            ) {
                foreach ($m[1] as $k) {
                    $refs[] = ['key' => $k, 'file' => basename($f)];
                }
            }
            // Older mustache form: `{{#str}}KEY, comp{{/str}}`
            if (
                preg_match_all(
                    '/\{\{#str\}\}\s*([a-zA-Z0-9_:]+)\s*,\s*' . preg_quote($component, '/') . '\s*\{\{\/str\}\}/s',
                    $body,
                    $m2
                )
            ) {
                foreach ($m2[1] as $k) {
                    $refs[] = ['key' => $k, 'file' => basename($f)];
                }
            }
        }

        // PHP: `get_string('KEY', 'local_ai_course_assistant')`. We only
        // capture single-quoted literal keys; dynamic keys (concatenation
        // or interpolation) are skipped because they need runtime context.
        foreach ($this->walk($this->plugin_root(), ['.php']) as $f) {
            $body = file_get_contents($f);
            if (
                preg_match_all(
                    "/get_string\(\s*'([a-zA-Z0-9_:]+)'\s*,\s*'" . preg_quote($component, '/') . "'/",
                    $body,
                    $m
                )
            ) {
                foreach ($m[1] as $k) {
                    $refs[] = ['key' => $k, 'file' => str_replace($this->plugin_root() . '/', '', $f)];
                }
            }
        }

        return $refs;
    }

    public function test_every_referenced_key_is_defined(): void {
        $defined = $this->load_en_strings();
        $refs = $this->referenced_keys();
        $this->assertNotEmpty($refs, 'Reference scan must find at least some keys');

        $missing = [];
        foreach ($refs as $r) {
            if (!array_key_exists($r['key'], $defined)) {
                $missing[$r['key']] = ($missing[$r['key']] ?? []);
                $missing[$r['key']][] = $r['file'];
            }
        }

        if (!empty($missing)) {
            $report = [];
            foreach ($missing as $k => $files) {
                $report[] = $k . ' (referenced in: ' . implode(', ', array_unique($files)) . ')';
            }
            $this->fail("Lang keys referenced in templates/PHP but missing from "
                . "lang/en/local_ai_course_assistant.php:\n  - "
                . implode("\n  - ", $report));
        }
    }

    /**
     * English keys that are knowingly not translated yet.
     *
     * The pre-7.0.0 audit found the plugin was 8 keys short of the 46/46 parity
     * the docs claim, and nothing detected it: the test above only proves a
     * referenced key exists in English, so a new English-only string was invisible.
     *
     * This list is the debt, not an exemption. Removing an entry is what a
     * translation batch does. Adding one should be a deliberate, reviewed act,
     * because the parity test below fails the moment an unlisted key appears in
     * English without translations, which is the drift that went unnoticed.
     */
    /**
     * Locales that carry the full v7.0.0 settings-i18n batch.
     *
     * These are asserted separately below, so exempting the batch from the
     * parity check does not quietly discard the translation work already done.
     */
    private const V700_TRANSLATED_LOCALES = [
        'ar', 'de', 'es', 'fr', 'hi', 'it', 'ja', 'pt_br', 'ru', 'zh_cn',
    ];

    private const KNOWN_UNTRANSLATED = [
        // v7.0.0 (2026-08-20): 170 keys extracted from settings.php, where 86
        // settings had their title and description written as hardcoded English
        // literals. Extracting them is what made them translatable at all — this
        // check could not see them before, because it compares lang/en against
        // the other locales and these had never been keys.
        //
        // Ten locales are translated (see V700_TRANSLATED_LOCALES, asserted
        // below). The remaining 35 fall back to lang/en, which is byte-for-byte
        // what those settings displayed before this release, so nothing
        // regressed for a learner or an administrator.
        //
        // This entry is temporary and should shrink to nothing when the
        // remaining locales land. Do not add to it for ordinary new strings.
        'settings:allow_student_attachments',
        'settings:allow_student_attachments_desc',
        'settings:anomaly_digest_enabled',
        'settings:anomaly_digest_enabled_desc',
        'settings:anomaly_digest_heading',
        'settings:anomaly_digest_heading_desc',
        'settings:anomaly_digest_recipient_email',
        'settings:anomaly_digest_recipient_email_desc',
        'settings:anomaly_digest_slack_webhook',
        'settings:anomaly_digest_slack_webhook_desc',
        'settings:anomaly_digest_teams_webhook',
        'settings:anomaly_digest_teams_webhook_desc',
        'settings:anomaly_digest_threshold_pct',
        'settings:anomaly_digest_threshold_pct_desc',
        'settings:attachment_allowed_types',
        'settings:attachment_allowed_types_desc',
        'settings:attachment_max_size_mb',
        'settings:attachment_max_size_mb_desc',
        'settings:attachments_heading',
        'settings:attachments_heading_desc',
        'settings:branding_heading',
        'settings:branding_heading_desc',
        'settings:chat_greeting',
        'settings:chat_greeting_desc',
        'settings:claude_temperature_allow_prefixes',
        'settings:claude_temperature_allow_prefixes_desc',
        'settings:customavatars',
        'settings:customavatars_desc',
        'settings:display_name',
        'settings:display_name_desc',
        'settings:enable_thinking',
        'settings:enable_thinking_desc',
        'settings:failover_per_call_enabled',
        'settings:failover_per_call_enabled_desc',
        'settings:failover_timeout_chat',
        'settings:failover_timeout_chat_desc',
        'settings:feedback_link_label',
        'settings:feedback_link_label_desc',
        'settings:feedback_panel_intro',
        'settings:feedback_panel_intro_desc',
        'settings:footer_courses_text',
        'settings:footer_courses_text_desc',
        'settings:footer_courses_url',
        'settings:footer_courses_url_desc',
        'settings:footer_links_heading',
        'settings:footer_links_heading_desc',
        'settings:hidden_categories',
        'settings:hidden_categories_desc',
        'settings:inactivity_reminder_enabled',
        'settings:inactivity_reminder_enabled_desc',
        'settings:inactivity_threshold_days',
        'settings:inactivity_threshold_days_desc',
        'settings:institution_short_name',
        'settings:institution_short_name_desc',
        'settings:max_content_per_resource',
        'settings:max_content_per_resource_desc',
        'settings:max_tokens',
        'settings:max_tokens_desc',
        'settings:max_total_content',
        'settings:max_total_content_desc',
        'settings:opt_cost_weight',
        'settings:opt_cost_weight_desc',
        'settings:opt_quality_weight',
        'settings:opt_quality_weight_desc',
        'settings:performance_heading',
        'settings:performance_heading_desc',
        'settings:practice_scoring_enabled',
        'settings:practice_scoring_enabled_desc',
        'settings:profile_update_interval',
        'settings:profile_update_interval_desc',
        'settings:provider_heading',
        'settings:provider_heading_desc',
        'settings:quiz_model',
        'settings:quiz_model_desc',
        'settings:quiz_provider',
        'settings:quiz_provider_desc',
        'settings:rag_extract_docx',
        'settings:rag_extract_docx_desc',
        'settings:rag_extract_h5p',
        'settings:rag_extract_h5p_desc',
        'settings:rag_extract_pdf',
        'settings:rag_extract_pdf_desc',
        'settings:rag_extract_pptx',
        'settings:rag_extract_pptx_desc',
        'settings:rag_extract_scorm',
        'settings:rag_extract_scorm_desc',
        'settings:rag_fetch_transcripts',
        'settings:rag_fetch_transcripts_desc',
        'settings:rag_iframe_host_patterns',
        'settings:rag_iframe_host_patterns_desc',
        'settings:rag_pdftotext_path',
        'settings:rag_pdftotext_path_desc',
        'settings:rag_scorm_max_mb',
        'settings:rag_scorm_max_mb_desc',
        'settings:rag_sources_heading',
        'settings:rag_sources_heading_desc',
        'settings:rag_transcript_url_pattern',
        'settings:rag_transcript_url_pattern_desc',
        'settings:rubric_heading',
        'settings:rubric_heading_desc',
        'settings:short_name',
        'settings:short_name_desc',
        'settings:soapbox_heading',
        'settings:soapbox_heading_desc',
        'settings:soapbox_max_recordings',
        'settings:soapbox_max_recordings_desc',
        'settings:soapbox_max_seconds',
        'settings:soapbox_max_seconds_desc',
        'settings:soapbox_retention_days',
        'settings:soapbox_retention_days_desc',
        'settings:soapbox_storage_bucket',
        'settings:soapbox_storage_bucket_desc',
        'settings:soapbox_storage_key',
        'settings:soapbox_storage_key_desc',
        'settings:soapbox_storage_prefix',
        'settings:soapbox_storage_prefix_desc',
        'settings:soapbox_storage_region',
        'settings:soapbox_storage_region_desc',
        'settings:soapbox_storage_secret',
        'settings:soapbox_storage_secret_desc',
        'settings:soapbox_video_quality',
        'settings:soapbox_video_quality_desc',
        'settings:spend_cap_analytics',
        'settings:spend_cap_analytics_desc',
        'settings:spend_cap_chat',
        'settings:spend_cap_chat_desc',
        'settings:spend_cap_period',
        'settings:spend_cap_period_desc',
        'settings:spend_cap_rag',
        'settings:spend_cap_rag_desc',
        'settings:spend_cap_site',
        'settings:spend_cap_site_desc',
        'settings:spend_cap_voice',
        'settings:spend_cap_voice_desc',
        'settings:spend_failover_chain',
        'settings:spend_failover_chain_desc',
        'settings:spend_guard_heading',
        'settings:spend_guard_heading_desc',
        'settings:spend_notify_emails',
        'settings:spend_notify_emails_desc',
        'settings:stt_selfhosted_enabled',
        'settings:stt_selfhosted_enabled_desc',
        'settings:stt_selfhosted_warm',
        'settings:stt_selfhosted_warm_desc',
        'settings:survey_enabled',
        'settings:survey_enabled_desc',
        'settings:survey_frequency',
        'settings:survey_frequency_desc',
        'settings:survey_heading',
        'settings:survey_heading_desc',
        'settings:survey_trigger_messages',
        'settings:survey_trigger_messages_desc',
        'settings:usertesting_enabled',
        'settings:usertesting_enabled_desc',
        'settings:usertesting_external_url',
        'settings:usertesting_external_url_desc',
        'settings:usertesting_heading',
        'settings:usertesting_heading_desc',
        'settings:voice_active_realtime',
        'settings:voice_active_realtime_desc',
        'settings:voice_active_stt',
        'settings:voice_active_stt_desc',
        'settings:voice_active_tts',
        'settings:voice_active_tts_desc',
        'settings:voice_providers_heading',
        'settings:voice_providers_heading_desc',
        'settings:voice_tab_enabled',
        'settings:voice_tab_enabled_desc',
        'settings:welcome_message',
        'settings:welcome_message_desc',
    ];

    /**
     * Keys defined in a locale file.
     *
     * @param string $lang locale directory name.
     * @return array<string, true>
     */
    private function locale_keys(string $lang): array {
        $path = $this->plugin_root() . '/lang/' . $lang . '/local_ai_course_assistant.php';
        if (!file_exists($path)) {
            return [];
        }
        $string = [];
        include($path);
        return $string;
    }

    public function test_v700_settings_batch_is_complete_in_its_translated_locales(): void {
        // KNOWN_UNTRANSLATED exempts the v7.0.0 batch from the parity check
        // above. Without this test that exemption would also let the ten
        // finished locales silently lose those keys again.
        $missing = [];
        foreach (self::V700_TRANSLATED_LOCALES as $lang) {
            $keys = $this->locale_keys($lang);
            $this->assertNotEmpty($keys, "locale {$lang} parsed as empty - this guard would pass vacuously");
            foreach (self::KNOWN_UNTRANSLATED as $key) {
                if (!array_key_exists($key, $keys)) {
                    $missing[] = "{$lang}/{$key}";
                }
            }
        }
        $this->assertSame(
            [],
            $missing,
            'These locales are declared as carrying the v7.0.0 settings batch but are missing keys: '
                . implode(', ', array_slice($missing, 0, 10))
        );
    }

    public function test_translation_parity_has_not_regressed(): void {
        $en = array_keys($this->load_en_strings());
        $locales = array_filter(
            scandir($this->plugin_root() . '/lang'),
            fn($d) => $d !== '.' && $d !== '..' && $d !== 'en'
        );
        $this->assertGreaterThan(40, count($locales), 'expected the full locale set');

        $unexpected = [];
        foreach ($locales as $lang) {
            $keys = $this->locale_keys($lang);
            foreach ($en as $key) {
                if (
                    !array_key_exists($key, $keys)
                        && !in_array($key, self::KNOWN_UNTRANSLATED, true)
                ) {
                    $unexpected[$key][] = $lang;
                }
            }
        }

        if (!empty($unexpected)) {
            $report = [];
            foreach ($unexpected as $key => $langs) {
                $report[] = $key . ' (missing from ' . count($langs) . ' locales)';
            }
            $this->fail("English strings with no translations, and not listed in "
                . "KNOWN_UNTRANSLATED. Either translate them or add them to that "
                . "list deliberately:\n  - " . implode("\n  - ", $report));
        }
    }
}
