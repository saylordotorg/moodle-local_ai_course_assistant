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

/**
 * Admin settings for local_ai_course_assistant.
 *
 * Settings are organized into an admin category with 7 settings pages
 * and 7 external admin pages, visible under Site administration > Plugins > Local plugins.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025-2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    // Read plugin version for the banner text.
    $pluginfo   = core_plugin_manager::instance()->get_plugin_info('local_ai_course_assistant');
    $release    = $pluginfo ? htmlspecialchars($pluginfo->release, ENT_QUOTES) : '?';
    $versionnum = $pluginfo ? htmlspecialchars($pluginfo->versiondisk, ENT_QUOTES) : '?';
    $shortname  = htmlspecialchars(get_config('local_ai_course_assistant', 'short_name') ?: 'SOLA', ENT_QUOTES);
    $versionbanner = '<div style="display:inline-flex;align-items:center;gap:.5rem;background:#f0f4ff;'
        . 'border:1px solid #c7d4f7;border-radius:6px;padding:.4rem .85rem;margin-bottom:.75rem;font-size:.85rem;color:#3b5bdb;">'
        . '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">'
        . '<path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/>'
        . '</svg>'
        . '<strong>' . $shortname . '</strong>&nbsp;v' . $release . '&nbsp;<span style="color:#868e96;">(' . $versionnum . ')</span>'
        . '</div>';

    // ── Create the plugin admin category ────────────────────────────────────
    // Admin tools live under this category as external pages; all settings
    // live on a single "Settings" page with TOC navigation.
    // Reachable at: Site admin > Plugins > Local plugins > AI Course Assistant
    $ADMIN->add('localplugins', new admin_category(
        'local_ai_course_assistant',
        get_string('pluginname', 'local_ai_course_assistant')
    ));

    // ── Single settings page with TOC ───────────────────────────────────────
    $settings = new admin_settingpage('local_ai_course_assistant_general', 'Settings');

    // The TOC and quicklink styling lives in styles.css (search .sola-toc).
    // It used to be an inline <style> block injected through an
    // admin_setting_description here, which is one of the things the plugin
    // directory review asked us to stop doing; it came back when the TOC was
    // added. Moodle loads the plugin's styles.css on admin pages, so nothing
    // needs injecting.
    $tocstyle = '';

    $analyticsurl = new moodle_url('/local/ai_course_assistant/analytics.php');
    $tokenanalyticsurl = new moodle_url('/local/ai_course_assistant/token_analytics.php');
    $demoadminurl = new moodle_url('/local/ai_course_assistant/demo_admin.php');
    $playgroundurl = new moodle_url('/local/ai_course_assistant/prompt_playground.php');

    // v6.1.0: emergency panel one click from the top of settings — the
    // review found the kill switch was CLI-only, leaving incident response
    // dependent on SSH access.
    $emergencyurl = new moodle_url('/local/ai_course_assistant/emergency_admin.php');
    // v7.2.1: the audit log is reachable. Two settings told admins to go and
    // read it -- Emergency Controls and per-call failover -- while no page
    // existed to read it in.
    $auditurl = new moodle_url('/local/ai_course_assistant/audit_log.php');
    $quicklinks = '<a href="' . $analyticsurl->out() . '">'
            . get_string('toc:analytics', 'local_ai_course_assistant') . '</a>'
        . '<a href="' . $tokenanalyticsurl->out() . '">'
            . get_string('toc:tokenanalytics', 'local_ai_course_assistant') . '</a>'
        . '<a href="' . $demoadminurl->out() . '">'
            . get_string('toc:testing', 'local_ai_course_assistant') . '</a>'
        . '<a href="' . $playgroundurl->out() . '">Prompt Playground</a>'
        . '<a href="' . $auditurl->out() . '">'
            . \local_ai_course_assistant\branding::str('auditlog:settings_link') . '</a>'
        . '<a href="' . $emergencyurl->out() . '" style="color:#b91c1c;font-weight:600">'
            . get_string('emergency:settings_link', 'local_ai_course_assistant') . '</a>';

    // "Back to last course" + "Course AI Settings" shortcuts. Pref set on course visits
    // by hook_callbacks. Two buttons so admins can pivot to the course OR to its
    // per-course AI settings page without hunting. v3.9.9+.
    global $DB, $USER;
    $lastcourseid = (int) get_user_preferences('local_ai_course_assistant_last_courseid', 0);
    if ($lastcourseid > 0 && $lastcourseid !== (int) SITEID) {
        $lastcourse = $DB->get_record('course', ['id' => $lastcourseid], 'id,shortname,fullname,visible');
        if ($lastcourse) {
            $lastlabel = $lastcourse->shortname !== '' ? $lastcourse->shortname : $lastcourse->fullname;
            $lasturl = new moodle_url('/course/view.php', ['id' => $lastcourseid]);
            $coursesettingsurl = new moodle_url(
                '/local/ai_course_assistant/course_settings.php',
                ['courseid' => $lastcourseid]
            );
            $backlabel = str_replace(
                '{$a}',
                s($lastlabel),
                get_string('toc:back_to_course', 'local_ai_course_assistant')
            );
            $courseaiurl = '<a href="' . $coursesettingsurl->out() . '" title="'
                . s($lastcourse->fullname) . '" style="background:#495057;border-color:#495057;">'
                . '&#9881; ' . s($lastlabel) . ' AI settings</a>';
            $backbtn = '<a href="' . $lasturl->out() . '" title="'
                . s($lastcourse->fullname) . '" style="background:#6c757d;border-color:#6c757d;">'
                . $backlabel . '</a>';
            $quicklinks = $backbtn . $courseaiurl . $quicklinks;
        }
    }

    // v5.5.6: TOC entries ordered by admin frequency-of-use (highest first)
    // rather than by section-block order in this file. Branding & UI sits
    // ahead of Content & RAG and Safety because first-install rebranding is
    // a higher-frequency task than RAG tuning or off-topic-cap edits. The
    // section blocks themselves stay where they are in the file; clicking a
    // TOC link still jumps to the right anchor.
    $toc = $tocstyle
        . '<div class="sola-toc">'
        . '<strong>' . get_string('settingspage:toc_heading', 'local_ai_course_assistant') . '</strong>'
        . '<ul>'
        . '<li><a href="#sec-general">' . get_string('settingspage:sec_general', 'local_ai_course_assistant') . '</a></li>'
        . '<li><a href="#sec-ai">' . s(get_string('settingspage:sec_ai', 'local_ai_course_assistant')) . '</a></li>'
        . '<li><a href="#sec-branding">' . s(get_string('settingspage:sec_branding', 'local_ai_course_assistant')) . '</a></li>'
        . '<li><a href="#sec-content">' . s(get_string('settingspage:sec_content', 'local_ai_course_assistant')) . '</a></li>'
        . '<li><a href="#sec-safety">' . s(get_string('settingspage:sec_safety', 'local_ai_course_assistant')) . '</a></li>'
        . '<li><a href="#sec-engagement">' . get_string('settingspage:sec_engagement', 'local_ai_course_assistant') . '</a></li>'
        . '<li><a href="#sec-integrations">' . s(get_string('settingspage:sec_integrations', 'local_ai_course_assistant')) . '</a></li>'
        . '<li><a href="#sec-save" class="sola-toc-save">&#8595; '
        . get_string('settingspage:toc_save', 'local_ai_course_assistant') . '</a></li>'
        . '</ul>'
        . '<div class="sola-quicklinks">' . $quicklinks . '</div>'
        . '</div>';

    $settings->add(new admin_setting_description(
        'local_ai_course_assistant/version_banner',
        '',
        $versionbanner . $toc
    ));

    // Helper to render a section anchor + heading.
    $sectionanchor = function (string $id, string $title): string {
        return '<span id="' . $id . '" class="sola-section-anchor"></span>'
            . '<h2 class="sola-section-heading">' . $title . '</h2>';
    };

    // ── Section: General ────────────────────────────────────────────────────
    $settings->add(new admin_setting_description(
        'local_ai_course_assistant/sec_general_anchor',
        '',
        $sectionanchor('sec-general', get_string('settingspage:sec_general', 'local_ai_course_assistant'))
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_ai_course_assistant/enabled',
        get_string('settings:enabled', 'local_ai_course_assistant'),
        get_string('settings:enabled_desc', 'local_ai_course_assistant'),
        0
    ));

    $coursemodes = [
        'per_course' => get_string('settings:default_course_mode_per_course', 'local_ai_course_assistant'),
        'all'        => get_string('settings:default_course_mode_all', 'local_ai_course_assistant'),
    ];
    $settings->add(new admin_setting_configselect(
        'local_ai_course_assistant/default_course_mode',
        get_string('settings:default_course_mode', 'local_ai_course_assistant'),
        get_string('settings:default_course_mode_desc', 'local_ai_course_assistant'),
        'per_course',
        $coursemodes
    ));

    $settings->add(new admin_setting_heading(
        'local_ai_course_assistant/quizlock_heading',
        \local_ai_course_assistant\branding::str('quizlock:heading'),
        ''
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_ai_course_assistant/quiz_lock_enabled',
        \local_ai_course_assistant\branding::str('quizlock:enabled'),
        \local_ai_course_assistant\branding::str('quizlock:enabled_desc'),
        1
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/quiz_lock_window_minutes',
        \local_ai_course_assistant\branding::str('quizlock:window'),
        \local_ai_course_assistant\branding::str('quizlock:window_desc'),
        180,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configselect(
        'local_ai_course_assistant/quiz_lock_scope',
        \local_ai_course_assistant\branding::str('quizlock:scope'),
        \local_ai_course_assistant\branding::str('quizlock:scope_desc'),
        \local_ai_course_assistant\quiz_lock::SCOPE_COURSE,
        [
            \local_ai_course_assistant\quiz_lock::SCOPE_COURSE
                => \local_ai_course_assistant\branding::str('quizlock:scope_course'),
            \local_ai_course_assistant\quiz_lock::SCOPE_SITE
                => \local_ai_course_assistant\branding::str('quizlock:scope_site'),
        ]
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_ai_course_assistant/auto_open',
        get_string('settings:auto_open', 'local_ai_course_assistant'),
        get_string('settings:auto_open_desc', 'local_ai_course_assistant'),
        0
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/hidden_categories',
        get_string('settings:hidden_categories', 'local_ai_course_assistant'),
        get_string('settings:hidden_categories_desc', 'local_ai_course_assistant'),
        '',
        // Comma-separated category IDs or names. PARAM_TEXT is lossless for
        // category names (which may contain '&', accents or punctuation) while
        // refusing markup; hook_callbacks trims and compares each entry.
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/remoteconfigurl',
        get_string('remoteconfigurl', 'local_ai_course_assistant'),
        get_string('remoteconfigurl_desc', 'local_ai_course_assistant'),
        \local_ai_course_assistant\remote_config_manager::DEFAULT_URL,
        PARAM_URL
    ));

    // ── Section: AI Provider & Models ───────────────────────────────────────
    $settings->add(new admin_setting_description(
        'local_ai_course_assistant/sec_ai_anchor',
        '',
        $sectionanchor('sec-ai', s(get_string('settingspage:sec_ai', 'local_ai_course_assistant')))
    ));

    $settings->add(new admin_setting_heading(
        'local_ai_course_assistant/provider_heading',
        get_string('settings:provider_heading', 'local_ai_course_assistant'),
        get_string('settings:provider_heading_desc', 'local_ai_course_assistant'),
    ));

    $providers = [
        'auto' => get_string('settings:provider_auto', 'local_ai_course_assistant'),
        'claude' => get_string('settings:provider_claude', 'local_ai_course_assistant'),
        'openai' => get_string('settings:provider_openai', 'local_ai_course_assistant'),
        'deepseek' => get_string('settings:provider_deepseek', 'local_ai_course_assistant'),
        'gemini' => get_string('settings:provider_gemini', 'local_ai_course_assistant'),
        'ollama' => get_string('settings:provider_ollama', 'local_ai_course_assistant'),
        'minimax' => get_string('settings:provider_minimax', 'local_ai_course_assistant'),
        'mistral' => get_string('settings:provider_mistral', 'local_ai_course_assistant'),
        'openrouter' => get_string('settings:provider_openrouter', 'local_ai_course_assistant'),
        'together' => get_string('settings:provider_together', 'local_ai_course_assistant'),
        'xai' => get_string('settings:provider_xai', 'local_ai_course_assistant'),
        'coreai' => get_string('settings:provider_coreai', 'local_ai_course_assistant'),
        'custom' => get_string('settings:provider_custom', 'local_ai_course_assistant'),
    ];
    $settings->add(new admin_setting_configselect(
        'local_ai_course_assistant/provider',
        get_string('settings:provider', 'local_ai_course_assistant'),
        // branding::str(), not get_string(): this description carries a
        // [[tutorshort]] token, and a bare get_string() would render it raw.
        \local_ai_course_assistant\branding::str('settings:provider_desc'),
        'auto',
        $providers
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_ai_course_assistant/apikey',
        get_string('settings:apikey', 'local_ai_course_assistant'),
        get_string('settings:apikey_desc', 'local_ai_course_assistant'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/model',
        get_string('settings:model', 'local_ai_course_assistant'),
        get_string('settings:model_desc_dynamic', 'local_ai_course_assistant'),
        ''
    ));

    // v6.9.5: which Anthropic models accept `temperature`. An ALLOW-list —
    // an unlisted model has temperature omitted, which is safe, rather than
    // sent and rejected with a 400. Editable here (and pushable via policy
    // bundle) so a newly released model never needs a plugin release.
    $settings->add(new admin_setting_configtextarea(
        'local_ai_course_assistant/claude_temperature_allow_prefixes',
        get_string('settings:claude_temperature_allow_prefixes', 'local_ai_course_assistant'),
        get_string('settings:claude_temperature_allow_prefixes_desc', 'local_ai_course_assistant'),
        implode("\n", \local_ai_course_assistant\provider\claude_provider::DEFAULT_TEMPERATURE_ALLOW_PREFIXES),
        // PARAM_RAW is required: this is a newline-separated list, and the
        // newlines are the record separator. It is never output as HTML - it is
        // split and matched with str_starts_with() against the configured model
        // name inside claude_provider::model_supports_temperature().
        PARAM_RAW
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/apibaseurl',
        get_string('settings:apibaseurl', 'local_ai_course_assistant'),
        get_string('settings:apibaseurl_desc', 'local_ai_course_assistant'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/temperature',
        get_string('settings:temperature', 'local_ai_course_assistant'),
        get_string('settings:temperature_desc', 'local_ai_course_assistant'),
        '0.7',
        PARAM_FLOAT
    ));

    $defaultprompt = get_string('settings:systemprompt_default', 'local_ai_course_assistant');
    $settings->add(new admin_setting_configtextarea(
        'local_ai_course_assistant/systemprompt',
        get_string('settings:systemprompt', 'local_ai_course_assistant'),
        get_string('settings:systemprompt_desc', 'local_ai_course_assistant')
        . '<br><button type="button" class="btn btn-sm btn-outline-secondary mt-1" '
        . 'onclick="document.getElementById(\'id_s_local_ai_course_assistant_systemprompt\').value='
        . 'atob(\'' . base64_encode($defaultprompt) . '\');">' . get_string('settingspage:reset_prompt_template', 'local_ai_course_assistant') . '</button>',
        ''
    ));

    // v4.11.0: prompt size + debugging controls.
    $settings->add(new admin_setting_configcheckbox(
        'local_ai_course_assistant/prompt_debug_enabled',
        get_string('settings:prompt_debug_enabled', 'local_ai_course_assistant'),
        get_string('settings:prompt_debug_enabled_desc', 'local_ai_course_assistant'),
        '0'
    ));
    $settings->add(new admin_setting_configcheckbox(
        'local_ai_course_assistant/socratic_verbose',
        get_string('settings:socratic_verbose', 'local_ai_course_assistant'),
        get_string('settings:socratic_verbose_desc', 'local_ai_course_assistant'),
        '0'
    ));

    // Both budget banners below render through $OUTPUT. Declared here rather
    // than beside either one: they are 130 lines apart and whichever came
    // second would silently get a null if this were attached to the first.
    global $OUTPUT;

    // v4.12.0: structured prompt budget + verbosity controls.
    //
    // v7.2.6: the mode select and the truncation warning now sit with the
    // budget they describe. v7.2.4 added the mode select next to the
    // cost-anomaly settings, 2,165 lines away, while its own description told
    // the reader to look at "the character budget below" -- which was above it,
    // in a different section, and unfindable without searching.
    $settings->add(new admin_setting_configselect(
        'local_ai_course_assistant/prompt_budget_mode',
        get_string('settings:prompt_budget_mode', 'local_ai_course_assistant'),
        get_string('settings:prompt_budget_mode_desc', 'local_ai_course_assistant'),
        'auto',
        [
            'auto' => get_string('settings:prompt_budget_mode_auto', 'local_ai_course_assistant'),
            'fixed' => get_string('settings:prompt_budget_mode_fixed', 'local_ai_course_assistant'),
        ]
    ));

    // v4.12.0: structured prompt budget + verbosity controls.
    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/prompt_budget_chars',
        get_string('settings:prompt_budget_chars', 'local_ai_course_assistant'),
        get_string('settings:prompt_budget_chars_desc', 'local_ai_course_assistant'),
        '36000',
        PARAM_INT
    ));

    // v7.2.7: auto mode with nothing to derive from.
    //
    // Staging ran with prompt_budget_mode=auto, model empty and
    // backend_context_tokens 0, so resolve_window_tokens() returned 0 and the
    // budget quietly fell back to the configured number -- while the setting
    // above told the administrator it was being derived from the model. The
    // only place that said otherwise was a self-test nobody has to run, and
    // 89.5% of turns were truncating with no indication why.
    if ((string) get_config('local_ai_course_assistant', 'prompt_budget_mode') !== 'fixed'
        && \local_ai_course_assistant\context_builder::resolve_window_tokens(0) <= 0
    ) {
        $settings->add(new admin_setting_description(
            'local_ai_course_assistant/prompt_budget_nowindow_warning',
            '',
            $OUTPUT->notification(
                \local_ai_course_assistant\branding::str('settings:prompt_budget_no_window'),
                \core\output\notification::NOTIFY_WARNING
            )
        ));
    }

    $trunclast = (int) get_config('local_ai_course_assistant', 'prompt_truncation_seen');
    if ($trunclast > 0 && (time() - $trunclast) < (7 * DAYSECS)) {
        $settings->add(new admin_setting_description(
            'local_ai_course_assistant/prompt_truncation_warning',
            '',
            $OUTPUT->notification(
                \local_ai_course_assistant\branding::str(
                    'settings:prompt_truncated_warning',
                    userdate($trunclast)
                ),
                \core\output\notification::NOTIFY_WARNING
            )
        ));
    }
    // v5.10.0: backend context window (max_model_len) for self-hosted/small
    // backends. 0 = hosted/unlimited (no clamping). When set, the system-prompt
    // character budget above is clamped so the prompt fits the token window.
    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/backend_context_tokens',
        get_string('settings:backend_context_tokens', 'local_ai_course_assistant'),
        \local_ai_course_assistant\branding::apply(get_string('settings:backend_context_tokens_desc', 'local_ai_course_assistant')),
        '0',
        PARAM_INT
    ));
    // v5.10.0: link to the on-demand backend self-test page.
    $settings->add(new admin_setting_description(
        'local_ai_course_assistant/selftest_link',
        get_string('selftest:link', 'local_ai_course_assistant'),
        get_string(
            'selftest:link_desc',
            'local_ai_course_assistant',
            (new moodle_url('/local/ai_course_assistant/backend_selftest.php'))->out()
        )
    ));
    // v5.10.0: link to the deployment presets page.
    $settings->add(new admin_setting_description(
        'local_ai_course_assistant/deployment_profile_link',
        get_string('profile:link', 'local_ai_course_assistant'),
        get_string(
            'profile:link_desc',
            'local_ai_course_assistant',
            (new moodle_url('/local/ai_course_assistant/deployment_profile.php'))->out()
        )
    ));
    // v5.1.0: per-section cap on the current_page_content body. Lets
    // cost-conscious admins clamp how much of the current page is
    // injected without affecting other prompt sections or disabling
    // page grounding entirely. Default keeps current behaviour (12,000 chars).
    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/current_page_content_maxchars',
        get_string('settings:current_page_content_maxchars', 'local_ai_course_assistant'),
        get_string('settings:current_page_content_maxchars_desc', 'local_ai_course_assistant'),
        '8000',
        PARAM_INT
    ));
    // v5.6.0: prompt section proportions. Admin-configurable per-section
    // weights that allocate the total budget across the four high-impact
    // buckets (safety_identity, course_structure, course_content,
    // current_page). Empirically tuned defaults from the v5.6.0 benchmark
    // are baked into context_builder::parse_section_weights(); leaving the
    // textarea blank uses those defaults. Boost mode auto-shifts allocation
    // toward the current page when one is in scope, and toward course
    // content when the learner is on the course main view.
    $settings->add(new admin_setting_heading(
        'local_ai_course_assistant/prompt_proportions_heading',
        get_string('settings:prompt_proportions_heading', 'local_ai_course_assistant'),
        get_string('settings:prompt_proportions_heading_desc', 'local_ai_course_assistant')
    ));
    $settings->add(new admin_setting_configtextarea(
        'local_ai_course_assistant/prompt_section_weights',
        get_string('settings:prompt_section_weights', 'local_ai_course_assistant'),
        get_string('settings:prompt_section_weights_desc', 'local_ai_course_assistant'),
        ''
    ));
    $settings->add(new admin_setting_configselect(
        'local_ai_course_assistant/prompt_context_boost_mode',
        get_string('settings:prompt_context_boost_mode', 'local_ai_course_assistant'),
        get_string('settings:prompt_context_boost_mode_desc', 'local_ai_course_assistant'),
        'page_focus',
        [
            'off'         => get_string('settings:prompt_context_boost_off', 'local_ai_course_assistant'),
            'page_focus'  => get_string('settings:prompt_context_boost_page_focus', 'local_ai_course_assistant'),
            'aggressive'  => get_string('settings:prompt_context_boost_aggressive', 'local_ai_course_assistant'),
        ]
    ));
    $settings->add(new admin_setting_configtextarea(
        'local_ai_course_assistant/prompt_section_weights_coach',
        get_string('settings:prompt_section_weights_coach', 'local_ai_course_assistant'),
        get_string('settings:prompt_section_weights_coach_desc', 'local_ai_course_assistant'),
        ''
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_ai_course_assistant/prompt_metrics_enabled',
        get_string('settings:prompt_metrics_enabled', 'local_ai_course_assistant'),
        get_string('settings:prompt_metrics_enabled_desc', 'local_ai_course_assistant'),
        '1'
    ));
    // v7.2.6: DEPRECATED. The tuner infers a ceiling by watching for truncation
    // and chasing the largest prompt it has seen; the v7.2.4 derived budget
    // reads the model's context window directly. The second is strictly better
    // -- it cannot oscillate, cannot chase noise, and needs no warm-up samples.
    //
    // Worse than redundant, the two collide. The tuner writes
    // prompt_budget_chars, and resolve_budget_chars() treats any value other
    // than the default as a deliberate administrator decision and stops
    // deriving. So on a site with both enabled the tuner's first write silently
    // switches the derived budget off. Found on Saylor production, where both
    // were on and neither site's budget was the default.
    //
    // The task now stands down in auto mode, so the collision cannot happen
    // even if this stays ticked. Kept for one release so nobody's configuration
    // changes under them; scheduled for removal after that.
    $settings->add(new admin_setting_configcheckbox(
        'local_ai_course_assistant/prompt_budget_auto_tune',
        get_string('settings:prompt_budget_auto_tune', 'local_ai_course_assistant'),
        \local_ai_course_assistant\branding::str('settings:prompt_budget_auto_tune_desc'),
        '0'
    ));

    // Warn only when both are actually on: a deprecation notice that fires for
    // everyone is noise, and this page already has a lot of it.
    if (
        (bool) get_config('local_ai_course_assistant', 'prompt_budget_auto_tune')
        && (string) get_config('local_ai_course_assistant', 'prompt_budget_mode') !== 'fixed'
    ) {
        $settings->add(new admin_setting_description(
            'local_ai_course_assistant/prompt_budget_tuner_conflict',
            '',
            $OUTPUT->notification(
                \local_ai_course_assistant\branding::str('settings:prompt_budget_tuner_conflict'),
                \core\output\notification::NOTIFY_WARNING
            )
        ));
    }

    $settings->add(new admin_setting_configselect(
        'local_ai_course_assistant/prompt_verbosity',
        get_string('settings:prompt_verbosity', 'local_ai_course_assistant'),
        get_string('settings:prompt_verbosity_desc', 'local_ai_course_assistant'),
        'concise',
        [
            'concise'  => get_string('settings:prompt_verbosity_concise', 'local_ai_course_assistant'),
            'standard' => get_string('settings:prompt_verbosity_standard', 'local_ai_course_assistant'),
            'verbose'  => get_string('settings:prompt_verbosity_verbose', 'local_ai_course_assistant'),
        ]
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/max_tokens',
        get_string('settings:max_tokens', 'local_ai_course_assistant'),
        get_string('settings:max_tokens_desc', 'local_ai_course_assistant'),
        '1024',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/maxhistory',
        get_string('settings:maxhistory', 'local_ai_course_assistant'),
        get_string('settings:maxhistory_desc', 'local_ai_course_assistant'),
        '20',
        PARAM_INT
    ));

    // v6.2.0: how conversation history is trimmed before it is sent to the
    // model. 'semantic' keeps only the recent turns relevant to the current
    // question (plus the latest pair); 'recency' keeps the last maxhistory
    // pairs. Semantic reduces cost/noise but does an extra embedding call.
    $settings->add(new admin_setting_configselect(
        'local_ai_course_assistant/history_mode',
        get_string('settings:history_mode', 'local_ai_course_assistant'),
        get_string('settings:history_mode_desc', 'local_ai_course_assistant'),
        'semantic',
        [
            'semantic' => get_string('settings:history_mode_semantic', 'local_ai_course_assistant'),
            'recency'  => get_string('settings:history_mode_recency', 'local_ai_course_assistant'),
        ]
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/history_semantic_minscore',
        get_string('settings:history_semantic_minscore', 'local_ai_course_assistant'),
        get_string('settings:history_semantic_minscore_desc', 'local_ai_course_assistant'),
        // Must round-trip through clean_param(PARAM_FLOAT): '0.20' cleans to
        // 0.2 and '0.2' !== '0.20' fails default-validation at install. Use '0.2'.
        '0.2',
        PARAM_FLOAT
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/history_candidates',
        get_string('settings:history_candidates', 'local_ai_course_assistant'),
        get_string('settings:history_candidates_desc', 'local_ai_course_assistant'),
        '12',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/profile_update_interval',
        get_string('settings:profile_update_interval', 'local_ai_course_assistant'),
        get_string('settings:profile_update_interval_desc', 'local_ai_course_assistant'),
        '10',
        PARAM_INT
    ));

    $settings->add(new \local_ai_course_assistant\admin_setting_comparison_providers(
        'local_ai_course_assistant/comparison_providers',
        get_string('settings:comparison_providers', 'local_ai_course_assistant'),
        get_string('settings:comparison_providers_desc', 'local_ai_course_assistant')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_ai_course_assistant/enable_thinking',
        get_string('settings:enable_thinking', 'local_ai_course_assistant'),
        get_string('settings:enable_thinking_desc', 'local_ai_course_assistant'),
        '0'
    ));

    // Quick link to the Starter Editor for convenience.
    $startersurl = new moodle_url('/local/ai_course_assistant/starter_settings.php');
    $settings->add(new admin_setting_description(
        'local_ai_course_assistant/starters_link',
        get_string('starters:admin_title', 'local_ai_course_assistant'),
        '<a href="' . $startersurl->out() . '" class="btn btn-sm btn-outline-primary">'
        . get_string('starters:admin_title', 'local_ai_course_assistant') . ' &rarr;</a>'
        . '<p class="text-muted mt-1" style="font-size:13px;">'
        . get_string('starters:admin_desc', 'local_ai_course_assistant') . '</p>'
    ));

    // Quick link to token analytics (cost dashboard).
    $tokenanalyticsurl = new moodle_url('/local/ai_course_assistant/token_analytics.php');
    $settings->add(new admin_setting_description(
        'local_ai_course_assistant/token_analytics_link',
        s(get_string('settingspage:token_analytics_title', 'local_ai_course_assistant')),
        '<a href="' . $tokenanalyticsurl->out() . '" class="btn btn-sm btn-outline-secondary">'
        . s(get_string('settingspage:token_analytics_link', 'local_ai_course_assistant')) . ' &rarr;</a>'
        . '<p class="text-muted mt-1" style="font-size:13px;">' . get_string('settingspage:token_analytics_blurb', 'local_ai_course_assistant') . '</p>'
    ));

    // Quick link to analytics dashboard.
    $analyticsurl = new moodle_url('/local/ai_course_assistant/analytics.php');
    $settings->add(new admin_setting_description(
        'local_ai_course_assistant/analytics_link',
        get_string('settingspage:analytics_title', 'local_ai_course_assistant'),
        '<a href="' . $analyticsurl->out() . '" class="btn btn-sm btn-outline-secondary">'
        . s(get_string('settingspage:analytics_link', 'local_ai_course_assistant')) . ' &rarr;</a>'
        . '<p class="text-muted mt-1" style="font-size:13px;">Cross-course usage analytics, enable/disable AI per course, student feedback, and Learning Radar.</p>'
    ));

    // v3.9.28: SSRF trusted-endpoints allowlist. Operators running a self-hosted
    // LLM (Ollama, vLLM, etc.) on the same VPC as Moodle can list those exact
    // hostnames here to bypass the loopback/private-IP and https-only checks
    // in security::is_safe_provider_url(). Default empty.
    $settings->add(new admin_setting_configtextarea(
        'local_ai_course_assistant/ssrf_trusted_endpoints',
        get_string('settings:ssrf_trusted_endpoints', 'local_ai_course_assistant'),
        \local_ai_course_assistant\branding::apply(get_string('settings:ssrf_trusted_endpoints_desc', 'local_ai_course_assistant')),
        '',
        // PARAM_RAW is required: a newline-separated allowlist of host[:port]
        // entries, including private/loopback hosts and Docker service names
        // (underscores) that PARAM_URL/PARAM_HOST would reject outright. Strict
        // checking happens where it matters - security::is_safe_provider_url()
        // parses each entry and compares it to the resolved target host.
        PARAM_RAW
    ));

    // v3.9.13: xAI Realtime WebSocket proxy settings. When configured,
    // xAI voice routes through services/xai_rt_proxy instead of opening a
    // direct browser connection to api.x.ai with the master key.
    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/xai_proxy_url',
        get_string('settings:xai_proxy_url', 'local_ai_course_assistant'),
        \local_ai_course_assistant\branding::apply(get_string('settings:xai_proxy_url_desc', 'local_ai_course_assistant')),
        '',
        PARAM_URL
    ));
    $settings->add(new admin_setting_configpasswordunmask(
        'local_ai_course_assistant/xai_proxy_jwt_secret',
        get_string('settings:xai_proxy_jwt_secret', 'local_ai_course_assistant'),
        get_string('settings:xai_proxy_jwt_secret_desc', 'local_ai_course_assistant'),
        ''
    ));

    // ── Section: Content & RAG ──────────────────────────────────────────────
    $settings->add(new admin_setting_description(
        'local_ai_course_assistant/sec_content_anchor',
        '',
        $sectionanchor('sec-content', s(get_string('settingspage:sec_content', 'local_ai_course_assistant')))
    ));

    $settings->add(new admin_setting_heading(
        'local_ai_course_assistant/rag_heading',
        get_string('settings:rag_heading', 'local_ai_course_assistant'),
        get_string('settings:rag_heading_desc', 'local_ai_course_assistant')
        . '<br><small class="text-muted">' . get_string('settingspage:rag_explainer', 'local_ai_course_assistant') . '</small>'
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_ai_course_assistant/rag_enabled',
        get_string('settings:rag_enabled', 'local_ai_course_assistant'),
        get_string('settings:rag_enabled_desc', 'local_ai_course_assistant'),
        1
    ));

    // v4.8.0: auto-reindex of drifted modules. Closes the gap where
    // `index_course_content` skips courses without active enrolments,
    // leaving content edits invisible to RAG until students show up.
    $settings->add(new admin_setting_configcheckbox(
        'local_ai_course_assistant/rag_auto_reindex_drifted',
        get_string('settings:rag_auto_reindex_drifted', 'local_ai_course_assistant'),
        get_string('settings:rag_auto_reindex_drifted_desc', 'local_ai_course_assistant'),
        1
    ));

    // v4.2.3: external resources (opt-in). When on, SOLA may suggest links to
    // reputable open educational resources (Wikipedia, Khan Academy, OER
    // Commons, OpenStax, MIT OpenCourseWare) alongside its course-grounded
    // answer. Default OFF for legal/quality control. Per-course override
    // available on course_settings.php.
    $settings->add(new admin_setting_heading(
        'local_ai_course_assistant/external_resources_heading',
        get_string('settings:external_resources_heading', 'local_ai_course_assistant'),
        \local_ai_course_assistant\branding::apply(get_string('settings:external_resources_heading_desc', 'local_ai_course_assistant'))
    ));
    $settings->add(new admin_setting_configcheckbox(
        'local_ai_course_assistant/external_resources_enabled',
        get_string('settings:external_resources_enabled', 'local_ai_course_assistant'),
        \local_ai_course_assistant\branding::apply(get_string('settings:external_resources_enabled_desc', 'local_ai_course_assistant')),
        0
    ));
    $settings->add(new admin_setting_configtextarea(
        'local_ai_course_assistant/external_resources_allowlist',
        get_string('settings:external_resources_allowlist', 'local_ai_course_assistant'),
        \local_ai_course_assistant\branding::apply(get_string('settings:external_resources_allowlist_desc', 'local_ai_course_assistant')),
        "Wikipedia (en.wikipedia.org)\n"
        . "Khan Academy (khanacademy.org)\n"
        . "OER Commons (oercommons.org)\n"
        . "OpenStax (openstax.org)\n"
        . "MIT OpenCourseWare (ocw.mit.edu)\n"
        . "Saylor Academy (learn.saylor.org)"
    ));

    $embeddingproviders = [
        'openai' => get_string('settings:embed_provider_openai', 'local_ai_course_assistant'),
        'voyage' => get_string('settings:embed_provider_voyage', 'local_ai_course_assistant'),
        'ollama' => get_string('settings:embed_provider_ollama', 'local_ai_course_assistant'),
    ];
    $settings->add(new admin_setting_configselect(
        'local_ai_course_assistant/embed_provider',
        get_string('settings:embed_provider', 'local_ai_course_assistant'),
        get_string('settings:embed_provider_desc', 'local_ai_course_assistant'),
        'openai',
        $embeddingproviders
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_ai_course_assistant/embed_apikey',
        get_string('settings:embed_apikey', 'local_ai_course_assistant'),
        get_string('settings:embed_apikey_desc', 'local_ai_course_assistant'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/embed_model',
        get_string('settings:embed_model', 'local_ai_course_assistant'),
        get_string('settings:embed_model_desc', 'local_ai_course_assistant'),
        'text-embedding-3-small'
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/embed_apibaseurl',
        get_string('settings:embed_apibaseurl', 'local_ai_course_assistant'),
        get_string('settings:embed_apibaseurl_desc', 'local_ai_course_assistant'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/embed_dimensions',
        get_string('settings:embed_dimensions', 'local_ai_course_assistant'),
        get_string('settings:embed_dimensions_desc', 'local_ai_course_assistant'),
        '1536',
        PARAM_INT
    ));

    // v7.0.3: separate query-side model. Two vectors are only comparable within
    // one embedding space, so this is empty by default (meaning "same model as
    // documents") and is only useful for a model family that guarantees a shared
    // space — currently Voyage's 4 series. rag_retriever refuses to score a
    // query against chunks it cannot be compared with, so a wrong value here
    // degrades to a logged warning and no results rather than to nonsense
    // rankings.
    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/embed_query_model',
        get_string('settings:embed_query_model', 'local_ai_course_assistant'),
        get_string('settings:embed_query_model_desc', 'local_ai_course_assistant'),
        '',
        PARAM_TEXT
    ));

    // v7.0.3: stored-vector quantization. int8 is a quarter of float32 and
    // binary an eighth of int8, at some cost in recall. Changing this requires a
    // full reindex — the encodings are not interchangeable, and the retriever
    // will skip (and warn about) rows that disagree with this setting.
    $settings->add(new admin_setting_configselect(
        'local_ai_course_assistant/embed_dtype',
        get_string('settings:embed_dtype', 'local_ai_course_assistant'),
        get_string('settings:embed_dtype_desc', 'local_ai_course_assistant'),
        \local_ai_course_assistant\embedding_compat::DTYPE_FLOAT,
        [
            \local_ai_course_assistant\embedding_compat::DTYPE_FLOAT  =>
                get_string('settings:embed_dtype_float', 'local_ai_course_assistant'),
            \local_ai_course_assistant\embedding_compat::DTYPE_INT8   =>
                get_string('settings:embed_dtype_int8', 'local_ai_course_assistant'),
            \local_ai_course_assistant\embedding_compat::DTYPE_BINARY =>
                get_string('settings:embed_dtype_binary', 'local_ai_course_assistant'),
        ]
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/rag_topk',
        get_string('settings:rag_topk', 'local_ai_course_assistant'),
        get_string('settings:rag_topk_desc', 'local_ai_course_assistant'),
        '3',
        PARAM_INT
    ));

    // v6.2.0: relevance gate — drop chunks below this cosine similarity so an
    // off-topic/sparse query injects fewer (or zero) passages instead of always
    // padding to top-k. Model-dependent; default suits text-embedding-3-small.
    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/rag_min_similarity',
        get_string('settings:rag_min_similarity', 'local_ai_course_assistant'),
        get_string('settings:rag_min_similarity_desc', 'local_ai_course_assistant'),
        '0.25',
        PARAM_FLOAT
    ));

    // v6.2.0: small ordering boost for chunks from the page the learner is on,
    // so "explain this" grounds on the visible page among near-ties.
    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/rag_currentpage_boost',
        get_string('settings:rag_currentpage_boost', 'local_ai_course_assistant'),
        get_string('settings:rag_currentpage_boost_desc', 'local_ai_course_assistant'),
        '0.05',
        PARAM_FLOAT
    ));

    // v6.8.7: retrieval scope when the learner is viewing a specific document.
    $settings->add(new admin_setting_configselect(
        'local_ai_course_assistant/rag_scope',
        get_string('settings:rag_scope', 'local_ai_course_assistant'),
        get_string('settings:rag_scope_desc', 'local_ai_course_assistant'),
        'document_first',
        [
            'document_first' => get_string('settings:rag_scope_document_first', 'local_ai_course_assistant'),
            'document_only'  => get_string('settings:rag_scope_document_only', 'local_ai_course_assistant'),
            'course'         => get_string('settings:rag_scope_course', 'local_ai_course_assistant'),
        ]
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/rag_chunksize',
        get_string('settings:rag_chunksize', 'local_ai_course_assistant'),
        get_string('settings:rag_chunksize_desc', 'local_ai_course_assistant'),
        '400',
        PARAM_INT
    ));

    // Parent-document retrieval: what to inject for a retrieved hit (single
    // chunk, a window of neighbouring chunks, or the whole page).
    $settings->add(new admin_setting_configselect(
        'local_ai_course_assistant/rag_return_scope',
        get_string('settings:rag_return_scope', 'local_ai_course_assistant'),
        get_string('settings:rag_return_scope_desc', 'local_ai_course_assistant'),
        'chunk',
        ['chunk' => 'chunk', 'window' => 'window', 'page' => 'page']
    ));
    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/rag_window_size',
        get_string('settings:rag_window_size', 'local_ai_course_assistant'),
        get_string('settings:rag_window_size_desc', 'local_ai_course_assistant'),
        '1',
        PARAM_INT
    ));
    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/rag_parent_max_chars',
        get_string('settings:rag_parent_max_chars', 'local_ai_course_assistant'),
        get_string('settings:rag_parent_max_chars_desc', 'local_ai_course_assistant'),
        '6000',
        PARAM_INT
    ));

    // v5.11.0: two-stage retrieval with Voyage rerank-2.5.
    // v6.1.0: own heading — these five settings were orphaned after
    // rag_chunksize with no visual group boundary.
    $settings->add(new admin_setting_heading(
        'local_ai_course_assistant/rerank_heading',
        get_string('settings:rerank_heading', 'local_ai_course_assistant'),
        get_string('settings:rerank_heading_desc', 'local_ai_course_assistant')
    ));
    $settings->add(new admin_setting_configcheckbox(
        'local_ai_course_assistant/rerank_enabled',
        get_string('settings:rerank_enabled', 'local_ai_course_assistant'),
        get_string('settings:rerank_enabled_desc', 'local_ai_course_assistant'),
        0
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_ai_course_assistant/rerank_apikey',
        get_string('settings:rerank_apikey', 'local_ai_course_assistant'),
        get_string('settings:rerank_apikey_desc', 'local_ai_course_assistant'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/rerank_model',
        get_string('settings:rerank_model', 'local_ai_course_assistant'),
        get_string('settings:rerank_model_desc', 'local_ai_course_assistant'),
        'rerank-2.5'
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/rerank_apibaseurl',
        get_string('settings:rerank_apibaseurl', 'local_ai_course_assistant'),
        get_string('settings:rerank_apibaseurl_desc', 'local_ai_course_assistant'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/rerank_candidates',
        get_string('settings:rerank_candidates', 'local_ai_course_assistant'),
        get_string('settings:rerank_candidates_desc', 'local_ai_course_assistant'),
        '20',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/rerank_margin_threshold',
        get_string('settings:rerank_margin_threshold', 'local_ai_course_assistant'),
        get_string('settings:rerank_margin_threshold_desc', 'local_ai_course_assistant'),
        '0.086',
        PARAM_FLOAT
    ));

    $ragadminurl = new moodle_url('/local/ai_course_assistant/rag_admin.php');
    $settings->add(new admin_setting_description(
        'local_ai_course_assistant/rag_admin_link',
        get_string('ragadmin:title', 'local_ai_course_assistant'),
        html_writer::link(
            $ragadminurl,
            get_string('ragadmin:view_status', 'local_ai_course_assistant'),
            ['class' => 'btn btn-secondary btn-sm']
        )
    ));

    // Content source extractors (v3.9.6+). Each flag gates a specific module
    // type or embed fetcher. Read from within the extractor classes.
    $settings->add(new admin_setting_heading(
        'local_ai_course_assistant/rag_sources_heading',
        get_string('settings:rag_sources_heading', 'local_ai_course_assistant'),
        get_string('settings:rag_sources_heading_desc', 'local_ai_course_assistant', $ragadminurl->out()),
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_ai_course_assistant/rag_extract_pdf',
        get_string('settings:rag_extract_pdf', 'local_ai_course_assistant'),
        get_string('settings:rag_extract_pdf_desc', 'local_ai_course_assistant'),
        1
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/rag_pdftotext_path',
        get_string('settings:rag_pdftotext_path', 'local_ai_course_assistant'),
        get_string('settings:rag_pdftotext_path_desc', 'local_ai_course_assistant'),
        ''
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_ai_course_assistant/rag_extract_docx',
        get_string('settings:rag_extract_docx', 'local_ai_course_assistant'),
        get_string('settings:rag_extract_docx_desc', 'local_ai_course_assistant'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_ai_course_assistant/rag_extract_pptx',
        get_string('settings:rag_extract_pptx', 'local_ai_course_assistant'),
        get_string('settings:rag_extract_pptx_desc', 'local_ai_course_assistant'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_ai_course_assistant/rag_extract_h5p',
        get_string('settings:rag_extract_h5p', 'local_ai_course_assistant'),
        get_string('settings:rag_extract_h5p_desc', 'local_ai_course_assistant'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_ai_course_assistant/rag_extract_scorm',
        get_string('settings:rag_extract_scorm', 'local_ai_course_assistant'),
        get_string('settings:rag_extract_scorm_desc', 'local_ai_course_assistant'),
        0
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/rag_scorm_max_mb',
        get_string('settings:rag_scorm_max_mb', 'local_ai_course_assistant'),
        get_string('settings:rag_scorm_max_mb_desc', 'local_ai_course_assistant'),
        '100',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_ai_course_assistant/rag_fetch_transcripts',
        get_string('settings:rag_fetch_transcripts', 'local_ai_course_assistant'),
        get_string('settings:rag_fetch_transcripts_desc', 'local_ai_course_assistant'),
        0
    ));

    $settings->add(new admin_setting_configtextarea(
        'local_ai_course_assistant/rag_iframe_host_patterns',
        get_string('settings:rag_iframe_host_patterns', 'local_ai_course_assistant'),
        get_string('settings:rag_iframe_host_patterns_desc', 'local_ai_course_assistant'),
        "share\\.synthesia\\.io/embeds/videos/\n"
        . "youtube\\.com/embed/\n"
        . "youtube-nocookie\\.com/embed/\n"
        . "review\\.articulate\\.com/\n"
        . "articulateusercontent\\.com/\n"
        . "360\\.articulate\\.com/\n"
        . "view\\.genially\\.com/\n"
        . "genial\\.ly/"
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/rag_transcript_url_pattern',
        get_string('settings:rag_transcript_url_pattern', 'local_ai_course_assistant'),
        get_string('settings:rag_transcript_url_pattern_desc', 'local_ai_course_assistant'),
        ""
    ));

    // Performance: caps on how much course content goes into the system prompt.
    $settings->add(new admin_setting_heading(
        'local_ai_course_assistant/performance_heading',
        get_string('settings:performance_heading', 'local_ai_course_assistant'),
        get_string('settings:performance_heading_desc', 'local_ai_course_assistant'),
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/max_content_per_resource',
        get_string('settings:max_content_per_resource', 'local_ai_course_assistant'),
        get_string('settings:max_content_per_resource_desc', 'local_ai_course_assistant'),
        '1500',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/max_total_content',
        get_string('settings:max_total_content', 'local_ai_course_assistant'),
        get_string('settings:max_total_content_desc', 'local_ai_course_assistant'),
        '15000',
        PARAM_INT
    ));

    // Spend guard + optimizer (v3.9.9+).
    $settings->add(new admin_setting_heading(
        'local_ai_course_assistant/spend_guard_heading',
        \local_ai_course_assistant\branding::str('settings:spend_guard_heading'),
        \local_ai_course_assistant\branding::str('settings:spend_guard_heading_desc', (new moodle_url('/local/ai_course_assistant/token_analytics.php'))->out()),
    ));

    $settings->add(new admin_setting_configselect(
        'local_ai_course_assistant/spend_cap_period',
        get_string('settings:spend_cap_period', 'local_ai_course_assistant'),
        get_string('settings:spend_cap_period_desc', 'local_ai_course_assistant'),
        'monthly',
        ['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly']
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/spend_cap_site',
        get_string('settings:spend_cap_site', 'local_ai_course_assistant'),
        get_string('settings:spend_cap_site_desc', 'local_ai_course_assistant'),
        '0',
        PARAM_FLOAT
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/spend_cap_chat',
        get_string('settings:spend_cap_chat', 'local_ai_course_assistant'),
        get_string('settings:spend_cap_chat_desc', 'local_ai_course_assistant'),
        '0',
        PARAM_FLOAT
    ));
    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/spend_cap_voice',
        get_string('settings:spend_cap_voice', 'local_ai_course_assistant'),
        get_string('settings:spend_cap_voice_desc', 'local_ai_course_assistant'),
        '0',
        PARAM_FLOAT
    ));
    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/spend_cap_rag',
        get_string('settings:spend_cap_rag', 'local_ai_course_assistant'),
        get_string('settings:spend_cap_rag_desc', 'local_ai_course_assistant'),
        '0',
        PARAM_FLOAT
    ));
    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/spend_cap_analytics',
        get_string('settings:spend_cap_analytics', 'local_ai_course_assistant'),
        get_string('settings:spend_cap_analytics_desc', 'local_ai_course_assistant'),
        '0',
        PARAM_FLOAT
    ));

    // v5.13.0: default per-course cap (applies to any course without an explicit override).
    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/spend_cap_per_course_default',
        get_string('settings:spend_cap_per_course_default', 'local_ai_course_assistant'),
        get_string('settings:spend_cap_per_course_default_desc', 'local_ai_course_assistant'),
        '0',
        PARAM_FLOAT
    ));

    // v6.0.0: daily cost anomaly detector (in-SOLA equivalent to the Redash query).
    $settings->add(new admin_setting_heading(
        'local_ai_course_assistant/cost_anomaly_heading',
        get_string('settings:cost_anomaly_heading', 'local_ai_course_assistant'),
        \local_ai_course_assistant\branding::apply(get_string('settings:cost_anomaly_heading_desc', 'local_ai_course_assistant'))
    ));
    $settings->add(new admin_setting_configcheckbox(
        'local_ai_course_assistant/cost_anomaly_enabled',
        get_string('settings:cost_anomaly_enabled', 'local_ai_course_assistant'),
        get_string('settings:cost_anomaly_enabled_desc', 'local_ai_course_assistant'),
        0
    ));
    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/cost_anomaly_multiplier',
        get_string('settings:cost_anomaly_multiplier', 'local_ai_course_assistant'),
        \local_ai_course_assistant\branding::apply(get_string('settings:cost_anomaly_multiplier_desc', 'local_ai_course_assistant')),
        // Must round-trip through clean_param(PARAM_FLOAT): '2.0' cleans to 2
        // and '2' !== '2.0' fails default-validation at install (pre-existing
        // bug that has been failing CI since v6.0.1). Use '2'.
        '2',
        PARAM_FLOAT
    ));

    // v6.9.7: unanswered-question monitor. The cost detector above only fires
    // when spend goes UP, so a provider that rejects every call costs nothing
    // and stays invisible — that gap hid a nine-day outage across ten courses
    // in August 2026. This watches the ratio of learner questions to assistant
    // replies instead, which drops to zero the moment a provider breaks.
    $settings->add(new admin_setting_configcheckbox(
        'local_ai_course_assistant/unanswered_check_enabled',
        get_string('settings:unanswered_check_enabled', 'local_ai_course_assistant'),
        \local_ai_course_assistant\branding::apply(
            get_string('settings:unanswered_check_enabled_desc', 'local_ai_course_assistant')),
        0
    ));
    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/unanswered_window_hours',
        get_string('settings:unanswered_window_hours', 'local_ai_course_assistant'),
        get_string('settings:unanswered_window_hours_desc', 'local_ai_course_assistant'),
        '6',
        PARAM_INT
    ));
    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/unanswered_min_questions',
        get_string('settings:unanswered_min_questions', 'local_ai_course_assistant'),
        get_string('settings:unanswered_min_questions_desc', 'local_ai_course_assistant'),
        '5',
        PARAM_INT
    ));
    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/unanswered_min_answer_rate',
        get_string('settings:unanswered_min_answer_rate', 'local_ai_course_assistant'),
        get_string('settings:unanswered_min_answer_rate_desc', 'local_ai_course_assistant'),
        // As with cost_anomaly_multiplier, the default must round-trip through
        // clean_param(PARAM_FLOAT) unchanged or install-time validation fails:
        // '0.5' cleans to 0.5 and compares equal, so this one is safe as-is.
        '0.5',
        PARAM_FLOAT
    ));

    // v6.4.0: signed policy bundle — behavior-as-data updates without a code
    // deploy. Daily task fetches a JSON envelope, verifies the Ed25519
    // signature, enforces the settings allowlist + monotonic version, applies
    // with an audit row. Authoring tooling: admin/cli/policy_bundle_tool.php.
    $policystatus = '';
    $lastresult = get_config('local_ai_course_assistant', 'policy_bundle_last_result');
    if ($lastresult !== false && $lastresult !== '') {
        $lastsync = (int) get_config('local_ai_course_assistant', 'policy_bundle_last_sync');
        $appliedver = (int) get_config('local_ai_course_assistant', 'policy_bundle_applied_version');
        $policystatus = '<br><strong>' . get_string('settings:policy_bundle_status', 'local_ai_course_assistant')
            . ':</strong> ' . s($lastresult)
            . ' (' . ($lastsync ? userdate($lastsync) : '-') . ', '
            . get_string('settings:policy_bundle_applied_version', 'local_ai_course_assistant')
            . ' ' . $appliedver . ')';
    }
    $settings->add(new admin_setting_heading(
        'local_ai_course_assistant/policy_bundle_heading',
        get_string('settings:policy_bundle_heading', 'local_ai_course_assistant'),
        get_string('settings:policy_bundle_heading_desc', 'local_ai_course_assistant') . $policystatus
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_ai_course_assistant/policy_bundle_enabled',
        get_string('settings:policy_bundle_enabled', 'local_ai_course_assistant'),
        get_string('settings:policy_bundle_enabled_desc', 'local_ai_course_assistant'),
        0
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/policy_bundle_url',
        get_string('settings:policy_bundle_url', 'local_ai_course_assistant'),
        get_string('settings:policy_bundle_url_desc', 'local_ai_course_assistant'),
        '',
        // A single fetchable URL, so PARAM_URL is the right type (it accepts the
        // https, localhost and private-IP forms this setting is used with, and
        // rejects javascript:/data: outright). policy_bundle::sync() still runs
        // the value through security::is_safe_provider_url() before fetching.
        PARAM_URL
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/policy_bundle_pubkey',
        get_string('settings:policy_bundle_pubkey', 'local_ai_course_assistant'),
        get_string('settings:policy_bundle_pubkey_desc', 'local_ai_course_assistant'),
        '',
        // Base64-encoded 32-byte Ed25519 public key - PARAM_BASE64 is exactly
        // that character class (verified against a generated key), and
        // policy_bundle::verify_envelope() still length-checks the decoded key.
        PARAM_BASE64
    ));

    $settings->add(new admin_setting_configtextarea(
        'local_ai_course_assistant/spend_failover_chain',
        \local_ai_course_assistant\branding::str('settings:spend_failover_chain'),
        \local_ai_course_assistant\branding::str('settings:spend_failover_chain_desc'),
        ''
    ));

    // v5.5.0: per-call failover. Off by default. When enabled, every chat
    // call wraps the primary provider in a failover_chain decorator that
    // tries each entry above on per-call timeout / 5xx, with a 15-minute
    // circuit on the failing label. Stays off-by-default so existing
    // installs see no behavior change on upgrade.
    $settings->add(new admin_setting_configcheckbox(
        'local_ai_course_assistant/failover_per_call_enabled',
        \local_ai_course_assistant\branding::str('settings:failover_per_call_enabled'),
        \local_ai_course_assistant\branding::str('settings:failover_per_call_enabled_desc'),
        0
    ));
    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/failover_timeout_chat',
        get_string('settings:failover_timeout_chat', 'local_ai_course_assistant'),
        get_string('settings:failover_timeout_chat_desc', 'local_ai_course_assistant'),
        '8',
        PARAM_INT
    ));
    // failover_timeout_voice was removed in v7.0.0. It was registered here and
    // on the policy-bundle allowlist, but nothing ever read it — voice failover
    // was never wired through, as its own help text admitted. A remotely
    // settable knob that does nothing reads as a control that works.

    // v5.10.0: bounded retry on a transient backend rejection (429/503). Aimed
    // at small self-hosted backends that reject under load. Retries only happen
    // before any response text has streamed, so output is never duplicated.
    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/backend_retry_attempts',
        get_string('settings:backend_retry_attempts', 'local_ai_course_assistant'),
        get_string('settings:backend_retry_attempts_desc', 'local_ai_course_assistant'),
        '2',
        PARAM_INT
    ));
    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/backend_retry_max_wait',
        get_string('settings:backend_retry_max_wait', 'local_ai_course_assistant'),
        \local_ai_course_assistant\branding::apply(get_string('settings:backend_retry_max_wait_desc', 'local_ai_course_assistant')),
        '5',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/spend_notify_emails',
        get_string('settings:spend_notify_emails', 'local_ai_course_assistant'),
        get_string('settings:spend_notify_emails_desc', 'local_ai_course_assistant'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/opt_cost_weight',
        get_string('settings:opt_cost_weight', 'local_ai_course_assistant'),
        get_string('settings:opt_cost_weight_desc', 'local_ai_course_assistant'),
        '0.7',
        PARAM_FLOAT
    ));
    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/opt_quality_weight',
        get_string('settings:opt_quality_weight', 'local_ai_course_assistant'),
        get_string('settings:opt_quality_weight_desc', 'local_ai_course_assistant'),
        '0.3',
        PARAM_FLOAT
    ));

    // ── Section: Safety & Moderation ────────────────────────────────────────
    $settings->add(new admin_setting_description(
        'local_ai_course_assistant/sec_safety_anchor',
        '',
        $sectionanchor('sec-safety', s(get_string('settingspage:sec_safety', 'local_ai_course_assistant')))
    ));

    $settings->add(new admin_setting_heading(
        'local_ai_course_assistant/quiz_hide_heading',
        get_string('settings:quiz_hide_heading', 'local_ai_course_assistant'),
        get_string('settings:quiz_hide_heading_desc', 'local_ai_course_assistant')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_ai_course_assistant/hide_on_quiz_for_students',
        get_string('settings:hide_on_quiz_for_students', 'local_ai_course_assistant'),
        get_string('settings:hide_on_quiz_for_students_desc', 'local_ai_course_assistant'),
        0
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_ai_course_assistant/hide_on_quiz_for_staff',
        get_string('settings:hide_on_quiz_for_staff', 'local_ai_course_assistant'),
        get_string('settings:hide_on_quiz_for_staff_desc', 'local_ai_course_assistant'),
        0
    ));

    $settings->add(new admin_setting_heading(
        'local_ai_course_assistant/offtopic_heading',
        get_string('settings:offtopic_heading', 'local_ai_course_assistant'),
        get_string('settings:offtopic_heading_desc', 'local_ai_course_assistant')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_ai_course_assistant/offtopic_enabled',
        get_string('settings:offtopic_enabled', 'local_ai_course_assistant'),
        get_string('settings:offtopic_enabled_desc', 'local_ai_course_assistant'),
        1
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/offtopic_max',
        get_string('settings:offtopic_max', 'local_ai_course_assistant'),
        get_string('settings:offtopic_max_desc', 'local_ai_course_assistant'),
        '3',
        PARAM_INT
    ));

    $offtopicactions = [
        'warn' => get_string('settings:offtopic_action_warn', 'local_ai_course_assistant'),
        'end' => get_string('settings:offtopic_action_end', 'local_ai_course_assistant'),
    ];
    $settings->add(new admin_setting_configselect(
        'local_ai_course_assistant/offtopic_action',
        get_string('settings:offtopic_action', 'local_ai_course_assistant'),
        get_string('settings:offtopic_action_desc', 'local_ai_course_assistant'),
        'warn',
        $offtopicactions
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/offtopic_lockout_duration',
        get_string('settings:offtopic_lockout_duration', 'local_ai_course_assistant'),
        get_string('settings:offtopic_lockout_duration_desc', 'local_ai_course_assistant'),
        '30',
        PARAM_INT
    ));

    $settings->add(new admin_setting_heading(
        'local_ai_course_assistant/wellbeing_heading',
        get_string('settings:wellbeing_heading', 'local_ai_course_assistant'),
        get_string('settings:wellbeing_heading_desc', 'local_ai_course_assistant')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_ai_course_assistant/wellbeing_enabled',
        get_string('settings:wellbeing_enabled', 'local_ai_course_assistant'),
        get_string('settings:wellbeing_enabled_desc', 'local_ai_course_assistant'),
        1
    ));

    $settings->add(new admin_setting_heading(
        'local_ai_course_assistant/integrity_heading',
        get_string('integrity:title', 'local_ai_course_assistant'),
        get_string('integrity:desc', 'local_ai_course_assistant')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_ai_course_assistant/integrity_enabled',
        get_string('integrity:enabled', 'local_ai_course_assistant'),
        get_string('integrity:enabled_desc', 'local_ai_course_assistant'),
        1
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/integrity_email',
        get_string('integrity:email', 'local_ai_course_assistant'),
        get_string('integrity:email_desc', 'local_ai_course_assistant'),
        '',
        PARAM_TEXT
    ));

    $integrityurl = new moodle_url('/local/ai_course_assistant/integrity_admin.php');
    $settings->add(new admin_setting_description(
        'local_ai_course_assistant/integrity_link',
        '',
        '<a href="' . $integrityurl->out() . '" class="btn btn-sm btn-outline-secondary">'
        . get_string('integrity:view_results', 'local_ai_course_assistant') . ' &rarr;</a>'
    ));

    // v3.9.12: data retention controls.
    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/audit_retention_days',
        get_string('settings:audit_retention_days', 'local_ai_course_assistant'),
        get_string('settings:audit_retention_days_desc', 'local_ai_course_assistant'),
        '365',
        PARAM_INT
    ));
    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/conversation_retention_days',
        get_string('settings:conversation_retention_days', 'local_ai_course_assistant'),
        get_string('settings:conversation_retention_days_desc', 'local_ai_course_assistant'),
        '730',
        PARAM_INT
    ));

    // v4.8.0: runtime validator pipeline. Default off so the upgrade is a
    // no-op for existing installs. 'annotate' appends a small visible
    // warning line to flagged responses; 'block' replaces them with a
    // safe fallback message. Both modes audit-log every fail so ops can
    // measure how often each validator trips before tightening to
    // 'block'.
    $settings->add(new admin_setting_configselect(
        'local_ai_course_assistant/validators_runtime_mode',
        get_string('settings:validators_runtime_mode', 'local_ai_course_assistant'),
        get_string('settings:validators_runtime_mode_desc', 'local_ai_course_assistant'),
        'off',
        [
            'off'      => get_string('settings:validators_runtime_off', 'local_ai_course_assistant'),
            'annotate' => get_string('settings:validators_runtime_annotate', 'local_ai_course_assistant'),
            'block'    => get_string('settings:validators_runtime_block', 'local_ai_course_assistant'),
        ]
    ));

    // v4.4.0: Optional Content-Security-Policy header on course pages where
    // the SOLA widget is active. Default off — admin opts in. Defense-in-
    // depth against arbitrary scripts pasted into Site administration →
    // Appearance → Additional HTML (the IBL AI / Raison incident).
    $settings->add(new admin_setting_configselect(
        'local_ai_course_assistant/csp_course_pages_mode',
        get_string('settings:csp_course_pages_mode', 'local_ai_course_assistant'),
        \local_ai_course_assistant\branding::apply(get_string('settings:csp_course_pages_mode_desc', 'local_ai_course_assistant')),
        'off',
        [
            'off'         => get_string('settings:csp_mode_off', 'local_ai_course_assistant'),
            'report-only' => get_string('settings:csp_mode_report_only', 'local_ai_course_assistant'),
            'enforce'     => get_string('settings:csp_mode_enforce', 'local_ai_course_assistant'),
        ]
    ));

    // ── Section: Engagement ─────────────────────────────────────────────────
    $settings->add(new admin_setting_description(
        'local_ai_course_assistant/sec_engagement_anchor',
        '',
        $sectionanchor('sec-engagement', get_string('settingspage:sec_engagement', 'local_ai_course_assistant'))
    ));

    // Study plans and reminders.
    $settings->add(new admin_setting_heading(
        'local_ai_course_assistant/studyplan_heading',
        get_string('settings:studyplan_heading', 'local_ai_course_assistant'),
        get_string('settings:studyplan_heading_desc', 'local_ai_course_assistant')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_ai_course_assistant/studyplan_enabled',
        get_string('settings:studyplan_enabled', 'local_ai_course_assistant'),
        get_string('settings:studyplan_enabled_desc', 'local_ai_course_assistant'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_ai_course_assistant/reminders_email_enabled',
        get_string('settings:reminders_email_enabled', 'local_ai_course_assistant'),
        get_string('settings:reminders_email_enabled_desc', 'local_ai_course_assistant'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_ai_course_assistant/reminders_whatsapp_enabled',
        get_string('settings:reminders_whatsapp_enabled', 'local_ai_course_assistant'),
        get_string('settings:reminders_whatsapp_enabled_desc', 'local_ai_course_assistant'),
        0
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/whatsapp_api_url',
        get_string('settings:whatsapp_api_url', 'local_ai_course_assistant'),
        get_string('settings:whatsapp_api_url_desc', 'local_ai_course_assistant'),
        ''
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_ai_course_assistant/whatsapp_api_token',
        get_string('settings:whatsapp_api_token', 'local_ai_course_assistant'),
        get_string('settings:whatsapp_api_token_desc', 'local_ai_course_assistant'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/whatsapp_from_number',
        get_string('settings:whatsapp_from_number', 'local_ai_course_assistant'),
        get_string('settings:whatsapp_from_number_desc', 'local_ai_course_assistant'),
        ''
    ));

    $settings->add(new admin_setting_configtextarea(
        'local_ai_course_assistant/whatsapp_blocked_countries',
        get_string('settings:whatsapp_blocked_countries', 'local_ai_course_assistant'),
        get_string('settings:whatsapp_blocked_countries_desc', 'local_ai_course_assistant'),
        ''
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_ai_course_assistant/inactivity_reminder_enabled',
        get_string('settings:inactivity_reminder_enabled', 'local_ai_course_assistant'),
        get_string('settings:inactivity_reminder_enabled_desc', 'local_ai_course_assistant'),
        1
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/inactivity_threshold_days',
        get_string('settings:inactivity_threshold_days', 'local_ai_course_assistant'),
        get_string('settings:inactivity_threshold_days_desc', 'local_ai_course_assistant'),
        '7',
        PARAM_INT
    ));

    // Voice Mode (Realtime).
    $settings->add(new admin_setting_heading(
        'local_ai_course_assistant/realtime_heading',
        get_string('settings:realtime_heading', 'local_ai_course_assistant'),
        '<small class="text-muted">' . get_string('settingspage:realtime_explainer', 'local_ai_course_assistant') . '</small>'
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_ai_course_assistant/realtime_enabled',
        get_string('settings:realtime_enabled', 'local_ai_course_assistant'),
        get_string('settings:realtime_enabled_desc', 'local_ai_course_assistant'),
        0
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_ai_course_assistant/realtime_apikey',
        get_string('settings:realtime_apikey', 'local_ai_course_assistant'),
        get_string('settings:realtime_apikey_desc', 'local_ai_course_assistant'),
        ''
    ));

    $realtimevoices = [
        'shimmer' => 'Shimmer (OpenAI)',
        'alloy'   => 'Alloy (OpenAI)',
        'echo'    => 'Echo (OpenAI)',
        'fable'   => 'Fable (OpenAI)',
        'onyx'    => 'Onyx (OpenAI)',
        'nova'    => 'Nova (OpenAI)',
        'eve'     => 'Eve (xAI / Grok)',
        'ara'     => 'Ara (xAI / Grok)',
        'leo'     => 'Leo (xAI / Grok)',
        'rex'     => 'Rex (xAI / Grok)',
        'sal'     => 'Sal (xAI / Grok)',
    ];
    $settings->add(new admin_setting_configselect(
        'local_ai_course_assistant/realtime_voice',
        get_string('settings:realtime_voice', 'local_ai_course_assistant'),
        get_string('settings:realtime_voice_desc', 'local_ai_course_assistant'),
        'shimmer',
        $realtimevoices
    ));

    // Voice providers registry (multi-row). Defines one or more voice API
    // endpoints (OpenAI, xAI Grok) with per-provider API keys and default
    // voices. Three active-provider selects below pick which registered label
    // drives Realtime, TTS, and STT.
    $settings->add(new admin_setting_heading(
        'local_ai_course_assistant/voice_providers_heading',
        get_string('settings:voice_providers_heading', 'local_ai_course_assistant'),
        get_string('settings:voice_providers_heading_desc', 'local_ai_course_assistant'),
    ));

    $settings->add(new \local_ai_course_assistant\admin_setting_voice_providers(
        'local_ai_course_assistant/voice_providers',
        'Voice providers',
        'Add one row per voice API. Valid provider IDs: openai, xai (these are the only providers with WebSocket Realtime + TTS + STT today). The Label is a friendly name you use to pick the active provider for each capability below. Realtime voice and TTS voice can be left blank to use the provider default (shimmer for OpenAI, eve for xAI).'
    ));

    $activechoices = ['' => '(use first configured or legacy fallback)'];
    foreach (\local_ai_course_assistant\voice_registry::parse_rows() as $row) {
        $label = $row['label'] !== '' ? $row['label'] : ucfirst($row['provider']);
        $activechoices[$label] = $label . ' (' . $row['provider'] . ')';
    }

    $settings->add(new admin_setting_configselect(
        'local_ai_course_assistant/voice_active_realtime',
        get_string('settings:voice_active_realtime', 'local_ai_course_assistant'),
        get_string('settings:voice_active_realtime_desc', 'local_ai_course_assistant'),
        '',
        $activechoices
    ));
    $settings->add(new admin_setting_configselect(
        'local_ai_course_assistant/voice_active_tts',
        get_string('settings:voice_active_tts', 'local_ai_course_assistant'),
        get_string('settings:voice_active_tts_desc', 'local_ai_course_assistant'),
        '',
        $activechoices
    ));
    // STT additionally offers the selfhosted Whisper server (v6.2.0). When a
    // server URL is configured below, the blank default prefers selfhosted;
    // picking a paid label here overrides that.
    $sttchoices = ['' => '(selfhosted if configured, else first row or legacy fallback)'];
    $sttchoices[\local_ai_course_assistant\voice_registry::SELFHOSTED_LABEL] =
        'Selfhosted Whisper server (free, uses the URL below)';
    foreach (\local_ai_course_assistant\voice_registry::parse_rows() as $row) {
        $label = $row['label'] !== '' ? $row['label'] : ucfirst($row['provider']);
        $sttchoices[$label] = $label . ' (' . $row['provider'] . ')';
    }
    $settings->add(new admin_setting_configselect(
        'local_ai_course_assistant/voice_active_stt',
        get_string('settings:voice_active_stt', 'local_ai_course_assistant'),
        get_string('settings:voice_active_stt_desc', 'local_ai_course_assistant'),
        '',
        $sttchoices
    ));

    // Selfhosted Whisper STT server (v6.2.0). Any OpenAI compatible
    // transcription server works: whisper-server Docker, speaches
    // (faster-whisper), or whisper.cpp server.
    $settings->add(new admin_setting_heading(
        'local_ai_course_assistant/stt_selfhosted_heading',
        get_string('settings:stt_selfhosted_heading', 'local_ai_course_assistant'),
        \local_ai_course_assistant\branding::apply(get_string('settings:stt_selfhosted_heading_desc', 'local_ai_course_assistant'))
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_ai_course_assistant/stt_selfhosted_enabled',
        get_string('settings:stt_selfhosted_enabled', 'local_ai_course_assistant'),
        get_string('settings:stt_selfhosted_enabled_desc', 'local_ai_course_assistant'),
        1
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/stt_selfhosted_url',
        get_string('settings:stt_selfhosted_url', 'local_ai_course_assistant'),
        \local_ai_course_assistant\branding::apply(get_string('settings:stt_selfhosted_url_desc', 'local_ai_course_assistant')),
        '',
        // PARAM_RAW_TRIMMED is required: this points at a private Whisper server,
        // typically a container hostname such as http://whisper_server:8080/...
        // PARAM_URL discards hostnames containing underscores, which would break
        // existing docker-compose deployments. The value is not output as HTML;
        // it is validated by security::is_safe_provider_url() (plus the SSRF
        // trusted-endpoints allowlist) before any request is made.
        PARAM_RAW_TRIMMED
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/stt_selfhosted_model',
        get_string('settings:stt_selfhosted_model', 'local_ai_course_assistant'),
        get_string('settings:stt_selfhosted_model_desc', 'local_ai_course_assistant'),
        '',
        // Model slug ("whisper-1", "Systran/faster-whisper-large-v3"): PARAM_TEXT
        // keeps the dots and slashes PARAM_ALPHANUMEXT would strip, and
        // voice_registry trims the value when it reads it.
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_ai_course_assistant/stt_selfhosted_apikey',
        get_string('settings:stt_selfhosted_apikey', 'local_ai_course_assistant'),
        get_string('settings:stt_selfhosted_apikey_desc', 'local_ai_course_assistant'),
        ''
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_ai_course_assistant/stt_selfhosted_warm',
        get_string('settings:stt_selfhosted_warm', 'local_ai_course_assistant'),
        get_string('settings:stt_selfhosted_warm_desc', 'local_ai_course_assistant'),
        0
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_ai_course_assistant/voice_tab_enabled',
        get_string('settings:voice_tab_enabled', 'local_ai_course_assistant'),
        get_string('settings:voice_tab_enabled_desc', 'local_ai_course_assistant'),
        0
    ));

    // Soapbox: spoken-presentation practice. Its own section so the transcription
    // mode and rubric editor sit together, separate from the pedagogy toggles.
    $settings->add(new admin_setting_heading(
        'local_ai_course_assistant/soapbox_heading',
        get_string('settings:soapbox_heading', 'local_ai_course_assistant'),
        get_string('settings:soapbox_heading_desc', 'local_ai_course_assistant'),
    ));

    // v6.7.0 Soapbox: which speech-to-text path the recorder uses. "server"
    // transcribes through the configured Whisper provider (self-hosted free, or
    // hosted OpenAI) via voice_registry; "browser" uses the learner's built-in
    // Web Speech API (free, no server, Chrome/Safari only). Server is the default
    // so transcription quality does not depend on the learner's browser.
    $settings->add(new admin_setting_configselect(
        'local_ai_course_assistant/soapbox_stt_mode',
        get_string('settings:soapbox_stt_mode', 'local_ai_course_assistant'),
        get_string('settings:soapbox_stt_mode_desc', 'local_ai_course_assistant'),
        'server',
        [
            'server'  => get_string('settings:soapbox_stt_mode_server', 'local_ai_course_assistant'),
            'browser' => get_string('settings:soapbox_stt_mode_browser', 'local_ai_course_assistant'),
        ]
    ));

    // Direct link to the speech rubric editor (Soapbox scores against the
    // speech rubric; the editor also loads the General / ESL level presets).
    $settings->add(new admin_setting_description(
        'local_ai_course_assistant/soapbox_rubric_link',
        'Soapbox speech rubric',
        'Edit the rubric Soapbox scores against, or load a level preset (General, ESL beginner, '
        . 'ESL intermediate, ESL advanced). '
        . '<a href="' . (new moodle_url('/local/ai_course_assistant/rubric_admin.php', ['type' => 'speech']))->out()
        . '" class="btn btn-sm btn-outline-primary ml-2">Open rubric editor &rarr;</a>'
    ));

    // v6.8.12 Soapbox video: site-wide caps and defaults for the video/audio
    // presentation assignments. Instructors set per-assignment values within
    // these caps; the caps are clamped server-side in soapbox_config.
    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/soapbox_max_seconds',
        get_string('settings:soapbox_max_seconds', 'local_ai_course_assistant'),
        get_string('settings:soapbox_max_seconds_desc', 'local_ai_course_assistant'),
        720,
        PARAM_INT
    ));
    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/soapbox_max_recordings',
        get_string('settings:soapbox_max_recordings', 'local_ai_course_assistant'),
        get_string('settings:soapbox_max_recordings_desc', 'local_ai_course_assistant'),
        3,
        PARAM_INT
    ));
    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/soapbox_retention_days',
        get_string('settings:soapbox_retention_days', 'local_ai_course_assistant'),
        get_string('settings:soapbox_retention_days_desc', 'local_ai_course_assistant'),
        7,
        PARAM_INT
    ));
    $settings->add(new admin_setting_configselect(
        'local_ai_course_assistant/soapbox_video_quality',
        get_string('settings:soapbox_video_quality', 'local_ai_course_assistant'),
        get_string('settings:soapbox_video_quality_desc', 'local_ai_course_assistant'),
        'standard_480p',
        [
            'low_360p'      => 'Low (360p, ~3 MB/min)',
            'standard_480p' => 'Standard (480p, ~4 MB/min)',
            'high_720p'     => 'High (720p, ~9 MB/min)',
        ]
    ));

    // v6.8.31 Soapbox slide vision (Phase 2, issue 15): master toggle for the
    // optional gpt-4o-mini vision pass over slide images. Off by default; even
    // when on, each assignment must also opt in via its own Slide visual-design
    // feedback checkbox. The pass adds a short visual-design note to the score.
    $settings->add(new admin_setting_configcheckbox(
        'local_ai_course_assistant/soapbox_slide_vision',
        get_string('settings:soapbox_slide_vision', 'local_ai_course_assistant'),
        get_string('settings:soapbox_slide_vision_desc', 'local_ai_course_assistant'),
        0
    ));
    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/soapbox_vision_provider',
        get_string('settings:soapbox_vision_provider', 'local_ai_course_assistant'),
        get_string('settings:soapbox_vision_provider_desc', 'local_ai_course_assistant'),
        'openai',
        PARAM_ALPHANUMEXT
    ));
    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/soapbox_vision_model',
        get_string('settings:soapbox_vision_model', 'local_ai_course_assistant'),
        get_string('settings:soapbox_vision_model_desc', 'local_ai_course_assistant'),
        'gpt-4o-mini',
        // Vendor model slug - PARAM_TEXT keeps the dots/slashes model ids need.
        PARAM_TEXT
    ));

    // v6.8.13 Soapbox video: object storage for recordings. The browser uploads
    // straight to S3 with a presigned URL (bytes never touch this server). By
    // default recordings live in the shared archive bucket under a soapbox/
    // prefix; a bucket lifecycle rule on that prefix is the deletion backstop.
    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/soapbox_storage_bucket',
        get_string('settings:soapbox_storage_bucket', 'local_ai_course_assistant'),
        get_string('settings:soapbox_storage_bucket_desc', 'local_ai_course_assistant'),
        'archive-course',
        PARAM_TEXT
    ));
    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/soapbox_storage_region',
        get_string('settings:soapbox_storage_region', 'local_ai_course_assistant'),
        get_string('settings:soapbox_storage_region_desc', 'local_ai_course_assistant'),
        'us-east-1',
        PARAM_TEXT
    ));
    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/soapbox_storage_prefix',
        get_string('settings:soapbox_storage_prefix', 'local_ai_course_assistant'),
        get_string('settings:soapbox_storage_prefix_desc', 'local_ai_course_assistant'),
        'soapbox/',
        PARAM_TEXT
    ));
    // Password type, not configtext: this is half of an IAM credential pair, and
    // Moodle only writes '********' into config_log for password settings. As
    // configtext, every access key ID ever saved stayed readable in mdl_config_log,
    // which is never purged. Its sibling soapbox_storage_secret was already
    // declared correctly; this one was missed, the same way redash_api_key was.
    // (configpasswordunmask takes no paramtype argument; it forces PARAM_RAW.)
    $settings->add(new admin_setting_configpasswordunmask(
        'local_ai_course_assistant/soapbox_storage_key',
        get_string('settings:soapbox_storage_key', 'local_ai_course_assistant'),
        get_string('settings:soapbox_storage_key_desc', 'local_ai_course_assistant'),
        ''
    ));
    $settings->add(new admin_setting_configpasswordunmask(
        'local_ai_course_assistant/soapbox_storage_secret',
        get_string('settings:soapbox_storage_secret', 'local_ai_course_assistant'),
        get_string('settings:soapbox_storage_secret_desc', 'local_ai_course_assistant'),
        ''
    ));

    // Student Survey.
    $settings->add(new admin_setting_heading(
        'local_ai_course_assistant/survey_heading',
        get_string('settings:survey_heading', 'local_ai_course_assistant'),
        get_string('settings:survey_heading_desc', 'local_ai_course_assistant', (new moodle_url('/local/ai_course_assistant/survey_admin.php'))->out()),
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_ai_course_assistant/survey_enabled',
        get_string('settings:survey_enabled', 'local_ai_course_assistant'),
        get_string('settings:survey_enabled_desc', 'local_ai_course_assistant'),
        1
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/survey_trigger_messages',
        get_string('settings:survey_trigger_messages', 'local_ai_course_assistant'),
        get_string('settings:survey_trigger_messages_desc', 'local_ai_course_assistant'),
        '10',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configselect(
        'local_ai_course_assistant/survey_frequency',
        get_string('settings:survey_frequency', 'local_ai_course_assistant'),
        get_string('settings:survey_frequency_desc', 'local_ai_course_assistant'),
        'once',
        [
            'once' => 'Once per course (default)',
            'monthly' => 'Once per month',
            'quarterly' => 'Once per quarter',
            'unlimited' => 'Every time (no limit)',
        ]
    ));

    // Practice Scoring (Rubrics).
    $settings->add(new admin_setting_heading(
        'local_ai_course_assistant/rubric_heading',
        get_string('settings:rubric_heading', 'local_ai_course_assistant'),
        get_string('settings:rubric_heading_desc', 'local_ai_course_assistant', (new moodle_url('/local/ai_course_assistant/rubric_admin.php'))->out()),
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_ai_course_assistant/practice_scoring_enabled',
        get_string('settings:practice_scoring_enabled', 'local_ai_course_assistant'),
        get_string('settings:practice_scoring_enabled_desc', 'local_ai_course_assistant'),
        1
    ));

    // Usability Testing.
    $settings->add(new admin_setting_heading(
        'local_ai_course_assistant/usertesting_heading',
        get_string('settings:usertesting_heading', 'local_ai_course_assistant'),
        get_string('settings:usertesting_heading_desc', 'local_ai_course_assistant'),
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_ai_course_assistant/usertesting_enabled',
        get_string('settings:usertesting_enabled', 'local_ai_course_assistant'),
        get_string('settings:usertesting_enabled_desc', 'local_ai_course_assistant'),
        '0'
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/usertesting_external_url',
        get_string('settings:usertesting_external_url', 'local_ai_course_assistant'),
        get_string('settings:usertesting_external_url_desc', 'local_ai_course_assistant'),
        '',
        PARAM_URL
    ));

    $settings->add(new admin_setting_description(
        'local_ai_course_assistant/usertesting_editor_link',
        'Edit Testing Tasks',
        '<a href="' . (new moodle_url('/local/ai_course_assistant/usertesting_admin.php'))->out()
        . '" class="btn btn-sm btn-outline-primary">Open Task Editor</a>'
    ));

    // Footer links: an "explore courses" link below the Feedback link, plus
    // customizable feedback wording.
    $settings->add(new admin_setting_heading(
        'local_ai_course_assistant/footer_links_heading',
        get_string('settings:footer_links_heading', 'local_ai_course_assistant'),
        get_string('settings:footer_links_heading_desc', 'local_ai_course_assistant'),
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/footer_courses_text',
        get_string('settings:footer_courses_text', 'local_ai_course_assistant'),
        get_string('settings:footer_courses_text_desc', 'local_ai_course_assistant'),
        'Explore open online courses at saylor.org',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/footer_courses_url',
        get_string('settings:footer_courses_url', 'local_ai_course_assistant'),
        get_string('settings:footer_courses_url_desc', 'local_ai_course_assistant'),
        'https://www.saylor.org',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/feedback_link_label',
        \local_ai_course_assistant\branding::str('settings:feedback_link_label'),
        \local_ai_course_assistant\branding::str('settings:feedback_link_label_desc'),
        '',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtextarea(
        'local_ai_course_assistant/feedback_panel_intro',
        get_string('settings:feedback_panel_intro', 'local_ai_course_assistant'),
        get_string('settings:feedback_panel_intro_desc', 'local_ai_course_assistant'),
        'Your feedback helps us improve the assistant and goes to your site administrators.',
        PARAM_TEXT
    ));

    // v6.9.7: master switch for the active-learners indicator. Default OFF.
    // The indicator tells a learner how many others are studying, which is a
    // social-proof nudge rather than a teaching feature, and it costs a poll
    // every 60 seconds per open drawer. Sites should opt in deliberately.
    // Existing sites that had it running will find it off after upgrade; the
    // setting below controls the count's scope once it is enabled.
    $settings->add(new admin_setting_configcheckbox(
        'local_ai_course_assistant/active_learners_enabled',
        get_string('settings:active_learners_enabled', 'local_ai_course_assistant'),
        get_string('settings:active_learners_enabled_desc', 'local_ai_course_assistant'),
        0
    ));

    // v4.1.1: Active-learners-online indicator scope. Default 'global' (the
    // anti-loneliness default — a global count rarely hits zero, so the
    // indicator actually appears on small courses). Set to 'course' to
    // restore the v4.1.0 per-course behaviour.
    $settings->add(new admin_setting_configselect(
        'local_ai_course_assistant/active_learners_scope',
        get_string('settings:active_learners_scope', 'local_ai_course_assistant'),
        get_string('settings:active_learners_scope_desc', 'local_ai_course_assistant'),
        'global',
        [
            'global' => get_string('settings:active_learners_scope_global', 'local_ai_course_assistant'),
            'course' => get_string('settings:active_learners_scope_course', 'local_ai_course_assistant'),
        ]
    ));

    // v4.5.0: Pedagogy defaults. Each setting here is a site-wide default
    // that applies to every course unless the per-course override is set.
    // Per-course override remains authoritative — admins can still force a
    // single course on or off independent of the global default. Default
    // off so upgrade is a no-op.
    $settings->add(new admin_setting_heading(
        'local_ai_course_assistant/pedagogy_defaults_heading',
        get_string('settings:pedagogy_defaults_heading', 'local_ai_course_assistant'),
        \local_ai_course_assistant\branding::apply(get_string('settings:pedagogy_defaults_heading_desc', 'local_ai_course_assistant'))
    ));
    foreach (
        [
        'mastery_enabled'         => 'pedagogy:mastery',
        'socratic_mode_enabled'   => 'pedagogy:socratic_mode',
        'worked_examples_enabled' => 'pedagogy:worked_examples',
        'flashcards_enabled'      => 'pedagogy:flashcards',
        'code_sandbox_enabled'    => 'pedagogy:code_sandbox',
        'essay_feedback_enabled'  => 'pedagogy:essay_feedback',
        'soapbox_enabled'         => 'pedagogy:soapbox',
        'talking_avatar_enabled'  => 'pedagogy:talking_avatar',
        // crossmastery / mastery_starter / program_path / learning_path render
        // in the Mastery tracking section below (they all require mastery).
        ] as $key => $stringkey
    ) {
        $settings->add(new admin_setting_configcheckbox(
            'local_ai_course_assistant/' . $key,
            \local_ai_course_assistant\branding::apply(get_string($stringkey, 'local_ai_course_assistant')),
            \local_ai_course_assistant\branding::apply(get_string($stringkey . '_desc', 'local_ai_course_assistant')),
            0
        ));
        // Talking-avatar provider config sits directly under its toggle so the
        // "configure a provider below" notice points at the fields beneath it.
        if ($key === 'talking_avatar_enabled') {
            // v4.9.0: Talking Avatar driver selection + per-provider config.
            // SOLA ships drivers for D-ID, HeyGen, Tavus (the three most cost-
            // effective real-time avatar vendors as of 2026-04) and Synthesia
            // Agents (newest entrant; iframable embed_url). Operators pick one in
            // the dropdown and fill in just that provider's API key + persona id;
            // the v4.8.1 placeholder fields below remain readable so an admin
            // mid-upgrade does not have to re-enter the key. SSRF-checked on
            // every outbound call.
            $settings->add(new admin_setting_heading(
                'local_ai_course_assistant/talking_avatar_heading',
                get_string('settings:talking_avatar_heading', 'local_ai_course_assistant'),
                \local_ai_course_assistant\branding::apply(get_string('settings:talking_avatar_heading_desc', 'local_ai_course_assistant'))
            ));
            $settings->add(new admin_setting_configselect(
                'local_ai_course_assistant/talking_avatar_provider',
                get_string('settings:talking_avatar_provider', 'local_ai_course_assistant'),
                get_string('settings:talking_avatar_provider_desc', 'local_ai_course_assistant'),
                '',
                [
                    ''          => get_string('settings:talking_avatar_provider_none', 'local_ai_course_assistant'),
                    'did'       => get_string('settings:talking_avatar_provider_did', 'local_ai_course_assistant'),
                    'heygen'    => get_string('settings:talking_avatar_provider_heygen', 'local_ai_course_assistant'),
                    'tavus'     => get_string('settings:talking_avatar_provider_tavus', 'local_ai_course_assistant'),
                    'synthesia' => get_string('settings:talking_avatar_provider_synthesia', 'local_ai_course_assistant'),
                ]
            ));
            // v7.3.3 (F10): the viewer's fallback notice has always told admins
            // to "set the CDN bundle URL in plugin settings" -- and the setting
            // had no admin surface, so the instruction was unfollowable.
            $settings->add(new admin_setting_configtext(
                'local_ai_course_assistant/cdn_bundle_url',
                get_string('settings:cdn_bundle_url', 'local_ai_course_assistant'),
                get_string('settings:cdn_bundle_url_desc', 'local_ai_course_assistant'),
                '',
                PARAM_URL
            ));
            foreach (['did', 'heygen', 'tavus', 'synthesia'] as $tap) {
                $settings->add(new admin_setting_configpasswordunmask(
                    'local_ai_course_assistant/' . $tap . '_api_key',
                    \local_ai_course_assistant\branding::apply(get_string('settings:talking_avatar_' . $tap . '_api_key', 'local_ai_course_assistant')),
                    \local_ai_course_assistant\branding::apply(get_string('settings:talking_avatar_' . $tap . '_api_key_desc', 'local_ai_course_assistant')),
                    ''
                ));
                $settings->add(new admin_setting_configtext(
                    'local_ai_course_assistant/' . $tap . '_persona_id',
                    \local_ai_course_assistant\branding::apply(get_string('settings:talking_avatar_' . $tap . '_persona_id', 'local_ai_course_assistant')),
                    \local_ai_course_assistant\branding::apply(get_string('settings:talking_avatar_' . $tap . '_persona_id_desc', 'local_ai_course_assistant')),
                    '',
                    PARAM_TEXT
                ));
            }
            // The v4.8.1 placeholder fields (talking_avatar_provider_url and
            // talking_avatar_provider_api_key) were removed in v7.0.0. They were
            // documented as an upgrade fallback, but base_provider::cfg() composes
            // 'talking_avatar_' . $key from suffixes like 'api_key' and 'base_url',
            // so it looked for talking_avatar_api_key — a name that was never a
            // registered setting. The fallback could not fire, which made the
            // API key field a credential prompt for a value nothing would read.

            // v4.10.0: avatar rate-card overrides. Mirrors the LLM rate-card
            // overrides editor; takes a JSON object keyed by provider with a
            // single per-minute USD rate as the value. Empty = bundled defaults
            // (D-ID $0.30/min, HeyGen $0.50/min, Tavus $0.30/min, Synthesia $0.40/min).
            $settings->add(new admin_setting_configtextarea(
                'local_ai_course_assistant/avatar_rate_card_overrides',
                get_string('settings:avatar_rate_card_overrides', 'local_ai_course_assistant'),
                get_string('settings:avatar_rate_card_overrides_desc', 'local_ai_course_assistant'),
                '',
                // PARAM_RAW is required: a JSON object (braces, quotes and
                // newlines must survive verbatim). It is json_decode'd by the
                // rate-card reader, which keeps only known provider keys and
                // casts each value to float; it is never echoed as HTML.
                PARAM_RAW
            ));
            // v4.10.0: optional per-provider webhook signing secrets. When set, the
            // talking_avatar_webhook.php endpoint accepts and verifies session-end
            // payloads from that vendor; webhook rows take precedence over
            // heartbeat rows. Empty = webhook handler off for that provider.
            foreach (['did', 'heygen', 'tavus', 'synthesia'] as $tap) {
                $settings->add(new admin_setting_configpasswordunmask(
                    'local_ai_course_assistant/' . $tap . '_webhook_secret',
                    \local_ai_course_assistant\branding::apply(get_string('settings:talking_avatar_' . $tap . '_webhook_secret', 'local_ai_course_assistant')),
                    \local_ai_course_assistant\branding::apply(get_string('settings:talking_avatar_' . $tap . '_webhook_secret_desc', 'local_ai_course_assistant')),
                    ''
                ));
            }
        }
    }

    // v3.9.17: mastery tracking tunables. Per-course enable toggles live
    // on the per-course Objectives admin page; these are the site-wide
    // knobs that govern mastery math and the classifier behavior.
    $settings->add(new admin_setting_heading(
        'local_ai_course_assistant/mastery_heading',
        get_string('settings:mastery_heading', 'local_ai_course_assistant'),
        get_string('settings:mastery_heading_desc', 'local_ai_course_assistant')
    ));
    // Mastery-dependent feature defaults (moved here from the pedagogy list so the
    // toggles sit with the mastery knobs they depend on). Each is a site-wide
    // default; per-course overrides remain authoritative. Default off.
    foreach (
        [
        'crossmastery_enabled'    => 'pedagogy:crossmastery',
        'mastery_starter_enabled' => 'pedagogy:mastery_starter',
        'program_path_enabled'    => 'pedagogy:program_path',
        'learning_path_enabled'   => 'pedagogy:learning_path',
        ] as $mkey => $mstringkey
    ) {
        $settings->add(new admin_setting_configcheckbox(
            'local_ai_course_assistant/' . $mkey,
            \local_ai_course_assistant\branding::apply(get_string($mstringkey, 'local_ai_course_assistant')),
            \local_ai_course_assistant\branding::apply(get_string($mstringkey . '_desc', 'local_ai_course_assistant')),
            0
        ));
    }
    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/mastery_threshold',
        get_string('settings:mastery_threshold', 'local_ai_course_assistant'),
        get_string('settings:mastery_threshold_desc', 'local_ai_course_assistant'),
        '0.75',
        // Decimal fraction, or blank to fall back to the code default (which is
        // why this is not PARAM_FLOAT - that would coerce '' to 0). A regex
        // paramtype is accepted by admin_setting_configtext::validate() and
        // restricts the field to digits and one decimal point.
        '/^[0-9]*\.?[0-9]*$/'
    ));
    // v6.8.28 Outcomes-based assessment (WSCUC): the institution-set benchmark,
    // as a percentage, for the per-outcome achievement report. Distinct from the
    // coaching mastery threshold above: this is the reporting standard.
    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/outcomes_benchmark_default',
        get_string('settings:outcomes_benchmark_default', 'local_ai_course_assistant'),
        get_string('settings:outcomes_benchmark_default_desc', 'local_ai_course_assistant'),
        '70',
        PARAM_INT
    ));
    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/mastery_window',
        get_string('settings:mastery_window', 'local_ai_course_assistant'),
        get_string('settings:mastery_window_desc', 'local_ai_course_assistant'),
        '8',
        PARAM_INT
    ));
    // v5.9.0: percent of a course's tracked objectives that must be mastered for
    // the learning-path nudge to treat the learner as "ready" (the early path;
    // Moodle course completion is the other trigger).
    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/learning_path_mastery_threshold',
        get_string('settings:learning_path_mastery_threshold', 'local_ai_course_assistant'),
        get_string('settings:learning_path_mastery_threshold_desc', 'local_ai_course_assistant'),
        '80',
        PARAM_INT
    ));

    // v4.0 / M4 — Mastery decay model. Default off in v4.0; planned default-on
    // in v4.1 once tuning data is in. Read-side only; no schema change.
    $settings->add(new admin_setting_configcheckbox(
        'local_ai_course_assistant/mastery_decay_enabled',
        get_string('settings:mastery_decay_enabled', 'local_ai_course_assistant'),
        get_string('settings:mastery_decay_enabled_desc', 'local_ai_course_assistant'),
        0
    ));
    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/mastery_decay_half_life_days',
        get_string('settings:mastery_decay_half_life_days', 'local_ai_course_assistant'),
        get_string('settings:mastery_decay_half_life_days_desc', 'local_ai_course_assistant'),
        '30',
        PARAM_INT
    ));

    // v6.1.0: the four mastery_classifier_* settings are registered together
    // (provider, model, weight, threshold). Before v6.1.0 the premium
    // escalation block interrupted this group, splitting provider from its
    // three siblings — admins tuning the classifier had to scroll past an
    // unrelated feature.
        // Quiz coach: optional dedicated provider/model. Empty (the default)
    // keeps quiz generation on the course's chat provider, which is the
    // pre-v6.9.5 behaviour. Set both to route quiz turns to a cheaper or
    // better-suited model without touching the chat tier.
    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/quiz_provider',
        get_string('settings:quiz_provider', 'local_ai_course_assistant'),
        get_string('settings:quiz_provider_desc', 'local_ai_course_assistant'),
        '',
        PARAM_ALPHANUMEXT
    ));
    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/quiz_model',
        get_string('settings:quiz_model', 'local_ai_course_assistant'),
        get_string('settings:quiz_model_desc', 'local_ai_course_assistant'),
        '',
        // Vendor model slug - PARAM_TEXT keeps the dots/slashes model ids need.
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/mastery_classifier_provider',
        get_string('settings:mastery_classifier_provider', 'local_ai_course_assistant'),
        get_string('settings:mastery_classifier_provider_desc', 'local_ai_course_assistant'),
        'openai',
        PARAM_ALPHANUMEXT
    ));
    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/mastery_classifier_model',
        get_string('settings:mastery_classifier_model', 'local_ai_course_assistant'),
        get_string('settings:mastery_classifier_model_desc', 'local_ai_course_assistant'),
        'gpt-4o-mini',
        // Vendor model slug - PARAM_TEXT keeps the dots/slashes model ids need.
        PARAM_TEXT
    ));
    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/mastery_classifier_weight',
        get_string('settings:mastery_classifier_weight', 'local_ai_course_assistant'),
        get_string('settings:mastery_classifier_weight_desc', 'local_ai_course_assistant'),
        '0.3',
        // Decimal fraction, blank = code default; see mastery_threshold above.
        '/^[0-9]*\.?[0-9]*$/'
    ));
    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/mastery_classifier_threshold',
        get_string('settings:mastery_classifier_threshold', 'local_ai_course_assistant'),
        get_string('settings:mastery_classifier_threshold_desc', 'local_ai_course_assistant'),
        '0.7',
        // Decimal fraction, blank = code default; see mastery_threshold above.
        '/^[0-9]*\.?[0-9]*$/'
    ));

    // v5.12.0: premium escalation tier (A.10 follow-on).
    // v6.1.0: moved after the complete mastery-classifier group so the
    // heading no longer splits that section in half.
    $settings->add(new admin_setting_heading(
        'local_ai_course_assistant/premium_escalation_heading',
        get_string('settings:premium_escalation_heading', 'local_ai_course_assistant'),
        \local_ai_course_assistant\branding::apply(get_string('settings:premium_escalation_heading_desc', 'local_ai_course_assistant'))
    ));
    $settings->add(new admin_setting_configcheckbox(
        'local_ai_course_assistant/premium_escalation_enabled',
        get_string('settings:premium_escalation_enabled', 'local_ai_course_assistant'),
        get_string('settings:premium_escalation_enabled_desc', 'local_ai_course_assistant'),
        0
    ));
    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/premium_escalation_provider',
        get_string('settings:premium_escalation_provider', 'local_ai_course_assistant'),
        get_string('settings:premium_escalation_provider_desc', 'local_ai_course_assistant'),
        'claude',
        PARAM_ALPHANUMEXT
    ));
    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/premium_escalation_model',
        get_string('settings:premium_escalation_model', 'local_ai_course_assistant'),
        get_string('settings:premium_escalation_model_desc', 'local_ai_course_assistant'),
        // claude-sonnet-5, not an Opus. Measured on the 50-prompt golden tutor
        // set 2026-08-22: sonnet-5 scored 14.56/15 against opus-5's 14.22 at
        // 0.352 cents/call against 2.224 -- higher quality for roughly a sixth
        // of the cost, and a third of the time to first token. The escalation
        // tier exists to buy quality on hard turns; on this evidence an Opus
        // target bought latency and spend instead. Note the previous default
        // was opus-4-8, which was not itself in that benchmark; the cost
        // argument holds regardless since 4.8 and 5 share a price tier.
        'claude-sonnet-5',
        // Vendor model slug - PARAM_TEXT keeps the dots/slashes model ids need.
        PARAM_TEXT
    ));
    $settings->add(new admin_setting_configtextarea(
        'local_ai_course_assistant/premium_escalation_triggers',
        get_string('settings:premium_escalation_triggers', 'local_ai_course_assistant'),
        get_string('settings:premium_escalation_triggers_desc', 'local_ai_course_assistant'),
        '',
        // PARAM_RAW is required: newline-separated regular expressions, whose
        // metacharacters (\, |, ^, $, backticks) must survive byte-for-byte.
        // premium_router::matches_trigger() trims each line, skips # comments and
        // evaluates it with a warning-suppressed preg_match, so a malformed
        // pattern is a non-match rather than an error; never output as HTML.
        PARAM_RAW
    ));
    $settings->add(new admin_setting_configtextarea(
        'local_ai_course_assistant/premium_escalation_course_tags',
        get_string('settings:premium_escalation_course_tags', 'local_ai_course_assistant'),
        get_string('settings:premium_escalation_course_tags_desc', 'local_ai_course_assistant'),
        '',
        // PARAM_RAW is required: a newline-separated list of course shortnames /
        // idnumbers, and the newlines are the record separator. premium_router
        // trims each line and compares it to the course record; never echoed.
        PARAM_RAW
    ));

    // ── Section: Branding & UI ──────────────────────────────────────────────
    $settings->add(new admin_setting_description(
        'local_ai_course_assistant/sec_branding_anchor',
        '',
        $sectionanchor('sec-branding', s(get_string('settingspage:sec_branding', 'local_ai_course_assistant')))
    ));

    $settings->add(new admin_setting_heading(
        'local_ai_course_assistant/branding_heading',
        get_string('settings:branding_heading', 'local_ai_course_assistant'),
        get_string('settings:branding_heading_desc', 'local_ai_course_assistant'),
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/institution_name',
        get_string('settings:institution_name', 'local_ai_course_assistant'),
        get_string('settings:institution_name_desc', 'local_ai_course_assistant'),
        'Saylor University'
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/institution_short_name',
        get_string('settings:institution_short_name', 'local_ai_course_assistant'),
        get_string('settings:institution_short_name_desc', 'local_ai_course_assistant'),
        'Saylor'
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/display_name',
        get_string('settings:display_name', 'local_ai_course_assistant'),
        get_string('settings:display_name_desc', 'local_ai_course_assistant'),
        'Saylor Online Learning Assistant'
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/short_name',
        get_string('settings:short_name', 'local_ai_course_assistant'),
        get_string('settings:short_name_desc', 'local_ai_course_assistant'),
        'SOLA'
    ));

    $settings->add(new admin_setting_configtextarea(
        'local_ai_course_assistant/welcome_message',
        get_string('settings:welcome_message', 'local_ai_course_assistant'),
        get_string('settings:welcome_message_desc', 'local_ai_course_assistant'),
        ''
    ));

    $settings->add(new admin_setting_configtextarea(
        'local_ai_course_assistant/chat_greeting',
        get_string('settings:chat_greeting', 'local_ai_course_assistant'),
        get_string('settings:chat_greeting_desc', 'local_ai_course_assistant'),
        ''
    ));

    $displaymodes = [
        'widget' => get_string('settings:display_mode_widget', 'local_ai_course_assistant'),
        'drawer' => get_string('settings:display_mode_drawer', 'local_ai_course_assistant'),
    ];
    $settings->add(new admin_setting_configselect(
        'local_ai_course_assistant/display_mode',
        get_string('settings:display_mode', 'local_ai_course_assistant'),
        get_string('settings:display_mode_desc', 'local_ai_course_assistant'),
        'drawer',
        $displaymodes
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_ai_course_assistant/avatar_animation_enabled',
        get_string('settings:avatar_animation_enabled', 'local_ai_course_assistant'),
        get_string('settings:avatar_animation_enabled_desc', 'local_ai_course_assistant'),
        1
    ));

    $positions = [
        'bottom-right' => get_string('settings:position_br', 'local_ai_course_assistant'),
        'bottom-left' => get_string('settings:position_bl', 'local_ai_course_assistant'),
        'top-right' => get_string('settings:position_tr', 'local_ai_course_assistant'),
        'top-left' => get_string('settings:position_tl', 'local_ai_course_assistant'),
    ];
    $settings->add(new admin_setting_configselect(
        'local_ai_course_assistant/position',
        get_string('settings:position', 'local_ai_course_assistant'),
        get_string('settings:position_desc', 'local_ai_course_assistant'),
        'bottom-right',
        $positions
    ));

    $avatarchoices = [
        'avatar_01' => get_string(
            'settings:avatar_saylor',
            'local_ai_course_assistant',
            get_config('local_ai_course_assistant', 'institution_name') ?: 'Saylor University'
        ),
    ];
    for ($i = 2; $i <= 10; $i++) {
        $num = str_pad($i, 2, '0', STR_PAD_LEFT);
        $avatarchoices["avatar_{$num}"] = "Avatar {$i}";
    }
    // Append admin-uploaded custom avatars to the selectable list. The filearea
    // is managed by the admin_setting_configstoredfile widget below; any file
    // uploaded there becomes a selectable default.
    require_once(__DIR__ . '/lib.php');
    foreach (local_ai_course_assistant_get_custom_avatars() as $av) {
        $avatarchoices[$av['key']] = 'Custom: ' . $av['label'];
    }
    $settings->add(new admin_setting_configselect(
        'local_ai_course_assistant/avatar',
        get_string('settings:avatar', 'local_ai_course_assistant'),
        get_string('settings:avatar_desc', 'local_ai_course_assistant'),
        'avatar_01',
        $avatarchoices
    ));

    // Admin-uploaded custom avatars. Files saved to the customavatars filearea
    // are served via local_ai_course_assistant_pluginfile() and appear in the
    // dropdown above so admins can set any uploaded image as the default.
    $settings->add(new admin_setting_configstoredfile(
        'local_ai_course_assistant/customavatars',
        get_string('settings:customavatars', 'local_ai_course_assistant'),
        get_string('settings:customavatars_desc', 'local_ai_course_assistant'),
        'customavatars',
        0,
        [
            'subdirs' => 0,
            'maxfiles' => 20,
            'accepted_types' => ['web_image'],
            'context' => context_system::instance(),
        ]
    ));

    $settings->add(new admin_setting_configcolourpicker(
        'local_ai_course_assistant/avatar_color',
        get_string('settings:avatar_color', 'local_ai_course_assistant'),
        get_string('settings:avatar_color_desc', 'local_ai_course_assistant'),
        '#152233'
    ));

    $settings->add(new admin_setting_configcolourpicker(
        'local_ai_course_assistant/avatar_fill',
        get_string('settings:avatar_fill', 'local_ai_course_assistant'),
        get_string('settings:avatar_fill_desc', 'local_ai_course_assistant'),
        '#ffffff'
    ));

    // v3.9.15: white-label contact points surfaced on the privacy notice.
    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/contact_email',
        get_string('settings:contact_email', 'local_ai_course_assistant'),
        get_string('settings:contact_email_desc', 'local_ai_course_assistant'),
        '',
        PARAM_EMAIL
    ));
    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/dpo_email',
        get_string('settings:dpo_email', 'local_ai_course_assistant'),
        get_string('settings:dpo_email_desc', 'local_ai_course_assistant'),
        '',
        PARAM_EMAIL
    ));
    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/privacy_external_url',
        get_string('settings:privacy_external_url', 'local_ai_course_assistant'),
        get_string('settings:privacy_external_url_desc', 'local_ai_course_assistant'),
        '',
        PARAM_URL
    ));

    // v3.9.16: admin-editable privacy notice override. If populated, this HTML
    // replaces the default branded notice rendered by privacy.php. Lets
    // Saylor (or any rebranded install) finalize the legal-reviewed notice
    // text in the admin UI without touching code.
    $settings->add(new admin_setting_confightmleditor(
        'local_ai_course_assistant/privacy_notice_override',
        get_string('settings:privacy_notice_override', 'local_ai_course_assistant'),
        get_string('settings:privacy_notice_override_desc', 'local_ai_course_assistant'),
        ''
    ));

    // ── Section: Integrations & Delivery ────────────────────────────────────
    $settings->add(new admin_setting_description(
        'local_ai_course_assistant/sec_integrations_anchor',
        '',
        $sectionanchor('sec-integrations', s(get_string('settingspage:sec_integrations', 'local_ai_course_assistant')))
    ));

    // FAQ & Zendesk.
    $settings->add(new admin_setting_heading(
        'local_ai_course_assistant/faq_heading',
        get_string('settings:faq_heading', 'local_ai_course_assistant'),
        get_string('settings:faq_heading_desc', 'local_ai_course_assistant')
    ));

    $settings->add(new admin_setting_configtextarea(
        'local_ai_course_assistant/faq_content',
        get_string('settings:faq_content', 'local_ai_course_assistant'),
        get_string('settings:faq_content_desc', 'local_ai_course_assistant'),
        ''
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_ai_course_assistant/zendesk_enabled',
        get_string('settings:zendesk_enabled', 'local_ai_course_assistant'),
        get_string('settings:zendesk_enabled_desc', 'local_ai_course_assistant'),
        0
    ));
    // v5.10.x (security finding #40): require disclosed learner consent before
    // a conversation (name, email, transcript) is escalated to the support desk.
    $settings->add(new admin_setting_configtextarea(
        'local_ai_course_assistant/escalation_intent_patterns',
        get_string('settings:escalation_intent_patterns', 'local_ai_course_assistant'),
        get_string('settings:escalation_intent_patterns_desc', 'local_ai_course_assistant'),
        '',
        PARAM_RAW,
        60,
        6
    ));
    $settings->add(new admin_setting_configcheckbox(
        'local_ai_course_assistant/zendesk_require_consent',
        get_string('settings:zendesk_require_consent', 'local_ai_course_assistant'),
        \local_ai_course_assistant\branding::apply(get_string('settings:zendesk_require_consent_desc', 'local_ai_course_assistant')),
        1
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/zendesk_subdomain',
        get_string('settings:zendesk_subdomain', 'local_ai_course_assistant'),
        get_string('settings:zendesk_subdomain_desc', 'local_ai_course_assistant'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/zendesk_email',
        get_string('settings:zendesk_email', 'local_ai_course_assistant'),
        get_string('settings:zendesk_email_desc', 'local_ai_course_assistant'),
        ''
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_ai_course_assistant/zendesk_token',
        get_string('settings:zendesk_token', 'local_ai_course_assistant'),
        get_string('settings:zendesk_token_desc', 'local_ai_course_assistant'),
        ''
    ));

    // Learning Radar Scheduled Reports — moved to a dedicated UI in v4.2.
    // The legacy single-schedule settings (metaai_cron_*) are now migrated
    // into the multi-schedule table on upgrade. This block keeps only the
    // anomaly-digest options in admin settings; per-query schedules live on
    // the analytics page.
    $settings->add(new admin_setting_heading(
        'local_ai_course_assistant/anomaly_digest_heading',
        \local_ai_course_assistant\branding::str('settings:anomaly_digest_heading'),
        \local_ai_course_assistant\branding::str('settings:anomaly_digest_heading_desc'),
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_ai_course_assistant/anomaly_digest_enabled',
        get_string('settings:anomaly_digest_enabled', 'local_ai_course_assistant'),
        get_string('settings:anomaly_digest_enabled_desc', 'local_ai_course_assistant'),
        0
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/anomaly_digest_threshold_pct',
        get_string('settings:anomaly_digest_threshold_pct', 'local_ai_course_assistant'),
        get_string('settings:anomaly_digest_threshold_pct_desc', 'local_ai_course_assistant'),
        '50',
        PARAM_INT
    ));

    // v7.2.1: PARAM_FLOAT rejected "25.50" (it cleans to 25.5, and configtext
    // compares the cleaned value to the input as a string) while happily storing
    // "-5" as a spend floor. See setting_money_nonnegative.
    $settings->add(new \local_ai_course_assistant\admin\setting_money_nonnegative(
        'local_ai_course_assistant/anomaly_digest_floor_usd',
        get_string('settings:anomaly_digest_floor_usd', 'local_ai_course_assistant'),
        get_string('settings:anomaly_digest_floor_usd_desc', 'local_ai_course_assistant'),
        '0'
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/anomaly_digest_recipient_email',
        get_string('settings:anomaly_digest_recipient_email', 'local_ai_course_assistant'),
        get_string('settings:anomaly_digest_recipient_email_desc', 'local_ai_course_assistant'),
        '',
        PARAM_EMAIL
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/anomaly_digest_slack_webhook',
        get_string('settings:anomaly_digest_slack_webhook', 'local_ai_course_assistant'),
        get_string('settings:anomaly_digest_slack_webhook_desc', 'local_ai_course_assistant'),
        '',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/anomaly_digest_teams_webhook',
        get_string('settings:anomaly_digest_teams_webhook', 'local_ai_course_assistant'),
        get_string('settings:anomaly_digest_teams_webhook_desc', 'local_ai_course_assistant'),
        '',
        PARAM_URL
    ));

    // Analytics export (Redash).
    $settings->add(new admin_setting_heading(
        'local_ai_course_assistant/redash_heading',
        get_string('redash_heading', 'local_ai_course_assistant'),
        get_string('redash_heading_desc', 'local_ai_course_assistant')
    ));

    // Password type, not configtext: Moodle only writes '********' into
    // config_log for password settings. A plain configtext credential has
    // every historical value recorded in the clear in mdl_config_log, which is
    // never purged and is readable by anything with DB or reporting access.
    // Confirmed on production 2026-08-03 -- a retired key was recoverable in
    // full from the log. Its siblings redash_user_api_key and github_token
    // were already declared correctly; this one was missed.
    $settings->add(new admin_setting_configpasswordunmask(
        'local_ai_course_assistant/redash_api_key',
        get_string('redash_api_key', 'local_ai_course_assistant'),
        get_string('redash_api_key_desc', 'local_ai_course_assistant'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/redash_allowed_origin',
        get_string('settings:redash_allowed_origin', 'local_ai_course_assistant'),
        get_string('settings:redash_allowed_origin_desc', 'local_ai_course_assistant'),
        ''
    ));

    // Default lookback window applied when a caller omits `since`, so a data
    // source that forgets the parameter cannot pull every row ever recorded.
    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/redash_export_window_days',
        get_string('settings:redash_export_window_days', 'local_ai_course_assistant'),
        get_string('settings:redash_export_window_days_desc', 'local_ai_course_assistant'),
        \local_ai_course_assistant\redash_export_request::DEFAULT_WINDOW_DAYS,
        PARAM_INT
    ));

    // Gate on anonymize=0. Without it the shared API key alone is enough to
    // pull real learner names out of the export.
    $settings->add(new admin_setting_configcheckbox(
        'local_ai_course_assistant/redash_allow_deanonymized',
        get_string('settings:redash_allow_deanonymized', 'local_ai_course_assistant'),
        get_string('settings:redash_allow_deanonymized_desc', 'local_ai_course_assistant'),
        0
    ));

    // v4.3.0: Real Redash integration. Three settings together let SOLA
    // push a Learning Radar query/response to Redash as a new saved query
    // via Redash's /api/queries endpoint. All three must be set for the
    // push action to work; the existing pull path (redash_export.php +
    // redash_api_key) keeps working unchanged.
    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/redash_base_url',
        get_string('settings:redash_base_url', 'local_ai_course_assistant'),
        get_string('settings:redash_base_url_desc', 'local_ai_course_assistant'),
        '',
        PARAM_URL
    ));
    $settings->add(new admin_setting_configpasswordunmask(
        'local_ai_course_assistant/redash_user_api_key',
        get_string('settings:redash_user_api_key', 'local_ai_course_assistant'),
        \local_ai_course_assistant\branding::apply(get_string('settings:redash_user_api_key_desc', 'local_ai_course_assistant')),
        ''
    ));
    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/redash_data_source_id',
        get_string('settings:redash_data_source_id', 'local_ai_course_assistant'),
        \local_ai_course_assistant\branding::apply(get_string('settings:redash_data_source_id_desc', 'local_ai_course_assistant')),
        '',
        PARAM_INT
    ));

    // CDN / frontend delivery.
    $settings->add(new admin_setting_heading(
        'local_ai_course_assistant/cdn_heading',
        get_string('settings:cdn_heading', 'local_ai_course_assistant'),
        get_string('settings:cdn_heading_desc', 'local_ai_course_assistant')
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/cdn_url',
        get_string('settings:cdn_url', 'local_ai_course_assistant'),
        get_string('settings:cdn_url_desc', 'local_ai_course_assistant'),
        '',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/cdn_version',
        get_string('settings:cdn_version', 'local_ai_course_assistant'),
        get_string('settings:cdn_version_desc', 'local_ai_course_assistant'),
        ''
    ));

    // Plugin updates (self-update from GitHub releases).
    // v4.6.0: Vendor & cost data overrides. Three settings let admins
    // (a) hide the Vendor DPA admin page if they do not use it, and
    // (b) edit the vendor DPA table and LLM rate card without a code edit.
    // Both override fields are JSON, merged on top of the hardcoded
    // defaults at runtime — empty string means "use defaults".
    $settings->add(new admin_setting_heading(
        'local_ai_course_assistant/vendor_data_heading',
        get_string('settings:vendor_data_heading', 'local_ai_course_assistant'),
        get_string('settings:vendor_data_heading_desc', 'local_ai_course_assistant')
    ));
    $settings->add(new admin_setting_configcheckbox(
        'local_ai_course_assistant/vendor_dpa_admin_page_enabled',
        get_string('settings:vendor_dpa_admin_page_enabled', 'local_ai_course_assistant'),
        get_string('settings:vendor_dpa_admin_page_enabled_desc', 'local_ai_course_assistant'),
        0
    ));
    $settings->add(new admin_setting_configtextarea(
        'local_ai_course_assistant/vendor_dpa_overrides',
        get_string('settings:vendor_dpa_overrides', 'local_ai_course_assistant'),
        get_string('settings:vendor_dpa_overrides_desc', 'local_ai_course_assistant'),
        '',
        // PARAM_RAW is required: a JSON object (braces, quotes and newlines must
        // survive verbatim). vendor_registry json_decode's it, keeps only
        // string-keyed array rows, and merges them over the shipped table;
        // vendor_dpa.php renders every field through Mustache, which escapes.
        PARAM_RAW
    ));
    $settings->add(new admin_setting_configtextarea(
        'local_ai_course_assistant/rate_card_overrides',
        get_string('settings:rate_card_overrides', 'local_ai_course_assistant'),
        get_string('settings:rate_card_overrides_desc', 'local_ai_course_assistant'),
        '',
        // PARAM_RAW is required: a JSON object of per-model rates. json_decode'd
        // by the rate-card reader, which keeps known keys only and casts each
        // rate to float; never echoed as HTML.
        PARAM_RAW
    ));

    // v4.7.0: rate-card auto-refresh. On by default. Weekly cron task pulls
    // an upstream pricing JSON (LiteLLM by default) and writes it into
    // rate_card_overrides above so cost estimation stays current without
    // admin intervention. Manual trigger button below for one-shot pulls.
    $settings->add(new admin_setting_configcheckbox(
        'local_ai_course_assistant/rate_card_auto_refresh',
        get_string('settings:rate_card_auto_refresh', 'local_ai_course_assistant'),
        \local_ai_course_assistant\branding::apply(get_string('settings:rate_card_auto_refresh_desc', 'local_ai_course_assistant')),
        1
    ));
    $settings->add(new admin_setting_configtext(
        'local_ai_course_assistant/rate_card_upstream_url',
        get_string('settings:rate_card_upstream_url', 'local_ai_course_assistant'),
        get_string('settings:rate_card_upstream_url_desc', 'local_ai_course_assistant'),
        \local_ai_course_assistant\rate_card_refresher::DEFAULT_UPSTREAM_URL,
        PARAM_URL
    ));

    // Manual "Refresh now" button + last-refresh status display.
    $lastrefreshat = (int) (get_config('local_ai_course_assistant', 'rate_card_last_refresh_at') ?: 0);
    $lastrefreshstatus = (string) (get_config('local_ai_course_assistant', 'rate_card_last_refresh_status') ?: '');
    $lastrefresherror = (string) (get_config('local_ai_course_assistant', 'rate_card_last_refresh_error') ?: '');
    $statusparts = [];
    if ($lastrefreshat > 0) {
        $statusparts[] = get_string(
            'settings:rate_card_last_refresh_at',
            'local_ai_course_assistant',
            userdate($lastrefreshat)
        );
    } else {
        $statusparts[] = get_string('settings:rate_card_never_refreshed', 'local_ai_course_assistant');
    }
    if ($lastrefreshstatus === 'error' && $lastrefresherror !== '') {
        $statusparts[] = '<span class="text-danger">' . s($lastrefresherror) . '</span>';
    } else if ($lastrefreshstatus === 'success') {
        $statusparts[] = '<span class="text-success">'
            . get_string('settings:rate_card_last_refresh_success', 'local_ai_course_assistant') . '</span>';
    }
    $refreshurl = new moodle_url(
        '/local/ai_course_assistant/rate_card_refresh.php',
        ['sesskey' => sesskey()]
    );
    $settings->add(new admin_setting_description(
        'local_ai_course_assistant/rate_card_refresh_button',
        get_string('settings:rate_card_refresh_now', 'local_ai_course_assistant'),
        '<a href="' . $refreshurl->out(false) . '" class="btn btn-sm btn-outline-primary">'
        . get_string('settings:rate_card_refresh_now_label', 'local_ai_course_assistant') . '</a>'
        . '<p class="text-muted mt-2 mb-0" style="font-size:13px">'
        . implode(' &middot; ', $statusparts) . '</p>'
    ));

    // ── v5.3.0: Empathetic communications + carryover memory ───────────────
    $settings->add(new admin_setting_heading(
        'local_ai_course_assistant/empathy_heading',
        get_string('empathy:title', 'local_ai_course_assistant'),
        \local_ai_course_assistant\branding::apply(get_string('empathy:desc', 'local_ai_course_assistant'))
    ));

    // Master kill switch for ALL outreach emails. Default OFF on fresh install.
    $settings->add(new admin_setting_configcheckbox(
        'local_ai_course_assistant/outreach_master_enabled',
        get_string('empathy:outreach_master_enabled', 'local_ai_course_assistant'),
        get_string('empathy:outreach_master_enabled_desc', 'local_ai_course_assistant'),
        '0'
    ));

    // Goals feature (in-chat only; no emails). Safe to default ON.
    $settings->add(new admin_setting_configcheckbox(
        'local_ai_course_assistant/goals_feature_enabled',
        get_string('empathy:goals_enabled', 'local_ai_course_assistant'),
        get_string('empathy:goals_enabled_desc', 'local_ai_course_assistant'),
        '1'
    ));

    // Milestone reflections (emails). Default OFF; explicit admin opt-in.
    $settings->add(new admin_setting_configcheckbox(
        'local_ai_course_assistant/milestones_feature_enabled',
        get_string('empathy:milestones_enabled', 'local_ai_course_assistant'),
        get_string('empathy:milestones_enabled_desc', 'local_ai_course_assistant'),
        '0'
    ));

    // Carryover memory (in-chat only; powers personalisation). Default ON.
    $settings->add(new admin_setting_configcheckbox(
        'local_ai_course_assistant/memory_feature_enabled',
        get_string('empathy:memory_enabled', 'local_ai_course_assistant'),
        \local_ai_course_assistant\branding::apply(get_string('empathy:memory_enabled_desc', 'local_ai_course_assistant')),
        '1'
    ));

    // Struggle classifier (in-chat only; writes memory notes). Default OFF.
    $settings->add(new admin_setting_configcheckbox(
        'local_ai_course_assistant/struggle_classifier_enabled',
        get_string('empathy:struggle_enabled', 'local_ai_course_assistant'),
        \local_ai_course_assistant\branding::apply(get_string('empathy:struggle_enabled_desc', 'local_ai_course_assistant')),
        '0'
    ));

    // Dry-run mode. When on, milestone email task logs intent without
    // sending — safe way to verify cooldown + consent logic on a fresh
    // install before going live.
    $settings->add(new admin_setting_configcheckbox(
        'local_ai_course_assistant/outreach_dryrun',
        get_string('empathy:outreach_dryrun', 'local_ai_course_assistant'),
        get_string('empathy:outreach_dryrun_desc', 'local_ai_course_assistant'),
        '0'
    ));

    // v5.5.6: invisible anchor at the bottom of the registered settings so
    // the TOC can offer a "jump to Save" shortcut without forcing admins to
    // scroll through 2,000 lines of settings to commit a single change.
    // Moodle's framework renders the actual Save button just below this
    // anchor as part of the form template.
    $settings->add(new admin_setting_description(
        'local_ai_course_assistant/sec_save_anchor',
        '',
        '<span id="sec-save" class="sola-section-anchor"></span>'
    ));

    // ── Dependencies: collapse a feature's detail until the feature is on ───
    //
    // This page carries well over 200 controls, most of them belonging to
    // features that ship off. Until v7.0.0 every one of them rendered
    // unconditionally, which is why this file also grew a hand-rolled table of
    // contents and jump anchors. Moodle already solves this: hide_if() gives
    // each detail setting a dependency on the toggle that owns it, so a
    // default install shows the toggles and nothing else.
    //
    // Two rules kept this safe to apply mechanically:
    //   1. Each setting depends on its NEAREST owning toggle, never on a
    //      grandparent, so no setting carries two dependencies. rag_scorm_max_mb
    //      hangs off rag_extract_scorm, which hangs off rag_enabled.
    //   2. A toggle only owns a setting the toggle genuinely controls. The
    //      embed_* family sits under the "external resources" heading purely by
    //      position — external_resources_enabled governs whether the tutor may
    //      suggest outside links, not embeddings — so they depend on rag_enabled.
    //
    // This changes visibility only. Every setting keeps its stored value while
    // hidden, so toggling a feature back on restores the configuration intact.
    $dependencies = [
        // Retrieval. rag_enabled defaults on, so this hides nothing by default;
        // it matters for sites that deliberately run without retrieval.
        'rag_enabled' => [
            'embed_provider', 'embed_apikey', 'embed_model', 'embed_apibaseurl',
            'embed_dimensions', 'embed_query_model', 'embed_dtype', 'rag_topk', 'rag_min_similarity', 'rag_currentpage_boost',
            'rag_chunksize', 'rag_return_scope', 'rag_window_size', 'rag_parent_max_chars',
            'rag_scope', 'rag_auto_reindex_drifted', 'rerank_enabled',
            'rag_extract_pdf', 'rag_extract_docx', 'rag_extract_pptx',
            'rag_extract_h5p', 'rag_extract_scorm', 'rag_fetch_transcripts',
        ],
        'rag_extract_pdf'   => ['rag_pdftotext_path'],
        'rag_extract_scorm' => ['rag_scorm_max_mb'],
        'rag_fetch_transcripts' => ['rag_iframe_host_patterns', 'rag_transcript_url_pattern'],
        'rerank_enabled' => [
            'rerank_apikey', 'rerank_model', 'rerank_apibaseurl',
            'rerank_candidates', 'rerank_margin_threshold',
        ],

        // Provider routing.
        'premium_escalation_enabled' => [
            'premium_escalation_provider', 'premium_escalation_model',
            'premium_escalation_triggers', 'premium_escalation_course_tags',
        ],
        'failover_per_call_enabled' => ['failover_timeout_chat'],

        // Monitoring and alerting.
        'cost_anomaly_enabled' => ['cost_anomaly_multiplier'],
        'unanswered_check_enabled' => [
            'unanswered_window_hours', 'unanswered_min_questions', 'unanswered_min_answer_rate',
        ],
        'anomaly_digest_enabled' => [
            'anomaly_digest_threshold_pct', 'anomaly_digest_floor_usd',
            'anomaly_digest_recipient_email',
            'anomaly_digest_slack_webhook', 'anomaly_digest_teams_webhook',
        ],

        // Integrations.
        'policy_bundle_enabled' => ['policy_bundle_url', 'policy_bundle_pubkey'],
        'zendesk_enabled' => [
            'zendesk_require_consent', 'zendesk_subdomain', 'zendesk_email', 'zendesk_token',
        ],

        // Voice.
        'realtime_enabled' => ['realtime_apikey', 'realtime_voice'],
        'stt_selfhosted_enabled' => [
            'stt_selfhosted_url', 'stt_selfhosted_model',
            'stt_selfhosted_apikey', 'stt_selfhosted_warm',
        ],

        // Engagement and outreach.
        'reminders_whatsapp_enabled' => [
            'whatsapp_api_url', 'whatsapp_api_token',
            'whatsapp_from_number', 'whatsapp_blocked_countries',
        ],
        'inactivity_reminder_enabled' => ['inactivity_threshold_days'],
        'survey_enabled' => ['survey_trigger_messages', 'survey_frequency'],

        // Safety, integrity, mastery, Soapbox.
        'offtopic_enabled' => ['offtopic_max', 'offtopic_action', 'offtopic_lockout_duration'],
        'integrity_enabled' => ['integrity_email'],
        'mastery_decay_enabled' => ['mastery_decay_half_life_days'],
        'soapbox_slide_vision' => ['soapbox_vision_provider', 'soapbox_vision_model'],
    ];

    foreach ($dependencies as $toggle => $dependents) {
        foreach ($dependents as $dependent) {
            $settings->hide_if(
                'local_ai_course_assistant/' . $dependent,
                'local_ai_course_assistant/' . $toggle,
                'notchecked'
            );
        }
    }

    $ADMIN->add('local_ai_course_assistant', $settings);

    // ── External admin pages (tools / editors) ──────────────────────────────

    $ADMIN->add('local_ai_course_assistant', new admin_externalpage(
        'local_ai_course_assistant_courses',
        get_string('courses_admin:title', 'local_ai_course_assistant'),
        new moodle_url('/local/ai_course_assistant/courses_admin.php'),
        'moodle/site:config'
    ));

    $ADMIN->add('local_ai_course_assistant', new admin_externalpage(
        'local_ai_course_assistant_starters',
        get_string('starters:admin_title', 'local_ai_course_assistant'),
        new moodle_url('/local/ai_course_assistant/starter_settings.php'),
        'moodle/site:config'
    ));

    $ADMIN->add('local_ai_course_assistant', new admin_externalpage(
        'local_ai_course_assistant_survey',
        'Survey Editor',
        new moodle_url('/local/ai_course_assistant/survey_admin.php'),
        'moodle/site:config'
    ));

    $ADMIN->add('local_ai_course_assistant', new admin_externalpage(
        'local_ai_course_assistant_usertesting',
        'Usability Testing Editor',
        new moodle_url('/local/ai_course_assistant/usertesting_admin.php'),
        'moodle/site:config'
    ));

    $ADMIN->add('local_ai_course_assistant', new admin_externalpage(
        'local_ai_course_assistant_rubric',
        'Rubric Editor',
        new moodle_url('/local/ai_course_assistant/rubric_admin.php'),
        'moodle/site:config'
    ));

    $ADMIN->add('local_ai_course_assistant', new admin_externalpage(
        'local_ai_course_assistant_ragadmin',
        get_string('ragadmin:title', 'local_ai_course_assistant'),
        new moodle_url('/local/ai_course_assistant/rag_admin.php'),
        'moodle/site:config'
    ));

    // v5.0.0 patch 3: prompt-metrics + budget-recommendation surface.
    $ADMIN->add('local_ai_course_assistant', new admin_externalpage(
        'local_ai_course_assistant_prompt_metrics',
        get_string('prompt_metrics:title', 'local_ai_course_assistant'),
        new moodle_url('/local/ai_course_assistant/prompt_metrics.php'),
        'moodle/site:config'
    ));

    // v5.0.0 patch 10: prompt-debug-log viewer (per-turn assembled prompt + history).
    $ADMIN->add('local_ai_course_assistant', new admin_externalpage(
        'local_ai_course_assistant_prompt_debug_view',
        get_string('prompt_debug_view:title', 'local_ai_course_assistant'),
        new moodle_url('/local/ai_course_assistant/prompt_debug_view.php'),
        'moodle/site:config'
    ));

    // v6.2.0: prompt playground — assemble the prompt with simulated injected
    // content and inspect the result + per-section breakdown.
    $ADMIN->add('local_ai_course_assistant', new admin_externalpage(
        'local_ai_course_assistant_prompt_playground',
        \local_ai_course_assistant\branding::apply('[[tutorshort]] Prompt Playground'),
        new moodle_url('/local/ai_course_assistant/prompt_playground.php'),
        'moodle/site:config'
    ));

    $ADMIN->add('local_ai_course_assistant', new admin_externalpage(
        'local_ai_course_assistant_integrity',
        get_string('integrity:title', 'local_ai_course_assistant'),
        new moodle_url('/local/ai_course_assistant/integrity_admin.php'),
        'moodle/site:config'
    ));

    $ADMIN->add('local_ai_course_assistant', new admin_externalpage(
        'local_ai_course_assistant_demoadmin',
        get_string('demo:title', 'local_ai_course_assistant'),
        new moodle_url('/local/ai_course_assistant/demo_admin.php'),
        'moodle/site:config'
    ));

    $ADMIN->add('local_ai_course_assistant', new admin_externalpage(
        'local_ai_course_assistant_userdata',
        get_string(
            'admin:user_data:title',
            'local_ai_course_assistant',
            \local_ai_course_assistant\branding::short_name()
        ),
        new moodle_url('/local/ai_course_assistant/admin_user_data.php'),
        'moodle/site:config'
    ));

    // v4.6.0: Vendor DPA admin page is now gated on
    // `vendor_dpa_admin_page_enabled`, default off. Admins who do not need
    // the DPA dashboard get a smaller admin tree; those who do flip the
    // setting in Site administration → Plugins → Local plugins →
    // AI Course Assistant.
    if ((bool) get_config('local_ai_course_assistant', 'vendor_dpa_admin_page_enabled')) {
        $ADMIN->add('local_ai_course_assistant', new admin_externalpage(
            'local_ai_course_assistant_vendordpa',
            get_string(
                'admin:vendor_dpa:title',
                'local_ai_course_assistant',
                \local_ai_course_assistant\branding::short_name()
            ),
            new moodle_url('/local/ai_course_assistant/vendor_dpa.php'),
            'moodle/site:config'
        ));
    }

    // Catalyst's fork carries a whatsapp_test.php admin tool that calls
    // admin_externalpage_setup('local_ai_course_assistant_whatsapptest').
    // Register it defensively only when the file is present so upstream
    // installs without that file still build a valid admin menu.
    if (file_exists(__DIR__ . '/whatsapp_test.php')) {
        $ADMIN->add('local_ai_course_assistant', new admin_externalpage(
            'local_ai_course_assistant_whatsapptest',
            'WhatsApp Integration Test',
            new moodle_url('/local/ai_course_assistant/whatsapp_test.php'),
            'moodle/site:config',
            true
        ));
    }
}
