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

namespace local_ai_course_assistant\autoupgrade;

use local_ai_course_assistant\branding;
use local_ai_course_assistant\email_footer;
use local_ai_course_assistant\email_optout;

/**
 * Emails about model switches, rollbacks and recommendations (v7.8.0).
 *
 * The same recipients and the same plumbing as the spend alerts: the
 * spend_notify_emails setting, falling back to the site administrators, the
 * spend-alert opt-out type, and the standard unsubscribe footer. A model
 * switch is a spend decision, and the people who get spend alerts are the
 * people who need to know the model changed.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class notifier {
    /**
     * A role was switched.
     *
     * @param \stdClass $switch
     * @param \stdClass $eval
     * @return int Emails sent.
     */
    public static function switched(\stdClass $switch, \stdClass $eval): int {
        $a = self::switch_strings($switch);
        $a->gate = self::gate_lines($eval);
        return self::send(
            branding::str('autoupgrade:mail_switched_subject', $a),
            branding::str('autoupgrade:mail_switched_body', $a)
        );
    }

    /**
     * A switch was rolled back.
     *
     * @param \stdClass $switch
     * @param string $reason
     * @return int
     */
    public static function rolledback(\stdClass $switch, string $reason): int {
        $a = self::switch_strings($switch);
        $a->reason = $reason;
        return self::send(
            branding::str('autoupgrade:mail_rolledback_subject', $a),
            branding::str('autoupgrade:mail_rolledback_body', $a)
        );
    }

    /**
     * A switch survived its watch window.
     *
     * @param \stdClass $switch
     * @param array $live
     * @param bool $thin Fewer answers than the watcher needs to judge.
     * @return int
     */
    public static function kept(\stdClass $switch, array $live, bool $thin): int {
        $a = self::switch_strings($switch);
        $a->turns = (int) $live['turns'];
        return self::send(
            branding::str('autoupgrade:mail_kept_subject', $a),
            branding::str($thin ? 'autoupgrade:mail_kept_thin_body' : 'autoupgrade:mail_kept_body', $a)
        );
    }

    /**
     * A candidate is eligible but was not switched; say so once.
     *
     * @param \stdClass $cand
     * @param \stdClass $eval
     * @param string $why
     * @return int
     */
    public static function recommend(\stdClass $cand, \stdClass $eval, string $why): int {
        $flag = 'autoupgrade_recommended_' . (int) $cand->id . '_' . (int) $eval->id;
        if (get_config('local_ai_course_assistant', $flag)) {
            return 0;
        }
        $a = (object) [
            'role' => get_string('autoupgrade:role_' . $cand->role, 'local_ai_course_assistant'),
            'model' => $cand->provider . '/' . $cand->model . switcher::variant_suffix((string) $cand->variant),
            'current' => $eval->inc_provider . '/' . $eval->inc_model . switcher::variant_suffix((string) $eval->inc_variant),
            'why' => $why,
            'gate' => self::gate_lines($eval),
            'url' => (new \moodle_url('/local/ai_course_assistant/model_upgrades.php'))->out(false),
        ];
        $sent = self::send(
            branding::str('autoupgrade:mail_recommend_subject', $a),
            branding::str('autoupgrade:mail_recommend_body', $a)
        );
        set_config($flag, 1, 'local_ai_course_assistant');
        return $sent;
    }

    /**
     * A voice model call was refused as an unknown model and retried on its catalog successor (8.1.0).
     *
     * Once per provider, old and new model: the same refusal on every learner's speak button must not become an
     * email each.
     *
     * @param string $provider
     * @param string $from The configured model the provider refused.
     * @param string $to The catalog successor that was tried instead.
     * @param bool $worked Whether the retry produced audio.
     * @return int Emails sent.
     */
    public static function voice_model_fallback(string $provider, string $from, string $to, bool $worked): int {
        $flag = 'voice_model_fallback_' . substr(sha1($provider . '|' . $from . '|' . $to . '|' . (int) $worked), 0, 16);
        if (get_config('local_ai_course_assistant', $flag)) {
            return 0;
        }
        $a = (object) [
            'provider' => $provider,
            'from' => $from,
            'to' => $to,
            'url' => (new \moodle_url('/admin/settings.php',
                ['section' => 'local_ai_course_assistant_adv_prompt']))->out(false),
        ];
        $sent = self::send(
            branding::str('voicemodels:mail_fallback_subject', $a),
            branding::str($worked ? 'voicemodels:mail_fallback_body' : 'voicemodels:mail_fallback_failed_body', $a)
        );
        set_config($flag, (string) time(), 'local_ai_course_assistant');
        return $sent;
    }

    /**
     * The daily voice model report: models in use that retire soon, and catalog models a provider no longer lists.
     *
     * @param string $lines One finding per line, already worded.
     * @return int Emails sent.
     */
    public static function voice_model_report(string $lines): int {
        $a = (object) [
            'lines' => $lines,
            'url' => (new \moodle_url('/admin/settings.php',
                ['section' => 'local_ai_course_assistant_adv_prompt']))->out(false),
        ];
        return self::send(
            branding::str('voicemodels:mail_watch_subject', $a),
            branding::str('voicemodels:mail_watch_body', $a)
        );
    }

    /**
     * Placeholders shared by the switch emails.
     *
     * @param \stdClass $switch
     * @return \stdClass
     */
    private static function switch_strings(\stdClass $switch): \stdClass {
        return (object) [
            'role' => get_string('autoupgrade:role_' . $switch->role, 'local_ai_course_assistant'),
            'from' => $switch->from_provider . '/' . $switch->from_model . switcher::variant_suffix((string) $switch->from_variant),
            'to' => $switch->to_provider . '/' . $switch->to_model . switcher::variant_suffix((string) $switch->to_variant),
            'hours' => watcher::WATCH_HOURS,
            'url' => (new \moodle_url('/local/ai_course_assistant/model_upgrades.php'))->out(false),
        ];
    }

    /**
     * One line per gate check.
     *
     * @param \stdClass $eval
     * @return string
     */
    private static function gate_lines(\stdClass $eval): string {
        $lines = [];
        foreach ((json_decode((string) $eval->gate_detail, true) ?: []) as $name => $check) {
            $lines[] = '- ' . $name . ': ' . (!empty($check['ok']) ? 'pass' : 'FAIL') . ' (' . ($check['detail'] ?? '') . ')';
        }
        return implode("\n", $lines);
    }

    /**
     * Send one email to every recipient who has not opted out.
     *
     * @param string $subject
     * @param string $body
     * @return int Emails sent.
     */
    private static function send(string $subject, string $body): int {
        $recipients = trim((string) (get_config('local_ai_course_assistant', 'spend_notify_emails') ?: ''));
        if ($recipients === '') {
            $recipients = implode(',', array_map(static function ($a) {
                return $a->email;
            }, get_admins()));
        }
        $sent = 0;
        $reason = branding::str('autoupgrade:mail_reason');
        foreach (array_filter(array_map('trim', explode(',', $recipients))) as $email) {
            try {
                if (email_optout::is_opted_out($email, email_optout::TYPE_SPEND_ALERT)) {
                    continue;
                }
            } catch (\Throwable $e) {
                unset($e);
            }
            $to = \core_user::get_noreply_user();
            $to = clone $to;
            $to->email = $email;
            $to->id = -99;
            $text = email_footer::append_text($body, $email, email_optout::TYPE_SPEND_ALERT, $reason);
            try {
                if (email_to_user($to, \core_user::get_noreply_user(), $subject, $text)) {
                    $sent++;
                }
            } catch (\Throwable $e) {
                debugging('Model upgrade email failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
        return $sent;
    }
}
