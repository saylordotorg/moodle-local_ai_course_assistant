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

namespace local_ai_course_assistant\extractors;

/**
 * Extracts text content from mod_h5p activities.
 *
 * Loads the H5P content.json parameters, walks the tree, and collects
 * values from a whitelist of content-bearing keys (text, title, question,
 * answer, description, feedback, etc.). HTML is stripped and adjacent
 * duplicate lines deduplicated.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025 AI Course Assistant
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class h5p_extractor {

    /** @var string[] Keys whose string values we treat as user-facing content. */
    private const CONTENT_KEYS = [
        'text', 'title', 'question', 'answer', 'description', 'feedback',
        'correctText', 'incorrectText', 'hint', 'solution', 'alt', 'prompt',
        'contentText', 'label', 'tip',
    ];

    /**
     * Extract text from a mod_h5p instance.
     *
     * @param int $instance mod_h5p instance id.
     * @param int $courseid Course id.
     * @return string Plain text, or empty string if extraction disabled / unavailable.
     */
    public static function extract(int $instance, int $courseid): string {
        global $DB;

        if (!self::is_enabled('rag_extract_h5p', true)) {
            return '';
        }

        try {
            $record = $DB->get_record('h5pactivity', ['id' => $instance, 'course' => $courseid], 'id, name');
            if (!$record) {
                return '';
            }

            $cm = get_coursemodule_from_instance('h5pactivity', $instance, $courseid, false, IGNORE_MISSING);
            if (!$cm) {
                return '';
            }

            $cmcontext = \context_module::instance($cm->id);
            $fs = get_file_storage();

            // Grab the deployed .h5p package attached to the activity.
            $files = $fs->get_area_files(
                $cmcontext->id,
                'mod_h5pactivity',
                'package',
                0,
                'sortorder DESC, id ASC',
                false
            );

            $packagefile = null;
            foreach ($files as $candidate) {
                if (!$candidate->is_directory()) {
                    $packagefile = $candidate;
                    break;
                }
            }

            $json = null;
            if ($packagefile !== null) {
                $json = self::read_content_json_from_h5p($packagefile);
            }

            // Fallback: look up the deployed H5P content in mdl_h5p via pathnamehash.
            if ($json === null && $packagefile !== null && $DB->get_manager()->table_exists('h5p')) {
                $pathnamehash = $packagefile->get_pathnamehash();
                $h5prec = $DB->get_record('h5p', ['pathnamehash' => $pathnamehash], 'id, jsoncontent');
                if ($h5prec && !empty($h5prec->jsoncontent)) {
                    $json = $h5prec->jsoncontent;
                }
            }

            if (!is_string($json) || $json === '') {
                return '';
            }

            $decoded = json_decode($json, true);
            if (!is_array($decoded)) {
                return '';
            }

            $lines = [];
            self::walk($decoded, $lines);

            if (!$lines) {
                return '';
            }

            // Deduplicate adjacent identical lines while preserving order.
            $out = [];
            $prev = null;
            foreach ($lines as $line) {
                if ($line === $prev) {
                    continue;
                }
                $out[] = $line;
                $prev = $line;
            }

            return trim(implode("\n", $out));
        } catch (\Throwable $e) {
            debugging('h5p_extractor failed for instance ' . $instance . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            return '';
        }
    }

    /**
     * Read content.json out of an .h5p ZIP package.
     *
     * @param \stored_file $file Deployed .h5p package.
     * @return string|null JSON string, or null if not readable.
     */
    private static function read_content_json_from_h5p(\stored_file $file): ?string {
        if (!class_exists('ZipArchive')) {
            return null;
        }

        // Request-scoped temp file via the Moodle File API (unique, not
        // world-writable, auto-removed at end of request).
        $tmppath = make_request_directory() . '/package.h5p';
        if ($tmppath === false) {
            return null;
        }

        try {
            $file->copy_content_to($tmppath);
            $zip = new \ZipArchive();
            if ($zip->open($tmppath) !== true) {
                return null;
            }
            $json = $zip->getFromName('content/content.json');
            $zip->close();
            return is_string($json) && $json !== '' ? $json : null;
        } catch (\Throwable $e) {
            return null;
        } finally {
            if (is_file($tmppath)) {
                @unlink($tmppath);
            }
        }
    }

    /**
     * Recursively walk the parsed JSON tree, collecting content strings.
     *
     * @param mixed    $node  Current node (array, scalar, etc.).
     * @param string[] $lines Accumulator for collected lines (by reference).
     * @return void
     */
    private static function walk($node, array &$lines): void {
        if (is_array($node)) {
            foreach ($node as $key => $value) {
                if (is_string($key) && is_string($value) && in_array($key, self::CONTENT_KEYS, true)) {
                    $clean = self::clean_value($value);
                    if ($clean !== '') {
                        $lines[] = $clean;
                    }
                } else if (is_array($value)) {
                    self::walk($value, $lines);
                }
            }
        }
    }

    /**
     * Strip HTML and normalize whitespace from a collected string.
     *
     * @param string $value
     * @return string
     */
    private static function clean_value(string $value): string {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = strip_tags($value);
        $value = preg_replace('/\s+/', ' ', $value);
        return trim($value);
    }

    /**
     * Read a boolean-ish plugin setting with a default when absent.
     *
     * @param string $name
     * @param bool   $default
     * @return bool
     */
    private static function is_enabled(string $name, bool $default): bool {
        $raw = get_config('local_ai_course_assistant', $name);
        if ($raw === false || $raw === null || $raw === '') {
            return $default;
        }
        return (bool) (int) $raw;
    }
}
