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
 * Single source of truth for product and institution branding strings.
 *
 * Every user-facing surface (widget, admin pages, privacy notice, download
 * filenames, consent banner, audit log action labels) routes through this
 * class so an operator can rebrand the plugin end to end by setting a
 * handful of admin config values — no code changes, no string file edits.
 *
 * Admin config keys consulted:
 *   display_name              → full product name, e.g. "Online Learning Assistant"
 *   short_name                → short product name, e.g. "SOLA"
 *   institution_name          → full institution name
 *   institution_short_name    → short institution name
 *   dpo_email                 → Data Protection Officer contact email
 *   privacy_external_url      → institution-level privacy page URL
 *
 * Defaults are neutral (8.4.1): a blank setting falls back to this site's own
 * name, support email and site policy page, never to Saylor's. Saylor's own
 * sites keep their values because the 8.4.1 upgrade writes them explicitly
 * (see site_defaults).
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class branding {
    /**
     * Full product name, used in UI headings and learner-facing docs.
     */
    public static function display_name(): string {
        $v = get_config('local_ai_course_assistant', 'display_name');
        return $v !== false && $v !== '' ? $v
            : get_string('branding:default_display_name', 'local_ai_course_assistant');
    }

    /**
     * Short product name, used in buttons, tooltips, filenames, and tight
     * text where the full display name would not fit.
     */
    public static function short_name(): string {
        $v = get_config('local_ai_course_assistant', 'short_name');
        return $v !== false && $v !== '' ? $v : 'SOLA';
    }

    /**
     * Full institution name, used in the privacy notice, consent banner,
     * and any operator-branded copy.
     */
    public static function institution_name(): string {
        $v = get_config('local_ai_course_assistant', 'institution_name');
        return $v !== false && $v !== '' ? $v : self::site_name(false);
    }

    /**
     * Short institution name, used where space is tight.
     */
    public static function institution_short_name(): string {
        $v = get_config('local_ai_course_assistant', 'institution_short_name');
        return $v !== false && $v !== '' ? $v : self::site_name(true);
    }

    /**
     * General contact email for the institution, surfaced on the privacy
     * notice's Contact section. Replaces the dedicated DPO email line for
     * institutions that route privacy questions through general support.
     */
    public static function contact_email(): string {
        $v = get_config('local_ai_course_assistant', 'contact_email');
        return $v !== false && $v !== '' ? $v : self::site_support_email();
    }

    /**
     * Data Protection Officer contact email. Retained for backward
     * compatibility (was the canonical contact in v3.9.15-v4.1.8). The
     * default privacy notice now uses {@see contact_email()} instead;
     * this remains available for installations that want to surface a
     * separate DPO line via a custom override.
     */
    public static function dpo_email(): string {
        $v = get_config('local_ai_course_assistant', 'dpo_email');
        return $v !== false && $v !== '' ? $v : self::site_support_email();
    }

    /**
     * Institution-level privacy page URL surfaced in the privacy notice.
     */
    public static function privacy_external_url(): string {
        $v = get_config('local_ai_course_assistant', 'privacy_external_url');
        return $v !== false && $v !== '' ? $v : self::site_policy_url();
    }

    /**
     * This site's own name (8.4.1 neutral default for the institution names).
     *
     * @param bool $short the short name rather than the full name
     * @return string plain text
     */
    private static function site_name(bool $short): string {
        global $SITE;
        $site = $SITE ?? null;
        if (!$site || empty($site->fullname)) {
            try {
                $site = get_site();
            } catch (\Throwable $e) {
                $site = null;
            }
        }
        $name = $site ? (string) ($short ? ($site->shortname ?: $site->fullname) : ($site->fullname ?: $site->shortname))
            : '';
        if ($name === '') {
            return get_string('pluginname', 'local_ai_course_assistant');
        }
        return format_string($name, true, ['context' => \context_system::instance(), 'escape' => false]);
    }

    /**
     * This site's support email (Moodle's support contact; Moodle itself falls back to the no-reply address).
     *
     * @return string
     */
    private static function site_support_email(): string {
        try {
            return (string) (\core_user::get_support_user()->email ?? '');
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * This site's policy page, when one is configured, else ''.
     *
     * @return string
     */
    private static function site_policy_url(): string {
        try {
            $url = (new \core_privacy\local\sitepolicy\manager())->get_redirect_url(false);
            return $url ? $url->out(false) : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Lowercase dash-safe slug derived from the short product name. Used
     * for filenames and identifiers that must be filesystem-friendly.
     *
     * Example: "State U Tutor" → "state-u-tutor"
     */
    public static function filename_slug(): string {
        $short = self::short_name();
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $short));
        $slug = trim($slug, '-');
        return $slug !== '' ? $slug : 'ai-course-assistant';
    }

    // ------------------------------------------------------------------
    // Brand-token substitution.
    //
    // Lang strings, the default system prompt, emails, and any other
    // brand-bearing copy embed four tokens instead of literal names, so the
    // whole product (and the operator-facing copy) rebrands from the four
    // admin settings with no code or string-file edits:
    //
    // [[tutorname]]  → display_name()            (e.g. "Online Learning Assistant")
    // [[tutorshort]] → short_name()              (e.g. "SOLA")
    // [[uniname]]    → institution_name()        (e.g. "State University")
    // [[unishort]]   → institution_short_name()  (e.g. "State U")
    //
    // Substitution happens at output boundaries (the JS string bundle, the
    // mustache template data, the system prompt builder, admin settings copy,
    // and the standalone learner/admin pages) via apply() / str(), and in the
    // browser via the token map exposed to JS. The double-square-bracket
    // delimiter is deliberately distinct from Moodle's `{$a}` and mustache's
    // `{{ }}` so a token can never be misread by either engine.
    // ------------------------------------------------------------------

    /** @var string Regex matching a single brand token, e.g. [[tutorshort]]. */
    private const TOKEN_RE = '/\[\[(tutorname|tutorshort|uniname|unishort)\]\]/';

    /**
     * Map of brand token name → its configured value. Single place that binds
     * the token vocabulary to the four accessors; consumed by apply() and by
     * the browser via token_map_json().
     *
     * @return array<string, string>
     */
    public static function token_map(): array {
        return [
            'tutorname'  => self::display_name(),
            'tutorshort' => self::short_name(),
            'uniname'    => self::institution_name(),
            'unishort'   => self::institution_short_name(),
        ];
    }

    /**
     * Substitute every brand token in a piece of text with its configured
     * value. Null-safe (returns '' for null) and a no-op for text that holds
     * no tokens, so it is cheap to apply broadly at output boundaries.
     *
     * @param string|null $text Text that may contain [[token]] placeholders.
     * @return string The text with all brand tokens resolved.
     */
    public static function apply(?string $text): string {
        if ($text === null || $text === '') {
            return (string) $text;
        }
        if (strpos($text, '[[') === false) {
            return $text;
        }
        $map = self::token_map();
        return preg_replace_callback(self::TOKEN_RE, static function ($m) use ($map) {
            return $map[$m[1]] ?? $m[0];
        }, $text);
    }

    /**
     * Fetch a Moodle lang string and resolve its brand tokens in one call.
     * Drop-in replacement for get_string() at brand-bearing call sites.
     *
     * @param string $identifier Lang string key.
     * @param array|object|string|null $a Standard get_string $a substitution.
     * @param string $component Lang component (defaults to this plugin).
     * @return string The fully-resolved, brand-substituted string.
     */
    public static function str(string $identifier, $a = null, string $component = 'local_ai_course_assistant'): string {
        return self::apply(get_string($identifier, $component, $a));
    }

    /**
     * The token map as a JSON object for embedding in an inline script, so the
     * browser can resolve brand tokens in strings fetched at runtime via the
     * Moodle string API. JSON_HEX_TAG guards against a value containing
     * "</script>" breaking out of the inline tag.
     *
     * @return string JSON object, e.g. {"tutorshort":"SOLA", ...}.
     */
    public static function token_map_json(): string {
        return json_encode(self::token_map(), JSON_HEX_TAG | JSON_UNESCAPED_UNICODE);
    }
}
