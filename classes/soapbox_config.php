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

defined('MOODLE_INTERNAL') || die();

/**
 * Soapbox video assignment configuration: admin caps, quality presets, and the
 * pure clamp that keeps instructor-supplied assignment settings within the
 * admin caps and valid ranges (v6.8.12).
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class soapbox_config {

    /** @var int Fallback ceiling on recording length (12 minutes). */
    const DEFAULT_MAX_SECONDS = 720;

    /** @var int Fallback ceiling on recordings per student per assignment. */
    const DEFAULT_MAX_RECORDINGS = 3;

    /** @var int Fallback retention window in days. */
    const DEFAULT_RETENTION_DAYS = 7;

    /** @var int Hard maximum retention window in days. */
    const RETENTION_MAX_DAYS = 28;

    /** @var string Default video quality preset key. */
    const DEFAULT_QUALITY = 'standard_480p';

    /** @var string[] Recording modes. */
    const MODES = ['video', 'audio'];

    /**
     * Video quality presets. Cost scales with MB/min, so this is the main cost
     * and bandwidth dial. Bitrates in kbps; the recorder caps MediaRecorder to
     * these values.
     *
     * @var array<string, array{width:int, height:int, video_kbps:int, audio_kbps:int, label:string}>
     */
    const QUALITY_PRESETS = [
        'low_360p' => [
            'width' => 640, 'height' => 360, 'video_kbps' => 350, 'audio_kbps' => 32,
            'label' => 'Low (360p)',
        ],
        'standard_480p' => [
            'width' => 854, 'height' => 480, 'video_kbps' => 500, 'audio_kbps' => 40,
            'label' => 'Standard (480p)',
        ],
        'high_720p' => [
            'width' => 1280, 'height' => 720, 'video_kbps' => 1200, 'audio_kbps' => 48,
            'label' => 'High (720p)',
        ],
    ];

    /**
     * Admin ceiling on any assignment's recording length, in seconds.
     *
     * @return int
     */
    public static function max_seconds(): int {
        $v = (int) get_config('local_ai_course_assistant', 'soapbox_max_seconds');
        return $v > 0 ? $v : self::DEFAULT_MAX_SECONDS;
    }

    /**
     * Admin ceiling on recordings per student per assignment.
     *
     * @return int
     */
    public static function max_recordings(): int {
        $v = (int) get_config('local_ai_course_assistant', 'soapbox_max_recordings');
        return $v > 0 ? $v : self::DEFAULT_MAX_RECORDINGS;
    }

    /**
     * Effective retention window in days, clamped to [1, RETENTION_MAX_DAYS].
     *
     * @return int
     */
    public static function retention_days(): int {
        $raw = get_config('local_ai_course_assistant', 'soapbox_retention_days');
        $v = ($raw === false || $raw === '') ? self::DEFAULT_RETENTION_DAYS : (int) $raw;
        return self::clamp_retention_days($v);
    }

    /**
     * Clamp a retention day count to [1, RETENTION_MAX_DAYS]. Pure.
     *
     * @param int $days
     * @return int
     */
    public static function clamp_retention_days(int $days): int {
        if ($days < 1) {
            return 1;
        }
        if ($days > self::RETENTION_MAX_DAYS) {
            return self::RETENTION_MAX_DAYS;
        }
        return $days;
    }

    /**
     * The configured video quality preset (falls back to the default).
     *
     * @return array{width:int, height:int, video_kbps:int, audio_kbps:int, label:string}
     */
    public static function quality(): array {
        $key = (string) get_config('local_ai_course_assistant', 'soapbox_video_quality');
        return self::QUALITY_PRESETS[$key] ?? self::QUALITY_PRESETS[self::DEFAULT_QUALITY];
    }

    /**
     * How many recordings a student may actually make for an assignment: the
     * per-assignment attempts value (0 = unlimited) bounded by the admin max.
     *
     * @param int $maxattempts Per-assignment attempts (0 = unlimited).
     * @return int
     */
    public static function effective_recording_cap(int $maxattempts): int {
        $maxrec = self::max_recordings();
        if ($maxattempts <= 0) {
            return $maxrec;
        }
        return min($maxattempts, $maxrec);
    }

    /**
     * Clamp instructor-supplied assignment settings to the admin caps and valid
     * ranges. Pure (reads admin caps via the static getters, no other IO), so
     * the editor and the upload validator both enforce identical bounds.
     *
     * Recognized keys: mode, ptype, min_seconds, max_seconds, max_attempts,
     * stored_attempts. Unknown keys pass through untouched.
     *
     * @param array $in
     * @return array
     */
    public static function clamp_assignment(array $in): array {
        $maxsec = self::max_seconds();
        $maxrec = self::max_recordings();
        $out = $in;

        $mode = $in['mode'] ?? 'video';
        $out['mode'] = in_array($mode, self::MODES, true) ? $mode : 'video';

        $ptype = strtolower(trim((string) ($in['ptype'] ?? 'informative')));
        $out['ptype'] = ($ptype !== '') ? $ptype : 'informative';

        $min = max(1, (int) ($in['min_seconds'] ?? 300));
        $max = max(1, (int) ($in['max_seconds'] ?? 420));
        if ($max > $maxsec) {
            $max = $maxsec;
        }
        if ($min > $max) {
            $min = $max;
        }
        $out['min_seconds'] = $min;
        $out['max_seconds'] = $max;

        // 0 = unlimited (still bounded by the admin max recordings at record time).
        $out['max_attempts'] = max(0, (int) ($in['max_attempts'] ?? 0));

        $stored = max(1, (int) ($in['stored_attempts'] ?? 2));
        if ($stored > $maxrec) {
            $stored = $maxrec;
        }
        $out['stored_attempts'] = $stored;

        // v6.8.21: slides on/off (student uploads a PDF deck and advances it).
        $out['slides_enabled'] = !empty($in['slides_enabled']) ? 1 : 0;

        return $out;
    }

    /** @var int Max slide-advance events kept in a timeline (guards absurd input). */
    const MAX_TIMELINE_EVENTS = 500;

    /**
     * Normalize a slide-advance timeline to a clean, bounded, sorted list of
     * {t, i} entries (t = seconds into the recording, i = 0-based slide index).
     * Pure so the recorder's captured timeline can be sanitized identically on
     * the way in. Drops malformed entries, clamps negatives, sorts by time, and
     * caps the count.
     *
     * @param mixed $raw Decoded JSON (array) or a JSON string.
     * @param int $slidecount Number of slides in the deck (0 = unknown, no upper clamp on index).
     * @return array List of ['t' => int, 'i' => int].
     */
    public static function normalize_slide_timeline($raw, int $slidecount = 0): array {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $entry) {
            if (!is_array($entry) || !array_key_exists('t', $entry) || !array_key_exists('i', $entry)) {
                continue;
            }
            $t = (int) round((float) $entry['t']);
            $i = (int) $entry['i'];
            if ($t < 0) {
                $t = 0;
            }
            if ($i < 0) {
                $i = 0;
            }
            if ($slidecount > 0 && $i > $slidecount - 1) {
                $i = $slidecount - 1;
            }
            $out[] = ['t' => $t, 'i' => $i];
        }
        usort($out, fn($a, $b) => $a['t'] <=> $b['t']);
        if (count($out) > self::MAX_TIMELINE_EVENTS) {
            $out = array_slice($out, 0, self::MAX_TIMELINE_EVENTS);
        }
        return $out;
    }
}
