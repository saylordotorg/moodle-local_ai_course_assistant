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
 * Per-course AI provider settings page.
 *
 * Allows site admins to override the global AI provider config for a
 * specific course (e.g. use DeepSeek for Course A, GPT-4o for Course B).
 *
 * @package    local_ai_course_assistant
 * @copyright  2025-2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_ai_course_assistant\course_config_manager;

/**
 * Translated inline word for a global on/off state, used inside sentences and
 * in the "Inherit global (...)" option labels.
 *
 * @param bool $on Whether the global setting is currently on.
 * @return string Localized 'enabled' or 'disabled'.
 */
function local_ai_course_assistant_course_settings_state_label(bool $on): string {
    return $on
        ? get_string('coursesettings:state_enabled', 'local_ai_course_assistant')
        : get_string('coursesettings:state_disabled', 'local_ai_course_assistant');
}

$courseid = required_param('courseid', PARAM_INT);

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);
$syscontext = context_system::instance();

require_login($course);
require_capability('local/ai_course_assistant:manage', $context);

$pageurl = new moodle_url('/local/ai_course_assistant/course_settings.php', ['courseid' => $courseid]);
$PAGE->set_url($pageurl);
$PAGE->set_title(get_string('coursesettings:title', 'local_ai_course_assistant'));
$PAGE->set_heading($course->fullname . ': ' . get_string('coursesettings:title', 'local_ai_course_assistant'));
$PAGE->set_pagelayout('admin');
$PAGE->set_context($context);

// Global admin settings URL for the "back to global" link.
$globalsettingsurl = new moodle_url('/admin/category.php', ['category' => 'local_ai_course_assistant']);

// Current global defaults (shown as placeholder hints).
$globalcfg = [
    'provider'    => get_config('local_ai_course_assistant', 'provider') ?: 'claude',
    'apikey'      => get_config('local_ai_course_assistant', 'apikey') ?: '',
    'model'       => get_config('local_ai_course_assistant', 'model') ?: '',
    'apibaseurl'  => get_config('local_ai_course_assistant', 'apibaseurl') ?: '',
    'systemprompt' => get_config('local_ai_course_assistant', 'systemprompt') ?: '',
    'temperature'  => get_config('local_ai_course_assistant', 'temperature') ?: '0.7',
];

// Handle POST (save).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    $data = [
        'enabled'     => optional_param('enabled', 0, PARAM_INT),
        'provider'    => optional_param('provider', '', PARAM_ALPHA),
        // API keys legitimately contain arbitrary characters, so PARAM_RAW_TRIMMED
        // is the correct (and Moodle-conventional) type for a secret credential.
        // Submitted blank means "unchanged", not "clear it" -- the field is
        // rendered empty now (the stored key is never sent to the browser), so
        // treating blank as a wipe would destroy the key on every unrelated save.
        // Clearing is still possible: see the explicit clear handling below.
        'apikey'      => optional_param('apikey', '', PARAM_RAW_TRIMMED),
        'model'       => optional_param('model', '', PARAM_TEXT),
        'apibaseurl'  => optional_param('apibaseurl', '', PARAM_URL),
        // The system prompt is free-form text sent verbatim to the model (never
        // rendered as HTML); PARAM_RAW preserves characters PARAM_TEXT would strip.
        'systemprompt' => optional_param('systemprompt', '', PARAM_RAW),
        // Received raw, then range-validated and cast to float just below.
        'temperature'  => optional_param('temperature', '', PARAM_RAW_TRIMMED),
        // Per-course monthly spend cap in USD. Blank/0 = no per-course cap, in
        // which case spend_guard falls back to spend_cap_per_course_default.
        'spend_cap_monthly' => optional_param('spend_cap_monthly', '', PARAM_RAW_TRIMMED),
    ];

    // v7.0.5 security fix: a course-level actor must not be able to redirect
    // that course's AI traffic to a host of their choosing.
    //
    // This page is gated on local/ai_course_assistant:manage in the COURSE
    // context, which an editing teacher holds. apibaseurl was accepted from it
    // with no validation on this path at all -- is_safe_provider_url() is never
    // called here -- so a teacher could point their course at any host they
    // controlled and capture every student message and every retrieved course
    // chunk for that course. security::is_safe_provider_url()'s own docblock
    // says it was written to constrain a compromised admin, not a course-level
    // actor, and blocking private ranges does nothing about a public collector.
    //
    // Changing the endpoint is therefore a site-level act: without
    // moodle/site:config the submitted value is discarded and the stored one
    // kept, so an existing override survives an unrelated save by a teacher.
    // Accepted values still have to pass the SSRF check.
    if (!has_capability('moodle/site:config', $syscontext)) {
        $existing = course_config_manager::get($courseid);
        $data['apibaseurl'] = ($existing && isset($existing->apibaseurl)) ? (string) $existing->apibaseurl : '';
    } else if ($data['apibaseurl'] !== ''
            && !\local_ai_course_assistant\security::is_safe_provider_url($data['apibaseurl'])) {
        \core\notification::error(
            get_string('coursesettings:apibaseurl_rejected', 'local_ai_course_assistant')
        );
        $existing = course_config_manager::get($courseid);
        $data['apibaseurl'] = ($existing && isset($existing->apibaseurl)) ? (string) $existing->apibaseurl : '';
    }

    // Blank API key means "keep the stored one". The field is rendered empty
    // (the credential is never sent to the browser), so without this every save
    // of an unrelated field -- the enable toggle, the model, the system prompt --
    // would silently clear the course's key and drop it back to the site key.
    // An explicit clear is available via the dedicated checkbox.
    if ($data['apikey'] === '') {
        $existingkey = course_config_manager::get($courseid);
        $data['apikey'] = ($existingkey && isset($existingkey->apikey)) ? (string) $existingkey->apikey : '';
    }
    if (optional_param('apikey_clear', 0, PARAM_INT)) {
        $data['apikey'] = '';
    }

    // Validate temperature range if provided.
    if ($data['temperature'] !== '') {
        $temp = (float) $data['temperature'];
        if ($temp < 0 || $temp > 2) {
            $data['temperature'] = '0.7';
        } else {
            $data['temperature'] = (string) $temp;
        }
    }

    course_config_manager::save($courseid, $data);

    // Per-course SOLA enable toggle. Writes an explicit '1' or '0' so the
    // setting no longer inherits the site-wide default_course_mode. This is
    // the same key used by the Analytics page per-course toggle list.
    $solacourse = optional_param('sola_course_enabled', 0, PARAM_INT);
    set_config('sola_enabled_course_' . $courseid, $solacourse ? '1' : '0', 'local_ai_course_assistant');

    // RAG toggle — stored separately as plugin config keyed by course.
    $ragcourse = optional_param('rag_course_enabled', 0, PARAM_INT);
    set_config('rag_enabled_course_' . $courseid, $ragcourse, 'local_ai_course_assistant');

    // English lock — force English responses for ELL courses.
    $englishlock = optional_param('english_lock', 0, PARAM_INT);
    set_config('english_lock_course_' . $courseid, $englishlock, 'local_ai_course_assistant');

    // Supplemental courses — per-course override. An empty value REMOVES the
    // override so the course falls back to the site-wide list; that is why this
    // unsets rather than storing '', which would read as "supplement with
    // nothing" and silently override the site default.
    //
    // Site-level act, for the same reason apibaseurl above is one. This page is
    // gated on local/ai_course_assistant:manage in the COURSE context, which an
    // editing teacher holds. The only validation the ids ever get is
    // `visible = 1`, and in Moodle "visible" means "listed in the catalogue",
    // not "this user may read it" -- a visible course's content is normally
    // behind enrolment. Retrieval takes a course id and never checks enrolment,
    // can_access_course() or moodle/course:view: build_packed_index_from_db()
    // scopes by courseid and hydrate_content() fetches by chunk id alone.
    //
    // So without this gate a teacher with :manage in their own course could
    // name any visible course on the site and have its indexed text injected
    // into the prompt for themselves and for every learner in their course,
    // none of them enrolled in it. Deciding that one course's content may be
    // quoted into another is an administrator's call about what is suitable for
    // the audience, not a per-course one.
    //
    // As with apibaseurl, a teacher's save keeps the stored value rather than
    // clearing it, so an unrelated save does not wipe an administrator's list.
    if (has_capability('moodle/site:config', $syscontext)) {
        $supplemental = optional_param('supplemental_courses', '', PARAM_RAW_TRIMMED);
        if (trim($supplemental) !== '') {
            // Normalize through the same parser retrieval uses, so what is
            // stored is what will actually be honoured rather than what was
            // typed.
            $ids = \local_ai_course_assistant\supplemental_sources::parse($supplemental, $courseid);
            set_config('supplemental_courses_course_' . $courseid, implode(',', $ids), 'local_ai_course_assistant');
        } else {
            unset_config('supplemental_courses_course_' . $courseid, 'local_ai_course_assistant');
        }
        \local_ai_course_assistant\supplemental_sources::reset_cache();
    }

    // Voice Tab — per-course override (inherit / force on / force off).
    $voicetab = optional_param('voice_tab', '', PARAM_RAW_TRIMMED);
    if ($voicetab === '1' || $voicetab === '0') {
        set_config('sola_voicetab_course_' . $courseid, $voicetab, 'local_ai_course_assistant');
    } else {
        unset_config('sola_voicetab_course_' . $courseid, 'local_ai_course_assistant');
    }

    // Auto-open — per-course override (inherit / force on / force off).
    $autoopen = optional_param('auto_open', '', PARAM_RAW_TRIMMED);
    if ($autoopen === '1' || $autoopen === '0') {
        set_config('sola_autoopen_course_' . $courseid, $autoopen, 'local_ai_course_assistant');
    } else {
        unset_config('sola_autoopen_course_' . $courseid, 'local_ai_course_assistant');
    }

    // Starter overrides — per-course enable/disable for each starter.
    $starteroverrides = [];
    $allstarters = \local_ai_course_assistant\starter_manager::get_global_starters();
    foreach ($allstarters as $s) {
        $paramname = 'starter_' . clean_param($s['key'], PARAM_ALPHANUMEXT);
        $starteroverrides[$s['key']] = (bool) optional_param($paramname, 0, PARAM_INT);
    }
    \local_ai_course_assistant\starter_manager::save_course_overrides($courseid, $starteroverrides);

    // Pedagogy toggles (v3.9.31). Previously each had its own inline
    // sub-form with onchange="this.form.submit()". Browsers auto-close the
    // outer form when they see a nested <form>, which orphaned the bottom
    // Save button. All six are now plain checkboxes inside the outer form
    // and save on the same Save Changes submission.
    // v4.5.0: pedagogy toggles save as three-way (inherit / force on /
    // force off). Empty value = inherit (unset the per-course key, fall
    // back to site-wide default). '1' = force on. '0' = force off.
    foreach (
        [
        'socratic_mode'           => 'socratic_mode_course_',
        'flashcards_on'           => 'flashcards_enabled_course_',
        'sandbox_on'              => 'code_sandbox_enabled_course_',
        'essay_on'                => 'essay_feedback_enabled_course_',
        'soapbox_on'              => 'soapbox_enabled_course_',
        'we_on'                   => 'worked_examples_enabled_course_',
        ] as $field => $cfgprefix
    ) {
        $v = optional_param($field, '', PARAM_RAW_TRIMMED);
        if ($v === '1' || $v === '0') {
            set_config($cfgprefix . $courseid, $v, 'local_ai_course_assistant');
        } else {
            unset_config($cfgprefix . $courseid, 'local_ai_course_assistant');
        }
    }
    // v6.7.0: Soapbox course-type/level (general speech vs ESL beginner/advanced).
    // Tailors the AI coaching register and the default sample rubric.
    $soapboxlevel = optional_param('soapbox_level', '', PARAM_ALPHANUMEXT);
    $validlevels = array_keys(\local_ai_course_assistant\rubric_manager::speech_presets());
    if (in_array($soapboxlevel, $validlevels, true) && $soapboxlevel !== \local_ai_course_assistant\rubric_manager::SPEECH_LEVEL_GENERAL) {
        set_config('soapbox_level_course_' . $courseid, $soapboxlevel, 'local_ai_course_assistant');
    } else {
        unset_config('soapbox_level_course_' . $courseid, 'local_ai_course_assistant');
    }
    // Digest email keeps its checkbox semantics — it's a per-course delivery
    // toggle, not a feature on/off.
    set_config(
        'digest_email_enabled_course_' . $courseid,
        (int) optional_param('digest_email', 0, PARAM_BOOL),
        'local_ai_course_assistant'
    );

    // v4.2.3: external resources opt-in. Inherit / force on / force off.
    $extres = optional_param('external_resources', '', PARAM_RAW_TRIMMED);
    if ($extres === '1' || $extres === '0') {
        set_config('external_resources_enabled_course_' . $courseid, $extres, 'local_ai_course_assistant');
    } else {
        unset_config('external_resources_enabled_course_' . $courseid, 'local_ai_course_assistant');
    }

    // v5.2.0: per-quiz SOLA assistance level. The form posts one
    // quiz_level[<cmid>] entry per quiz in the course.
    $quizlevels = optional_param_array('quiz_level', [], PARAM_ALPHA);
    foreach ($quizlevels as $cmid => $level) {
        $cmid = (int)$cmid;
        if ($cmid <= 0) {
            continue;
        }
        // Confirm the cmid actually belongs to this course before writing.
        $owns = $DB->record_exists('course_modules', ['id' => $cmid, 'course' => $courseid]);
        if ($owns) {
            \local_ai_course_assistant\quiz_config_manager::save($cmid, $courseid, (string)$level);
        }
    }

    redirect(
        $pageurl,
        get_string('coursesettings:saved', 'local_ai_course_assistant'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// Load current course overrides.
$current = course_config_manager::get($courseid);

// Per-course SOLA enable state (effective, honours default_course_mode).
$solacourseenabled = course_config_manager::is_enabled_for_course($courseid);

// RAG setting — defaults to enabled if global RAG is on and not explicitly disabled.
$ragenabled = (bool)get_config('local_ai_course_assistant', 'rag_enabled');
$ragcourseraw = get_config('local_ai_course_assistant', 'rag_enabled_course_' . $courseid);
$ragcourseenabled = ($ragcourseraw === false) || (bool)$ragcourseraw;

// English lock setting.
$englishlockenabled = (bool)get_config('local_ai_course_assistant', 'english_lock_course_' . $courseid);
$supplementalraw = (string) (get_config('local_ai_course_assistant', 'supplemental_courses_course_' . $courseid) ?: '');
$supplementalsite = (string) (get_config('local_ai_course_assistant', 'supplemental_courses') ?: '');

// Voice Tab per-course override ('', '1', or '0').
$voicetabcourseraw = get_config('local_ai_course_assistant', 'sola_voicetab_course_' . $courseid);
$voicetabglobal = (bool)get_config('local_ai_course_assistant', 'voice_tab_enabled');

// Auto-open per-course override ('', '1', or '0').
$autoopencourseraw = get_config('local_ai_course_assistant', 'sola_autoopen_course_' . $courseid);
$autoopenglobal = (bool)get_config('local_ai_course_assistant', 'auto_open');

// Build provider options.
$providers = [
    ''         => get_string('coursesettings:using_global', 'local_ai_course_assistant') .
                  ' (' . $globalcfg['provider'] . ')',
    'claude'   => get_string('settings:provider_claude', 'local_ai_course_assistant'),
    'openai'   => get_string('settings:provider_openai', 'local_ai_course_assistant'),
    'deepseek' => get_string('settings:provider_deepseek', 'local_ai_course_assistant'),
    'ollama'   => get_string('settings:provider_ollama', 'local_ai_course_assistant'),
    'minimax'  => get_string('settings:provider_minimax', 'local_ai_course_assistant'),
    'coreai'   => get_string('settings:provider_coreai', 'local_ai_course_assistant'),
    'custom'   => get_string('settings:provider_custom', 'local_ai_course_assistant'),
];

// ---------------------------------------------------------------------
// Rendering (CONTRIB-10574 #273 and #278)
//
// The page markup is templates/course_settings.mustache, which renders every
// picker through templates/course_settings_select.mustache, and the one piece
// of behaviour this page has -- the "Reset to global" button beside the system
// prompt -- is amd/src/course_settings.js.
//
// Nothing about the page changed. Every input still posts under the name the
// handler at the top of this file reads, the cards appear in the same order,
// and the two quirks the old markup had are preserved deliberately: the
// pedagogy toggles live inside the RAG card and therefore only appear when RAG
// is enabled site-wide, and the API base URL field is rendered for anyone with
// :manage even though only a site administrator's value is kept on save.
//
// The default system prompt and the confirm text travel in the root element's
// data-config attribute rather than as js_call_amd arguments. A system prompt
// alone runs to several kilobytes, well past the 1,024-character advisory limit
// those arguments carry -- the complaint issue #277 raised about the starter
// admin page, where a 9,262-character payload printed a warning on every load.
// ---------------------------------------------------------------------

// Current state for the three-way pedagogy overrides: the raw per-course value
// ('1', '0' or false for "inherit") plus the site-wide default, so each select
// can label its inherit option with what inheriting currently means.
$socraticraw  = get_config('local_ai_course_assistant', 'socratic_mode_course_' . $courseid);
$fcraw        = get_config('local_ai_course_assistant', 'flashcards_enabled_course_' . $courseid);
$sbraw        = get_config('local_ai_course_assistant', 'code_sandbox_enabled_course_' . $courseid);
$essraw       = get_config('local_ai_course_assistant', 'essay_feedback_enabled_course_' . $courseid);
$sbxraw       = get_config('local_ai_course_assistant', 'soapbox_enabled_course_' . $courseid);
$weraw        = get_config('local_ai_course_assistant', 'worked_examples_enabled_course_' . $courseid);
$socraticgbl  = (bool) get_config('local_ai_course_assistant', 'socratic_mode_enabled');
$fcgbl        = (bool) get_config('local_ai_course_assistant', 'flashcards_enabled');
$sbgbl        = (bool) get_config('local_ai_course_assistant', 'code_sandbox_enabled');
$essgbl       = (bool) get_config('local_ai_course_assistant', 'essay_feedback_enabled');
$sbxgbl       = (bool) get_config('local_ai_course_assistant', 'soapbox_enabled');
$wegbl        = (bool) get_config('local_ai_course_assistant', 'worked_examples_enabled');

// Resolved booleans that decide whether a feature's secondary link is shown.
$fcon       = \local_ai_course_assistant\feature_flags::resolve('flashcards', $courseid);
$sbon       = \local_ai_course_assistant\feature_flags::resolve('code_sandbox', $courseid);
$esson      = \local_ai_course_assistant\feature_flags::resolve('essay_feedback', $courseid);
$sbxon      = \local_ai_course_assistant\feature_flags::resolve('soapbox', $courseid);
$sbxlevel   = (string) (get_config('local_ai_course_assistant', 'soapbox_level_course_' . $courseid)
    ?: \local_ai_course_assistant\rubric_manager::SPEECH_LEVEL_GENERAL);
$digeston   = (bool) get_config('local_ai_course_assistant', 'digest_email_enabled_course_' . $courseid);
$extresraw  = get_config('local_ai_course_assistant', 'external_resources_enabled_course_' . $courseid);
$extresglobal = (bool) get_config('local_ai_course_assistant', 'external_resources_enabled');

/**
 * Option list for a three-way pedagogy override (inherit / force on / force off).
 *
 * @param mixed $raw Stored per-course value: '1', '0', or false when unset.
 * @param bool $globalon Whether the site-wide default is on, for the inherit label.
 * @return array Option contexts for course_settings_select.mustache.
 */
$pedagogyoptions = function ($raw, bool $globalon): array {
    $on  = get_string('pedagogy:on', 'local_ai_course_assistant');
    $off = get_string('pedagogy:off', 'local_ai_course_assistant');
    $inheritlabel = get_string(
        'pedagogy:per_course_inherit',
        'local_ai_course_assistant',
        $globalon ? $on : $off
    );
    $selected = ($raw === '1' || $raw === '0') ? $raw : '';
    return [
        ['value' => '', 'label' => $inheritlabel, 'selected' => ($selected === '')],
        [
            'value' => '1',
            'label' => get_string('pedagogy:per_course_force_on', 'local_ai_course_assistant'),
            'selected' => ($selected === '1'),
        ],
        [
            'value' => '0',
            'label' => get_string('pedagogy:per_course_force_off', 'local_ai_course_assistant'),
            'selected' => ($selected === '0'),
        ],
    ];
};

/**
 * Select context for a three-way pedagogy override.
 *
 * @param string $name Posted field name.
 * @param string $id Element id, also the label's for target.
 * @param array $options Option contexts.
 * @return array Context for course_settings_select.mustache.
 */
$pedagogyselect = function (string $name, string $id, array $options): array {
    return [
        'hasid' => true,
        'id' => $id,
        'name' => $name,
        'selectclass' => 'form-control form-control-sm sola-cs-select-pedagogy',
        'options' => $options,
    ];
};

/**
 * Option list for the Voice Tab and Auto-open overrides.
 *
 * @param mixed $raw Stored per-course value: '1', '0', or false when unset.
 * @param bool $globalon Whether the site-wide default is on, for the inherit label.
 * @return array Option contexts for course_settings_select.mustache.
 */
$inheritoptions = function ($raw, bool $globalon): array {
    return [
        [
            'value' => '',
            'label' => get_string(
                'coursesettings:inherit_global',
                'local_ai_course_assistant',
                local_ai_course_assistant_course_settings_state_label($globalon)
            ),
            'selected' => ($raw === false || $raw === ''),
        ],
        [
            'value' => '1',
            'label' => get_string('coursesettings:force_on', 'local_ai_course_assistant'),
            'selected' => ($raw === '1'),
        ],
        [
            'value' => '0',
            'label' => get_string('coursesettings:force_off', 'local_ai_course_assistant'),
            'selected' => ($raw === '0'),
        ],
    ];
};

// Provider picker.
$provideroptions = [];
foreach ($providers as $val => $label) {
    $provideroptions[] = [
        'value' => $val,
        'label' => $label,
        'selected' => ($current && $current->provider === $val),
    ];
}

// API key field. The stored credential is never rendered; the placeholder is
// the only hint that one exists, and the clear checkbox is the only way to
// remove it.
$haskey = $current && $current->apikey !== '';
$keyph = $haskey
    ? get_string('coursesettings:apikey_stored', 'local_ai_course_assistant')
    : get_string('coursesettings:using_global', 'local_ai_course_assistant');

// Soapbox course type, shown only once Soapbox resolves to on for this course.
$soapboxleveloptions = [];
foreach (\local_ai_course_assistant\rubric_manager::speech_presets() as $lvkey => $lvdef) {
    $soapboxleveloptions[] = [
        'value' => $lvkey,
        'label' => get_string($lvdef['label_key'], 'local_ai_course_assistant'),
        'selected' => ($sbxlevel === $lvkey),
    ];
}

// External resources opt-in.
$extresoptions = [
    [
        'value' => '',
        'label' => get_string(
            'external_resources:inherit',
            'local_ai_course_assistant',
            $extresglobal
                ? get_string('external_resources:on', 'local_ai_course_assistant')
                : get_string('external_resources:off', 'local_ai_course_assistant')
        ),
        'selected' => ($extresraw === false || $extresraw === ''),
    ],
    [
        'value' => '1',
        'label' => get_string('external_resources:force_on', 'local_ai_course_assistant'),
        'selected' => ($extresraw === '1'),
    ],
    [
        'value' => '0',
        'label' => get_string('external_resources:force_off', 'local_ai_course_assistant'),
        'selected' => ($extresraw === '0'),
    ],
];

// Starter overrides.
$allstarters = \local_ai_course_assistant\starter_manager::get_global_starters();
$coursestarteroverrides = \local_ai_course_assistant\starter_manager::get_course_overrides($courseid);
$starterrows = [];
foreach ($allstarters as $s) {
    // With no course overrides saved yet, fall back to the global enabled state.
    $isenabled = is_array($coursestarteroverrides)
        ? !empty($coursestarteroverrides[$s['key']])
        : !empty($s['enabled']);
    $starterrows[] = [
        'paramname' => 'starter_' . clean_param($s['key'], PARAM_ALPHANUMEXT),
        'name' => $s['name'],
        'hasdescription' => !empty($s['description']),
        'description' => (string) ($s['description'] ?? ''),
        'enabled' => $isenabled,
    ];
}

// Per-quiz assistance level.
$quizrows = \local_ai_course_assistant\quiz_config_manager::list_for_course($courseid);
$levellabels = [
    'default' => get_string('quizsettings:level_default', 'local_ai_course_assistant'),
    'full'    => get_string('quizsettings:level_full', 'local_ai_course_assistant'),
    'coach'   => get_string('quizsettings:level_coach', 'local_ai_course_assistant'),
    'hidden'  => get_string('quizsettings:level_hidden', 'local_ai_course_assistant'),
];
$quizungraded = get_string('quizsettings:ungraded', 'local_ai_course_assistant');
$quizrowdata = [];
foreach ($quizrows as $q) {
    $leveloptions = [];
    foreach ($levellabels as $lkey => $llabel) {
        $leveloptions[] = [
            'value' => $lkey,
            'label' => $llabel,
            'selected' => ($q->stored_level === $lkey),
        ];
    }
    $hasgrade = ((float) $q->grade) > 0;
    $quizrowdata[] = [
        'name' => $q->name,
        'hasgrade' => $hasgrade,
        'grade' => $hasgrade ? format_float((float) $q->grade, 2) : '',
        'ungraded' => $quizungraded,
        'levelselect' => [
            'hasid' => false,
            'name' => 'quiz_level[' . (int) $q->cmid . ']',
            'selectclass' => 'form-control form-control-sm',
            'options' => $leveloptions,
        ],
        'effective' => (string) ($levellabels[$q->effective_level] ?? $q->effective_level),
    ];
}

$courseurl = new moodle_url('/course/view.php', ['id' => $courseid]);
$courseanalyticsurl = new moodle_url('/local/ai_course_assistant/analytics.php', ['courseid' => $courseid]);

$templatedata = [
    'configjson' => json_encode([
        'globalprompt' => $globalcfg['systemprompt'],
        'resetconfirm' => get_string('coursesettings:reset_prompt_confirm', 'local_ai_course_assistant'),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),

    'formaction' => $pageurl->out(false),
    'cancelurl' => $courseurl->out(false),
    'sesskey' => sesskey(),
    'savelabel' => get_string('savechanges'),
    'cancellabel' => get_string('cancel'),

    'navlinks' => [
        [
            'url' => $courseurl->out(false),
            'label' => get_string('coursesettings:back_to_course', 'local_ai_course_assistant'),
            'back' => true,
        ],
        [
            'url' => $globalsettingsurl->out(false),
            'label' => get_string('coursesettings:global_settings_link', 'local_ai_course_assistant'),
            'back' => false,
        ],
        [
            'url' => $courseanalyticsurl->out(false),
            'label' => get_string('coursesettings:course_analytics', 'local_ai_course_assistant'),
            'back' => false,
        ],
    ],

    'howitworks' => get_string('coursesettings:how_it_works', 'local_ai_course_assistant'),
    'howitworksdesc' => get_string('coursesettings:how_it_works_desc', 'local_ai_course_assistant'),

    // SOLA on/off for this course.
    'solaenabledtitle' => get_string('coursesettings:sola_enabled', 'local_ai_course_assistant'),
    'solaenableddesc' => get_string('coursesettings:sola_enabled_desc', 'local_ai_course_assistant'),
    'solaenabledtoggle' => get_string('coursesettings:sola_enabled_toggle', 'local_ai_course_assistant'),
    'solacourseenabled' => (bool) $solacourseenabled,

    // Provider override card.
    'title' => get_string('coursesettings:title', 'local_ai_course_assistant'),
    'enableddesc' => get_string('coursesettings:enabled_desc', 'local_ai_course_assistant'),
    'enabledlabel' => get_string('coursesettings:enabled', 'local_ai_course_assistant'),
    'enabled' => (bool) ($current && $current->enabled),
    'usingglobal' => get_string('coursesettings:using_global', 'local_ai_course_assistant'),
    'globalsettingsurl' => $globalsettingsurl->out(false),
    'globalsettingslabel' => get_string('coursesettings:global_settings_link', 'local_ai_course_assistant'),
    'providerlabel' => get_string('settings:provider', 'local_ai_course_assistant'),
    'providerselect' => [
        'hasid' => true,
        'id' => 'provider',
        'name' => 'provider',
        'selectclass' => 'form-control',
        'options' => $provideroptions,
    ],
    'spendcaplabel' => get_string('coursesettings:spend_cap_monthly', 'local_ai_course_assistant'),
    'spendcapvalue' => ($current && $current->spend_cap_monthly > 0)
        ? (string) (float) $current->spend_cap_monthly : '',
    'spendcapdesc' => get_string('coursesettings:spend_cap_monthly_desc', 'local_ai_course_assistant'),
    'apikeylabel' => get_string('settings:apikey', 'local_ai_course_assistant'),
    'apikeyplaceholder' => $keyph,
    'haskey' => (bool) $haskey,
    'apikeyclearlabel' => get_string('coursesettings:apikey_clear', 'local_ai_course_assistant'),
    'apikeydesc' => get_string('settings:apikey_desc', 'local_ai_course_assistant'),
    'modellabel' => get_string('settings:model', 'local_ai_course_assistant'),
    'modelvalue' => $current ? $current->model : '',
    'modelplaceholder' => $globalcfg['model'],
    'modeldesc' => get_string('settings:model_desc', 'local_ai_course_assistant'),
    'apibaseurllabel' => get_string('settings:apibaseurl', 'local_ai_course_assistant'),
    'apibaseurlvalue' => $current ? $current->apibaseurl : '',
    'apibaseurlplaceholder' => $globalcfg['apibaseurl'],
    'apibaseurldesc' => get_string('settings:apibaseurl_desc', 'local_ai_course_assistant'),
    'temperaturelabel' => get_string('settings:temperature', 'local_ai_course_assistant'),
    'temperaturevalue' => ($current && $current->temperature !== null) ? $current->temperature : '',
    'temperatureplaceholder' => $globalcfg['temperature'],
    'temperaturedesc' => get_string('settings:temperature_desc', 'local_ai_course_assistant'),
    'systempromptlabel' => get_string('settings:systemprompt', 'local_ai_course_assistant'),
    'systempromptvalue' => $current ? $current->systemprompt : '',
    'systemprompthint' => get_string('coursesettings:systemprompt_hint', 'local_ai_course_assistant'),
    'resetprompttitle' => get_string('coursesettings:reset_prompt_title', 'local_ai_course_assistant'),
    'resetpromptlabel' => get_string('coursesettings:reset_prompt', 'local_ai_course_assistant'),

    // RAG card. The pedagogy toggles below live inside it, so they are shown
    // only when RAG is on site-wide -- carried over from the old markup.
    'showrag' => (bool) $ragenabled,
    'ragtitle' => get_string('coursesettings:rag', 'local_ai_course_assistant'),
    'ragdesc' => get_string('coursesettings:rag_desc', 'local_ai_course_assistant'),
    'ragenablelabel' => get_string('coursesettings:rag_enable', 'local_ai_course_assistant'),
    'ragcourseenabled' => (bool) $ragcourseenabled,
    'ragadminurl' => (new moodle_url('/local/ai_course_assistant/rag_admin.php'))->out(false),
    'ragadminlabel' => get_string('ragadmin:title', 'local_ai_course_assistant'),
    'objectivesurl' => (new moodle_url(
        '/local/ai_course_assistant/objectives_admin.php',
        ['courseid' => $courseid]
    ))->out(false),
    'objectiveslabel' => get_string('objectives:title', 'local_ai_course_assistant'),
    'showdashboardlink' => has_capability('local/ai_course_assistant:viewanalytics', $context),
    'dashboardurl' => (new moodle_url(
        '/local/ai_course_assistant/instructor_dashboard.php',
        ['courseid' => $courseid]
    ))->out(false),
    'dashboardlabel' => get_string('instructor_dashboard:link', 'local_ai_course_assistant'),

    // Socratic mode.
    'socratictitle' => get_string('socratic:title', 'local_ai_course_assistant'),
    'socratichelp' => get_string('socratic:toggle_help', 'local_ai_course_assistant'),
    'socraticselect' => $pedagogyselect(
        'socratic_mode',
        'aica-socratic-mode',
        $pedagogyoptions($socraticraw, $socraticgbl)
    ),

    // Flashcards.
    'flashcardstitle' => get_string('flashcards:title', 'local_ai_course_assistant'),
    'flashcardshelp' => get_string('flashcards:toggle_help', 'local_ai_course_assistant'),
    'flashcardsselect' => $pedagogyselect(
        'flashcards_on',
        'aica-flashcards-on',
        $pedagogyoptions($fcraw, $fcgbl)
    ),
    'showflashcardslink' => (bool) $fcon,
    'flashcardsurl' => (new moodle_url(
        '/local/ai_course_assistant/flashcards.php',
        ['courseid' => $courseid]
    ))->out(false),
    'flashcardslinklabel' => get_string('flashcards:link', 'local_ai_course_assistant'),

    // Python code sandbox. The link only works once a runtime location is set
    // site-wide, so when it is not we say why instead of offering a button that
    // lands on an error page (v7.6.1).
    'sandboxtitle' => get_string('sandbox:title', 'local_ai_course_assistant'),
    'sandboxhelp' => get_string('sandbox:toggle_help', 'local_ai_course_assistant'),
    'sandboxselect' => $pedagogyselect(
        'sandbox_on',
        'aica-sandbox-on',
        $pedagogyoptions($sbraw, $sbgbl)
    ),
    'sandboxavailable' => \local_ai_course_assistant\code_sandbox::is_available($courseid),
    'sandboxurl' => (new moodle_url(
        '/local/ai_course_assistant/sandbox.php',
        ['courseid' => $courseid]
    ))->out(false),
    'sandboxlinklabel' => get_string('sandbox:link', 'local_ai_course_assistant'),
    'showsandboxwarning' => (!\local_ai_course_assistant\code_sandbox::is_available($courseid) && $sbon),
    'sandboxnoruntime' => get_string('sandbox:noruntimeurl', 'local_ai_course_assistant'),

    // Essay feedback.
    'essaytitle' => get_string('essay_feedback:title', 'local_ai_course_assistant'),
    'essayhelp' => get_string('essay_feedback:toggle_help', 'local_ai_course_assistant'),
    'essayselect' => $pedagogyselect(
        'essay_on',
        'aica-essay-on',
        $pedagogyoptions($essraw, $essgbl)
    ),
    'showessaylink' => (bool) $esson,
    'essayurl' => (new moodle_url(
        '/local/ai_course_assistant/essay_feedback.php',
        ['courseid' => $courseid]
    ))->out(false),
    'essaylinklabel' => get_string('essay_feedback:link', 'local_ai_course_assistant'),

    // Soapbox speech practice.
    'soapboxtitle' => get_string('soapbox:title', 'local_ai_course_assistant'),
    'soapboxhelp' => get_string('soapbox:toggle_help', 'local_ai_course_assistant'),
    'soapboxselect' => $pedagogyselect(
        'soapbox_on',
        'aica-soapbox-on',
        $pedagogyoptions($sbxraw, $sbxgbl)
    ),
    'showsoapbox' => (bool) $sbxon,
    'soapboxlevellabel' => get_string('soapbox:level_label', 'local_ai_course_assistant'),
    'soapboxlevelselect' => [
        'hasid' => true,
        'id' => 'aica-soapbox-level',
        'name' => 'soapbox_level',
        'selectclass' => 'form-control form-control-sm sola-cs-select-level',
        'options' => $soapboxleveloptions,
    ],
    'soapboxlevelhelp' => get_string('soapbox:level_help', 'local_ai_course_assistant'),
    'soapboxurl' => (new moodle_url(
        '/local/ai_course_assistant/soapbox.php',
        ['courseid' => $courseid]
    ))->out(false),
    'soapboxlinklabel' => get_string('soapbox:link', 'local_ai_course_assistant'),
    'soapboxrubricurl' => (new moodle_url(
        '/local/ai_course_assistant/rubric_admin.php',
        ['courseid' => $courseid, 'type' => 'speech']
    ))->out(false),
    'soapboxrubriclabel' => get_string('soapbox:edit_rubric', 'local_ai_course_assistant'),

    // Worked examples.
    'wetitle' => get_string('worked_examples:starter', 'local_ai_course_assistant'),
    'wehelp' => get_string('worked_examples:toggle_help', 'local_ai_course_assistant'),
    'weselect' => $pedagogyselect('we_on', 'aica-we-on', $pedagogyoptions($weraw, $wegbl)),

    // Weekly digest email. A delivery toggle, so it stays a plain checkbox.
    'digesttitle' => get_string('digest:title', 'local_ai_course_assistant'),
    'digesttoggle' => get_string('digest:toggle', 'local_ai_course_assistant'),
    'digesthelp' => get_string('digest:toggle_help', 'local_ai_course_assistant'),
    'digeston' => (bool) $digeston,

    // External resources opt-in.
    'extrestitle' => get_string('external_resources:title', 'local_ai_course_assistant'),
    'extresselect' => [
        'hasid' => true,
        'id' => 'aica-extres',
        'name' => 'external_resources',
        'selectclass' => 'form-control form-control-sm sola-cs-select-extres',
        'options' => $extresoptions,
    ],
    'extreshelp' => \local_ai_course_assistant\branding::apply(
        get_string('external_resources:toggle_help', 'local_ai_course_assistant')
    ),

    // Supplemental courses. Administrators only, matching the save path:
    // rendering an editable field whose value the POST handler discards is
    // worse than not showing it.
    'showsupplemental' => has_capability('moodle/site:config', $syscontext),
    'supplementalheading' => get_string('coursesettings:supplemental_heading', 'local_ai_course_assistant'),
    'supplementaldesc' => \local_ai_course_assistant\branding::str('coursesettings:supplemental_desc'),
    'supplementallabel' => get_string('coursesettings:supplemental_courses', 'local_ai_course_assistant'),
    'supplementalvalue' => $supplementalraw,
    'supplementalplaceholder' => $supplementalsite,
    'supplementalhelp' => $supplementalsite !== ''
        ? get_string('coursesettings:supplemental_inherit', 'local_ai_course_assistant', $supplementalsite)
        : get_string('coursesettings:supplemental_nosite', 'local_ai_course_assistant'),

    // English lock.
    'englishlockheading' => get_string('coursesettings:english_lock_heading', 'local_ai_course_assistant'),
    'englishlockdesc' => \local_ai_course_assistant\branding::str('coursesettings:english_lock_desc'),
    'englishlocklabel' => get_string('coursesettings:english_lock', 'local_ai_course_assistant'),
    'englishlocktoggle' => get_string('coursesettings:english_lock_toggle', 'local_ai_course_assistant'),
    'englishlockenabled' => (bool) $englishlockenabled,
    'englishlockhelp' => \local_ai_course_assistant\branding::str('coursesettings:english_lock_help'),

    // Voice Tab. The description's {$a} is a <strong>-wrapped state word, so
    // the template emits it unescaped.
    'voicetabtitle' => get_string('coursesettings:voice_tab', 'local_ai_course_assistant'),
    'voicetabdesc' => get_string(
        'coursesettings:voice_tab_desc',
        'local_ai_course_assistant',
        '<strong>' . local_ai_course_assistant_course_settings_state_label($voicetabglobal) . '</strong>'
    ),
    'voicetabselect' => [
        'hasid' => true,
        'id' => 'voice_tab',
        'name' => 'voice_tab',
        'selectclass' => 'form-control',
        'options' => $inheritoptions($voicetabcourseraw, $voicetabglobal),
    ],
    'voicetabhelp' => get_string('coursesettings:voice_tab_help', 'local_ai_course_assistant'),

    // Auto-open. Same <strong> treatment as Voice Tab above.
    'autoopenheading' => get_string('coursesettings:auto_open_heading', 'local_ai_course_assistant'),
    'autoopendesc' => \local_ai_course_assistant\branding::str(
        'coursesettings:auto_open_desc',
        '<strong>' . local_ai_course_assistant_course_settings_state_label($autoopenglobal) . '</strong>'
    ),
    'autoopenlabel' => get_string('coursesettings:auto_open', 'local_ai_course_assistant'),
    'autoopenselect' => [
        'hasid' => true,
        'id' => 'auto_open',
        'name' => 'auto_open',
        'selectclass' => 'form-control',
        'options' => $inheritoptions($autoopencourseraw, $autoopenglobal),
    ],
    'autoopenhelp' => get_string('coursesettings:auto_open_help', 'local_ai_course_assistant'),

    // Starter overrides.
    'starterssection' => get_string('starters:course_section', 'local_ai_course_assistant'),
    'startersdesc' => get_string('starters:course_desc', 'local_ai_course_assistant'),
    'starters' => $starterrows,
    'starteradminurl' => (new moodle_url('/local/ai_course_assistant/starter_settings.php'))->out(false),
    'starteradminlabel' => get_string('starters:admin_title', 'local_ai_course_assistant'),

    // Per-quiz assistance level.
    'showquizzes' => !empty($quizrowdata),
    'quiztitle' => get_string('quizsettings:title', 'local_ai_course_assistant'),
    'quizdesc' => \local_ai_course_assistant\branding::apply(
        get_string('quizsettings:desc', 'local_ai_course_assistant')
    ),
    'quizcolquiz' => get_string('quizsettings:colquiz', 'local_ai_course_assistant'),
    'quizcolgrade' => get_string('quizsettings:colgrade', 'local_ai_course_assistant'),
    'quizcollevel' => get_string('quizsettings:collevel', 'local_ai_course_assistant'),
    'quizcoleffective' => get_string('quizsettings:coleffective', 'local_ai_course_assistant'),
    'quizrows' => $quizrowdata,

    // Token usage.
    'tokenusagetitle' => get_string('coursesettings:token_usage', 'local_ai_course_assistant'),
    'tokenusagedesc' => get_string('coursesettings:token_usage_desc', 'local_ai_course_assistant'),
    'tokenusageurl' => (new moodle_url(
        '/local/ai_course_assistant/token_analytics.php',
        ['courseid' => $courseid]
    ))->out(false),
];

$PAGE->requires->js_call_amd('local_ai_course_assistant/course_settings', 'init');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('coursesettings:title', 'local_ai_course_assistant'));
echo $OUTPUT->render_from_template('local_ai_course_assistant/course_settings', $templatedata);
echo $OUTPUT->footer();
