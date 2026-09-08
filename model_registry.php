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
 * Model registry: prices, provenance, pricing sources, drift findings,
 * benchmark results and recommendations.
 *
 * Every capability on this page is operable with no shell and no deploy, which
 * is the requirement it exists to meet: adding or correcting a price is a form,
 * adding a pricing SOURCE is a form (a source is a database row with a
 * declarative parse spec, never a new PHP class per vendor), and running a
 * benchmark queues an adhoc task. Nothing here writes to the filesystem.
 *
 * All data building and all writes live in model_registry_page so they can be
 * tested; this file does access control, parameter cleaning, dispatch and
 * rendering only.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_ai_course_assistant\branding;
use local_ai_course_assistant\model_registry;
use local_ai_course_assistant\model_registry_page;
use local_ai_course_assistant\task\run_model_benchmark;

$syscontext = context_system::instance();
require_login();
require_capability('moodle/site:config', $syscontext);

$pageurl = new moodle_url(model_registry_page::PAGE_URL);
$PAGE->set_url($pageurl);
$PAGE->set_context($syscontext);
$PAGE->set_title(branding::str('modelregistry:title'));
$PAGE->set_heading(branding::str('modelregistry:title'));
$PAGE->set_pagelayout('admin');

// ---------------------------------------------------------------------------
// POST actions. Every one requires a session key; every one redirects, so a
// reload cannot repeat a write (or re-queue a billable benchmark).
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    $action = optional_param('action', '', PARAM_ALPHANUMEXT);
    $result = null;

    if ($action === 'savemodel' || $action === 'applydrift') {
        // Rates arrive as text, not PARAM_FLOAT: an empty field must stay
        // empty. PARAM_FLOAT turns '' into 0.0, which would record "this model
        // is free" as though an administrator had said so — the exact reading
        // this release exists to make impossible.
        $form = [
            'modelkey'       => optional_param('modelkey', '', PARAM_TEXT),
            'provider'       => optional_param('provider', '', PARAM_TEXT),
            'capability'     => optional_param('capability', '', PARAM_ALPHANUMEXT),
            'input_rate'     => optional_param('input_rate', '', PARAM_TEXT),
            'output_rate'    => optional_param('output_rate', '', PARAM_TEXT),
            'context_tokens' => optional_param('context_tokens', '', PARAM_TEXT),
            'status'         => optional_param('status', '', PARAM_ALPHA),
            'notes'          => optional_param('notes', '', PARAM_TEXT),
        ];
        $result = $action === 'savemodel'
            ? model_registry_page::save_model($form, (int) $USER->id)
            : model_registry_page::apply_drift($form, (int) $USER->id);
    } else if ($action === 'deletemodel') {
        $result = model_registry_page::delete_model(optional_param('modelkey', '', PARAM_TEXT));
    } else if ($action === 'savesource') {
        $result = model_registry_page::save_source([
            'sourceid' => optional_param('sourceid', 0, PARAM_INT),
            'name'     => optional_param('name', '', PARAM_TEXT),
            // PARAM_URL keeps only a syntactically valid URL and returns the
            // empty string otherwise, which save_source() reports as "a source
            // needs an https URL". The fetch itself is checked again against
            // the SSRF allowlist, so this is the first of two gates.
            'url'      => optional_param('url', '', PARAM_URL),
            'format'   => optional_param('format', '', PARAM_ALPHANUMEXT),
            // PARAM_RAW is required: the spec is a JSON object whose braces,
            // quotes and backslashes must survive verbatim. It is validated as
            // JSON before it is stored, is never eval'd, and reaches the page
            // only through Mustache, which escapes.
            'spec'     => optional_param('spec', '', PARAM_RAW),
            'enabled'  => optional_param('enabled', 0, PARAM_BOOL),
        ], (int) $USER->id);
    } else if ($action === 'togglesource') {
        $result = model_registry_page::toggle_source(
            optional_param('sourceid', 0, PARAM_INT),
            (bool) optional_param('enable', 0, PARAM_BOOL)
        );
    } else if ($action === 'deletesource') {
        $result = model_registry_page::delete_source(optional_param('sourceid', 0, PARAM_INT));
    } else if ($action === 'driftnow') {
        $result = model_registry_page::run_drift();
    } else if ($action === 'queuebench') {
        $result = model_registry_page::queue_benchmark(
            optional_param('registrykey', '', PARAM_TEXT),
            optional_param('solafunction', '', PARAM_ALPHANUMEXT),
            optional_param('samples', 0, PARAM_INT),
            (int) $USER->id
        );
    }

    if ($result === null) {
        redirect($pageurl);
    }
    $levels = [
        model_registry_page::OK => \core\output\notification::NOTIFY_SUCCESS,
        model_registry_page::WARN => \core\output\notification::NOTIFY_WARNING,
        model_registry_page::ERROR => \core\output\notification::NOTIFY_ERROR,
    ];
    redirect(
        $pageurl,
        $result['message'],
        null,
        $levels[$result['level']] ?? \core\output\notification::NOTIFY_INFO
    );
}

// ---------------------------------------------------------------------------
// Read-only page build.
// ---------------------------------------------------------------------------
$editkey = strtolower(trim(optional_param('edit', '', PARAM_TEXT)));
$editsourceid = optional_param('editsource', 0, PARAM_INT);

$modelform = [
    'modelkey'       => '',
    'provider'       => '',
    'capability'     => '',
    'input_rate'     => '',
    'output_rate'    => '',
    'context_tokens' => '',
    'notes'          => '',
    'editing'        => false,
    'statuses'       => model_registry_page::status_options(),
];
if ($editkey !== '') {
    $existing = $DB->get_record(model_registry::TABLE_MODELS, ['modelkey' => $editkey]);
    $prov = model_registry::provenance_for($editkey);
    $modelform['modelkey'] = $editkey;
    $modelform['editing'] = true;
    $modelform['provider'] = $existing ? (string) ($existing->provider ?? '') : '';
    $modelform['capability'] = $existing ? (string) ($existing->capability ?? '') : '';
    // Prefilled from the EFFECTIVE price when there is no table row yet, so
    // "correct this baseline price" starts from the number being charged
    // rather than from an empty field.
    $modelform['input_rate'] = model_registry_page::numberfield($prov['input'] ?? null);
    $modelform['output_rate'] = model_registry_page::numberfield($prov['output'] ?? null);
    $modelform['context_tokens'] = ($existing && !empty($existing->context_tokens))
        ? (string) (int) $existing->context_tokens : '';
    $modelform['notes'] = $existing ? (string) ($existing->notes ?? '') : '';
    foreach ($modelform['statuses'] as $idx => $option) {
        $modelform['statuses'][$idx]['selected'] =
            $existing && strtolower((string) $existing->status) === $option['value'];
    }
}

$sourceform = [
    'sourceid' => 0,
    'name'     => '',
    'url'      => '',
    'spec'     => '',
    'enabled'  => false,
    'editing'  => false,
    'formats'  => model_registry_page::format_options(),
];
if ($editsourceid > 0) {
    $source = $DB->get_record(model_registry::TABLE_SOURCES, ['id' => $editsourceid]);
    if ($source) {
        $sourceform['sourceid'] = (int) $source->id;
        $sourceform['name'] = (string) $source->name;
        $sourceform['url'] = (string) $source->url;
        $sourceform['spec'] = (string) ($source->spec ?? '');
        $sourceform['enabled'] = !empty($source->enabled);
        $sourceform['editing'] = true;
        foreach ($sourceform['formats'] as $idx => $option) {
            $sourceform['formats'][$idx]['selected'] = ($option['value'] === (string) $source->format);
        }
    }
}

$unpriced = model_registry_page::unpriced_block();
$effective = model_registry_page::effective_rows();
$sources = model_registry_page::source_rows();
$drift = model_registry_page::drift_block();
$bench = model_registry_page::bench_block();
$queuemodels = model_registry_page::queue_options();

// Every visible string is resolved here and passed as data. Brand-bearing
// strings go through branding::str so a rebranded institution never sees
// "SOLA" on this page; the rest are plain lang strings.
$labels = [
    'title'              => branding::str('modelregistry:title'),
    'intro'              => branding::str('modelregistry:intro'),
    'manualwins'         => get_string('modelregistry:manual_wins', 'local_ai_course_assistant'),
    // Unpriced block.
    'unpricedheading'    => get_string('modelregistry:unpriced_heading', 'local_ai_course_assistant'),
    'unpriceddesc'       => get_string(
        'modelregistry:unpriced_desc',
        'local_ai_course_assistant',
        $unpriced['days']
    ),
    'unpricednone'       => get_string(
        'modelregistry:unpriced_none',
        'local_ai_course_assistant',
        $unpriced['days']
    ),
    'unpricedcount'      => get_string(
        'modelregistry:unpriced_count',
        'local_ai_course_assistant',
        $unpriced['count']
    ),
    'priceit'            => get_string('modelregistry:price_it', 'local_ai_course_assistant'),
    // Shared column headers.
    'colmodel'           => get_string('modelregistry:col_model', 'local_ai_course_assistant'),
    'colprovider'        => get_string('modelregistry:col_provider', 'local_ai_course_assistant'),
    'colcalls'           => get_string('modelregistry:col_calls', 'local_ai_course_assistant'),
    'coltokens'          => get_string('modelregistry:col_tokens', 'local_ai_course_assistant'),
    'collastseen'        => get_string('modelregistry:col_lastseen', 'local_ai_course_assistant'),
    'colactions'         => get_string('modelregistry:col_actions', 'local_ai_course_assistant'),
    'colprefix'          => get_string('modelregistry:col_prefix', 'local_ai_course_assistant'),
    'colinput'           => get_string('modelregistry:col_input', 'local_ai_course_assistant'),
    'coloutput'          => get_string('modelregistry:col_output', 'local_ai_course_assistant'),
    'colcapability'      => get_string('modelregistry:col_capability', 'local_ai_course_assistant'),
    'colcontext'         => get_string('modelregistry:col_context', 'local_ai_course_assistant'),
    'colstatus'          => get_string('modelregistry:col_status', 'local_ai_course_assistant'),
    'collayer'           => get_string('modelregistry:col_layer', 'local_ai_course_assistant'),
    'colsource'          => get_string('modelregistry:col_source', 'local_ai_course_assistant'),
    'colsetby'           => get_string('modelregistry:col_setby', 'local_ai_course_assistant'),
    'colupdated'         => get_string('modelregistry:col_updated', 'local_ai_course_assistant'),
    'colquality'         => get_string('modelregistry:col_quality', 'local_ai_course_assistant'),
    'colcost'            => get_string('modelregistry:col_cost', 'local_ai_course_assistant'),
    'colttft'            => get_string('modelregistry:col_ttft', 'local_ai_course_assistant'),
    'colverdict'         => get_string('modelregistry:col_verdict', 'local_ai_course_assistant'),
    'colmeasured'        => get_string('modelregistry:col_measured', 'local_ai_course_assistant'),
    // Effective prices.
    'effectiveheading'   => get_string('modelregistry:effective_heading', 'local_ai_course_assistant'),
    'effectivedesc'      => get_string('modelregistry:effective_desc', 'local_ai_course_assistant'),
    'effectivenone'      => get_string('modelregistry:effective_none', 'local_ai_course_assistant'),
    'edit'               => get_string('modelregistry:edit', 'local_ai_course_assistant'),
    'delete'             => get_string('modelregistry:delete', 'local_ai_course_assistant'),
    'deleteconfirm'      => get_string('modelregistry:delete_confirm', 'local_ai_course_assistant'),
    // Model form.
    'formheading'        => get_string('modelregistry:form_heading', 'local_ai_course_assistant'),
    'formdesc'           => get_string('modelregistry:form_desc', 'local_ai_course_assistant'),
    'fieldmodelkey'      => get_string('modelregistry:field_modelkey', 'local_ai_course_assistant'),
    'fieldmodelkeyhelp'  => get_string('modelregistry:field_modelkey_help', 'local_ai_course_assistant'),
    'fieldprovider'      => get_string('modelregistry:field_provider', 'local_ai_course_assistant'),
    'fieldcapability'    => get_string('modelregistry:field_capability', 'local_ai_course_assistant'),
    'fieldcapabilityhelp' => get_string('modelregistry:field_capability_help', 'local_ai_course_assistant'),
    'fieldinput'         => get_string('modelregistry:field_input_rate', 'local_ai_course_assistant'),
    'fieldoutput'        => get_string('modelregistry:field_output_rate', 'local_ai_course_assistant'),
    'fieldoutputhelp'    => get_string('modelregistry:field_output_rate_help', 'local_ai_course_assistant'),
    'fieldcontext'       => get_string('modelregistry:field_context_tokens', 'local_ai_course_assistant'),
    'fieldstatus'        => get_string('modelregistry:field_status', 'local_ai_course_assistant'),
    'fieldnotes'         => get_string('modelregistry:field_notes', 'local_ai_course_assistant'),
    'fieldnoteshelp'     => get_string('modelregistry:field_notes_help', 'local_ai_course_assistant'),
    'save'               => get_string('modelregistry:save', 'local_ai_course_assistant'),
    // Pricing sources.
    'sourcesheading'     => get_string('modelregistry:sources_heading', 'local_ai_course_assistant'),
    'sourcesdesc'        => get_string('modelregistry:sources_desc', 'local_ai_course_assistant'),
    'sourcesnone'        => get_string('modelregistry:sources_none', 'local_ai_course_assistant'),
    'colname'            => get_string('modelregistry:col_name', 'local_ai_course_assistant'),
    'colurl'             => get_string('modelregistry:col_url', 'local_ai_course_assistant'),
    'colformat'          => get_string('modelregistry:col_format', 'local_ai_course_assistant'),
    'colenabled'         => get_string('modelregistry:col_enabled', 'local_ai_course_assistant'),
    'collastfetch'       => get_string('modelregistry:col_lastfetch', 'local_ai_course_assistant'),
    'colresult'          => get_string('modelregistry:col_result', 'local_ai_course_assistant'),
    'sourceformheading'  => get_string('modelregistry:source_form_heading', 'local_ai_course_assistant'),
    'fieldname'          => get_string('modelregistry:field_name', 'local_ai_course_assistant'),
    'fieldurl'           => get_string('modelregistry:field_url', 'local_ai_course_assistant'),
    'fieldurlhelp'       => get_string('modelregistry:field_url_help', 'local_ai_course_assistant'),
    'fieldformat'        => get_string('modelregistry:field_format', 'local_ai_course_assistant'),
    'fieldspec'          => get_string('modelregistry:field_spec', 'local_ai_course_assistant'),
    'fieldspechelp'      => get_string('modelregistry:field_spec_help', 'local_ai_course_assistant'),
    'fieldenabled'       => get_string('modelregistry:field_enabled', 'local_ai_course_assistant'),
    'savesource'         => get_string('modelregistry:save_source', 'local_ai_course_assistant'),
    'enable'             => get_string('modelregistry:enable', 'local_ai_course_assistant'),
    'disable'            => get_string('modelregistry:disable', 'local_ai_course_assistant'),
    // Drift.
    'driftheading'       => get_string('modelregistry:drift_heading', 'local_ai_course_assistant'),
    'driftdesc'          => get_string('modelregistry:drift_desc', 'local_ai_course_assistant'),
    'driftnone'          => get_string('modelregistry:drift_none', 'local_ai_course_assistant'),
    'driftrunnow'        => get_string('modelregistry:drift_run_now', 'local_ai_course_assistant'),
    'drifttolerance'     => get_string(
        'modelregistry:drift_tolerance',
        'local_ai_course_assistant',
        $drift['tolerance']
    ),
    'drifttruncated'     => get_string(
        'modelregistry:drift_truncated',
        'local_ai_course_assistant',
        $drift['truncated']
    ),
    'driftcolfinding'    => get_string('modelregistry:drift_col_finding', 'local_ai_course_assistant'),
    'driftcolproposed'   => get_string('modelregistry:drift_col_proposed', 'local_ai_course_assistant'),
    'driftcolregistry'   => get_string('modelregistry:drift_col_registry', 'local_ai_course_assistant'),
    'driftcoldelta'      => get_string('modelregistry:drift_col_delta', 'local_ai_course_assistant'),
    'driftapply'         => get_string('modelregistry:drift_apply', 'local_ai_course_assistant'),
    'driftnosource'      => get_string('modelregistry:drift_nosource', 'local_ai_course_assistant'),
    // Benchmarks and recommendations.
    'benchheading'       => get_string('bench:heading', 'local_ai_course_assistant'),
    'benchdesc'          => get_string('modelregistry:bench_desc', 'local_ai_course_assistant'),
    'benchnoresults'     => get_string('bench:no_results', 'local_ai_course_assistant'),
    'benchrecentruns'    => get_string('bench:recent_runs', 'local_ai_course_assistant'),
    'recunmeasured'      => get_string('rec:unmeasured', 'local_ai_course_assistant'),
    'recunmeasuredintro' => get_string('rec:unmeasuredintro', 'local_ai_course_assistant'),
    'benchrunnow'        => get_string('bench:run_now', 'local_ai_course_assistant'),
    // Queue form.
    'queueheading'       => get_string('modelregistry:queue_heading', 'local_ai_course_assistant'),
    'queuedesc'          => get_string('modelregistry:queue_desc', 'local_ai_course_assistant'),
    'queuefieldmodel'    => get_string('modelregistry:queue_field_model', 'local_ai_course_assistant'),
    'queuefieldfunction' => get_string('modelregistry:queue_field_function', 'local_ai_course_assistant'),
    'queuefieldsamples'  => get_string('modelregistry:queue_field_samples', 'local_ai_course_assistant'),
    'queuesubmit'        => get_string('modelregistry:queue_submit', 'local_ai_course_assistant'),
    'queuenoeligible'    => get_string('modelregistry:queue_noeligible', 'local_ai_course_assistant'),
];

$defaultsamples = (int) (get_config('local_ai_course_assistant', 'bench_default_samples')
    ?: run_model_benchmark::DEFAULT_SAMPLES);

$templatedata = [
    'backurl'   => (new moodle_url('/admin/category.php', ['category' => 'local_ai_course_assistant']))->out(false),
    'backlabel' => get_string('modelregistry:back_to_settings', 'local_ai_course_assistant'),
    'posturl'   => $pageurl->out(false),
    'pageurl'   => $pageurl->out(false),
    'sesskey'   => sesskey(),
    'l'         => $labels,
    'unpriced'  => $unpriced,
    'effective' => $effective,
    'modelform' => $modelform,
    'sources'   => $sources,
    'sourceform' => $sourceform,
    'drift'     => $drift,
    'bench'     => $bench,
    'queue'     => [
        'models'    => $queuemodels,
        'hasmodels' => !empty($queuemodels),
        'functions' => model_registry_page::function_options(),
        'samples'   => max(1, min(run_model_benchmark::MAX_SAMPLES, $defaultsamples)),
        'maxsamples' => run_model_benchmark::MAX_SAMPLES,
    ],
];

echo $OUTPUT->header();
echo $OUTPUT->heading(branding::str('modelregistry:title'));
echo $OUTPUT->render_from_template('local_ai_course_assistant/model_registry', $templatedata);
echo $OUTPUT->footer();
