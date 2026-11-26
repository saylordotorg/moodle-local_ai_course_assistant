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
 * Guards the 8.4.1 move of hard-coded learner and admin English into language strings.
 *
 * Each pair below is a fragment of code that used to carry English text. Putting any one back
 * fails the test for that pair, and every key the code now asks for must exist in lang/en.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\review_queue
 */
final class ui_literals_test extends \basic_testcase {
    /**
     * Code fragments that must not come back: [file relative to the plugin, fragment].
     *
     * @return array
     */
    public static function removed_literal_provider(): array {
        $pairs = [
            ['classes/review_queue.php', '\'Off-topic count: \''],
            ['classes/review_queue.php', '\'Integrity: \''],
            ['classes/task/send_inactivity_reminders.php', '"We miss you in'],
            ['classes/task/send_inactivity_reminders.php', 'Even 10 minutes of review'],
            ['amd/src/voice.js', '\'Audio recording is not supported in this browser.\''],
            ['amd/src/voice.js', '\'Transcription failed. Please try again in a moment.\''],
            ['amd/src/voice.js', '\'Transcription is unavailable right now.\''],
            ['amd/src/voice.js', '\'Speech recognition is not supported in this browser. Please try Chrome or Edge.\''],
            ['amd/src/voice.js', '\'Microphone access denied. Please allow microphone access in your browser settings and try again.\''],
            ['amd/src/voice.js', '\'Speech recognition error: \''],
            ['amd/src/voice.js', '\'Could not start speech recognition. Please check microphone permissions.\''],
            ['amd/src/voice.js', '\'Audio playback is not available in this browser.\''],
            ['amd/src/voice.js', '\'Speech input is not supported in this browser.\''],
            ['amd/src/voice.js', '\'Microphone access denied. Please allow microphone access to use voice chat.\''],
            ['amd/src/chat.js', '\'Microphone access is not available in this browser.'],
            ['amd/src/chat.js', '\'Microphone access was blocked. Please allow microphone access and try again.'],
            ['amd/src/chat.js', '\'No microphone was found on this device.'],
            ['amd/src/chat.js', '\'Your microphone is busy in another app. Close the other app and try again.'],
            ['amd/src/chat.js', '\'Could not start live voice chat.'],
            ['amd/src/chat.js', '\'Voice chat is open for "'],
            ['amd/src/chat.js', '\'Voice chat is open for this page or another course topic.'],
            ['amd/src/chat.js', '\'Start a voice conversation about "'],
            ['amd/src/chat.js', '\'Start a voice conversation about this page'],
            ['amd/src/chat.js', '\'Ready when you are.'],
            ['amd/src/chat.js', '\'Voice chat is starting.'],
            ['amd/src/chat.js', '\'Voice chat is live.'],
            ['amd/src/chat.js', '\'Voice chat is not enabled for this course yet.'],
            ['amd/src/chat.js', '\'Voice chat needs HTTPS and microphone access.'],
            ['amd/src/chat.js', '\'Audio playback is not available in this browser.'],
            ['amd/src/chat.js', '\'This browser does not support speech input.'],
            ['amd/src/chat.js', '\'Could not refresh history right now.'],
            ['amd/src/chat.js', '\'Language set to '],
            ['amd/src/chat.js', '\'Language is locked to English for this course.'],
            ['amd/src/chat.js', '\'You have already completed this survey.'],
            ['amd/src/chat.js', '\'No testing tasks are available right now.'],
            ['amd/src/chat.js', '\'You have completed all testing tasks.'],
            ['amd/src/chat.js', '\'Could not load testing tasks.'],
            ['amd/src/chat.js', '\'Could not save the avatar choice.'],
            ['amd/src/chat.js', '\'Voice connection failed.'],
            ['amd/src/chat.js', '\'Voice mode failed.'],
            ['amd/src/chat.js', '\'Live voice isn\\\'t available right now'],
            ['amd/src/chat.js', '\'Please wait for the current response to finish'],
            ['amd/src/chat.js', '\'Voice chat needs a secure context'],
            ['amd/src/chat.js', '\'Pronunciation Practice is not enabled for this course yet.'],
            ['amd/src/chat.js', '\'Pronunciation Practice needs a secure context'],
            ['amd/src/chat.js', '\'Microphone error: '],
            ['amd/src/chat.js', '\'Could not generate a quiz. Please try again.'],
            ['amd/src/chat.js', '\'Copied!'],
            ['amd/src/chat.js', '\'Copy failed'],
            ['amd/src/quiz.js', '\'Difficulty\''],
            ['amd/src/quiz.js', '\'Easy\''],
            ['amd/src/quiz.js', '\'Medium\''],
            ['amd/src/quiz.js', '\'Hard\''],
            ['amd/src/quiz.js', '\'Question \''],
            ['amd/src/audio_player.js', '\'Play audio\''],
            ['amd/src/audio_player.js', '\'Pause audio\''],
            ['amd/src/realtime.js', '\'Unknown error\''],
            ['amd/src/learning_radar.js', '\'Error: '],
            ['amd/src/learning_radar.js', '\'Network error'],
            ['amd/src/learning_radar.js', '\'Redash query created.'],
            ['amd/src/learning_radar.js', '\'Redash push error: '],
            ['amd/src/learning_radar.js', '\'unknown error'],
            ['amd/src/learning_radar.js', '\'Recipient email address:'],
            ['amd/src/learning_radar.js', '\' incoming webhook URL:'],
            ['amd/src/learning_radar.js', '\'Sent.'],
            ['amd/src/learning_radar.js', '\'Send error: '],
            ['amd/src/learning_radar.js', '\'Type or run a query first.'],
            ['amd/src/learning_radar.js', '\'Share link copied to clipboard.'],
            ['amd/src/learning_radar.js', '\'Delete this schedule?'],
            ['amd/src/learning_radar.js', '\'Edit schedule'],
            ['amd/src/learning_radar.js', '\'Schedule a Learning Radar query'],
            ['amd/src/learning_radar.js', '\'Course IDs (e.g. 2,5,12)'],
            ['amd/src/learning_radar.js', '\'Provider (e.g. openai)'],
            ['amd/src/learning_radar.js', 'alert(\'Run a query first.\')'],
            ['templates/analytics_tab_comparison.mustache', '<th>Metric</th>'],
            ['templates/analytics_tab_comparison.mustache', '<th>AI Users</th>'],
            ['templates/analytics_tab_comparison.mustache', '<th>Non-Users</th>'],
            ['templates/analytics_tab_feedback.mustache', '<h5>Message Ratings</h5>'],
            ['templates/analytics_tab_feedback.mustache', '<h5>Star Rating Distribution</h5>'],
            ['templates/analytics_tab_feedback.mustache', 'Recent Negative Feedback</h5>'],
            ['templates/analytics_tab_feedback.mustache', '<th>Message Excerpt</th>'],
            ['templates/analytics_tab_feedback.mustache', '<th>Comment</th>'],
            ['templates/analytics_tab_feedback.mustache', '<th>Hallucination?</th>'],
            ['templates/analytics_tab_feedback.mustache', '<th>Date</th>'],
            ['templates/analytics_tab_overall.mustache', '<h5>Daily Usage Trend</h5>'],
            ['templates/analytics_tab_overall.mustache', '<h5>Hour of Day</h5>'],
            ['templates/analytics_tab_overall.mustache', '<h5>Day of Week</h5>'],
            ['templates/analytics_tab_themes.mustache', '<th>Keyword</th>'],
            ['templates/analytics_tab_themes.mustache', '<th>Frequency</th>'],
            ['templates/analytics_tab_themes.mustache', '<th>Category</th>'],
            ['templates/analytics_tab_usagetypes.mustache', '<th>Type</th>'],
            ['templates/analytics_tab_usagetypes.mustache', '<th>Count</th>'],
            ['templates/analytics_dashboard.mustache', 'Failed to generate insights: '],
            ['templates/analytics_dashboard.mustache', '\'Unknown error\''],
        ];
        $cases = [];
        foreach ($pairs as [$file, $fragment]) {
            $cases[$file . ' :: ' . $fragment] = [$file, $fragment];
        }
        return $cases;
    }

    /**
     * A literal that moved to a language string is not in the file any more.
     *
     * @dataProvider removed_literal_provider
     * @param string $file Path relative to the plugin root.
     * @param string $fragment The code fragment that held the English text.
     */
    public function test_literal_is_gone_from_code(string $file, string $fragment): void {
        $path = dirname(__DIR__) . '/' . $file;
        $this->assertFileExists($path);
        $this->assertStringNotContainsString($fragment, file_get_contents($path), "$file still holds $fragment");
    }

    /**
     * The keys added for these moves.
     *
     * @return array
     */
    public static function new_key_provider(): array {
        $keys = [
            'instructor_dashboard:review_summary_offtopic',
            'instructor_dashboard:review_summary_integrity',
            'reminder:inactivity_subject',
            'reminder:inactivity_since_days',
            'reminder:inactivity_since_awhile',
            'reminder:inactivity_body',
            'chat:voice_err_record_unsupported',
            'chat:voice_err_transcribe_failed',
            'chat:voice_err_transcribe_unavailable',
            'chat:voice_err_recognition_unsupported',
            'chat:voice_err_mic_denied_settings',
            'chat:voice_err_recognition',
            'chat:voice_err_recognition_start',
            'chat:voice_err_playback_unavailable',
            'chat:voice_err_mic_denied_chat',
            'chat:voice_err_mic_blocked',
            'chat:voice_err_mic_missing',
            'chat:voice_err_mic_busy',
            'chat:voice_err_mic_unavailable',
            'chat:voice_err_start_failed',
            'chat:voice_err_connection_failed',
            'chat:voice_err_mode_failed',
            'chat:voice_live_fallback',
            'chat:voice_err_speech_input',
            'chat:voice_err_wait_response',
            'chat:voice_err_not_enabled',
            'chat:voice_err_secure_context',
            'chat:voice_err_pron_not_enabled',
            'chat:voice_err_pron_secure_context',
            'chat:voicepanel_open_page',
            'chat:voicepanel_open_generic',
            'chat:voicepanel_start_page',
            'chat:voicepanel_start_generic',
            'chat:voicepanel_status_ready',
            'chat:voicepanel_status_starting',
            'chat:voicepanel_status_live',
            'chat:voicepanel_status_https',
            'chat:history_refresh_failed',
            'chat:language_set',
            'chat:language_locked',
            'chat:survey_already_done',
            'chat:usertesting_none',
            'chat:usertesting_done',
            'chat:usertesting_load_failed',
            'chat:avatar_save_failed',
            'chat:error_stream_cap',
            'chat:error_stream_connection',
            'chat:audio_play',
            'chat:audio_pause',
            'chat:quiz_difficulty',
            'chat:quiz_difficulty_easy',
            'chat:quiz_difficulty_medium',
            'chat:quiz_difficulty_hard',
            'chat:quiz_progress',
            'analytics:msg_ratings',
            'analytics:star_distribution',
            'analytics:recent_negative',
            'analytics:msg_excerpt',
            'analytics:hallucination_q',
            'analytics:daily_usage_trend',
            'analytics:hour_of_day',
            'analytics:day_of_week',
            'analytics:keyword',
            'analytics:category',
            'analytics:usage_type',
            'analytics:insights_failed',
            'analytics:unknown_error',
            'radar:js_error_prefix',
            'radar:js_network_error',
            'radar:js_redash_created_confirm',
            'radar:js_redash_push_error',
            'radar:js_unknown_error',
            'radar:js_email_prompt',
            'radar:js_slack_prompt',
            'radar:js_teams_prompt',
            'radar:js_sent',
            'radar:js_send_error',
            'radar:js_type_query_first',
            'radar:js_share_copied',
            'radar:js_delete_schedule_confirm',
            'radar:js_schedule_edit',
            'radar:js_schedule_new',
            'radar:js_placeholder_courseids',
            'radar:js_placeholder_provider',
        ];
        $cases = [];
        foreach ($keys as $key) {
            $cases[$key] = [$key];
        }
        return $cases;
    }

    /**
     * Every new key is defined in lang/en and is asked for by some code.
     *
     * @dataProvider new_key_provider
     * @param string $key Lang string identifier.
     */
    public function test_new_key_exists_and_is_used(string $key): void {
        $string = [];
        include(dirname(__DIR__) . '/lang/en/local_ai_course_assistant.php');
        $this->assertArrayHasKey($key, $string, "$key is missing from lang/en");
        $this->assertNotSame('', trim($string[$key]));

        $root = dirname(__DIR__);
        $found = false;
        foreach (['amd/src', 'templates', 'classes', '.'] as $dir) {
            $base = $root . '/' . $dir;
            $files = $dir === '.'
                ? glob($base . '/*.php')
                : new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                $name = (string) $file;
                if (!preg_match('/\.(js|php|mustache)$/', $name)) {
                    continue;
                }
                if (strpos(file_get_contents($name), $key) !== false) {
                    $found = true;
                    break 2;
                }
            }
        }
        $this->assertTrue($found, "$key is defined but no code asks for it");
    }
}
