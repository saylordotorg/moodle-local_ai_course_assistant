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
 * RAG index status and manual reindex admin page.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025-2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_ai_course_assistant\content_indexer;

$syscontext = context_system::instance();
require_login();
require_capability('moodle/site:config', $syscontext);

$action   = optional_param('action', '', PARAM_ALPHANUMEXT);
$courseid = optional_param('courseid', 0, PARAM_INT);

$pageurl = new moodle_url('/local/ai_course_assistant/rag_admin.php');
$PAGE->set_url($pageurl);
$PAGE->set_context($syscontext);
$PAGE->set_title(get_string('ragadmin:title', 'local_ai_course_assistant'));
$PAGE->set_heading(get_string('ragadmin:title', 'local_ai_course_assistant'));
$PAGE->set_pagelayout('admin');

// Handle POST actions.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    if ($action === 'reindexall') {
        // Reindex all courses that have active enrolments.
        $sql = "SELECT DISTINCT c.id, c.fullname
                  FROM {course} c
                  JOIN {enrol} e ON e.courseid = c.id AND e.status = 0
                  JOIN {user_enrolments} ue ON ue.enrolid = e.id AND ue.status = 0
                 WHERE c.id > 1 AND c.visible = 1";
        $courses = $DB->get_records_sql($sql);

        $totalindexed = 0;
        $totalskipped = 0;
        $totalerrors  = 0;
        $samplereason = '';

        foreach ($courses as $c) {
            try {
                $stats = content_indexer::index_course((int) $c->id);
                $totalindexed += $stats['indexed'];
                $totalskipped += $stats['skipped'];
                $totalerrors  += $stats['errors'];
                if ($samplereason === '' && !empty($stats['fatal'])) {
                    $samplereason = $stats['fatal'];
                }
                if ($samplereason === '' && !empty($stats['embed_error'])) {
                    $samplereason = $stats['embed_error'];
                }
            } catch (\Exception $e) {
                $totalerrors++;
                if ($samplereason === '') {
                    $samplereason = $e->getMessage();
                }
            }
        }

        $msg = get_string('ragadmin:reindexall_done', 'local_ai_course_assistant', (object)[
            'courses' => count($courses),
            'indexed' => $totalindexed,
            'skipped' => $totalskipped,
            'errors'  => $totalerrors,
        ]);
        // If nothing embedded across every course, the cause is almost always a
        // single shared misconfiguration (provider/key) — surface it.
        if ($totalindexed === 0 && $samplereason !== '') {
            redirect($pageurl,
                $msg . ' Nothing was embedded — first error: ' . $samplereason
                    . ' Check the embedding provider and API key in Settings.',
                null, \core\output\notification::NOTIFY_ERROR);
        }
        redirect($pageurl, $msg, null, \core\output\notification::NOTIFY_SUCCESS);

    } else if ($action === 'reindexcourse' && $courseid > 0) {
        try {
            $stats = content_indexer::index_course($courseid);
        } catch (\Exception $e) {
            redirect($pageurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
        }
        $msg = get_string('ragadmin:reindexcourse_done', 'local_ai_course_assistant', (object)[
            'indexed' => $stats['indexed'],
            'skipped' => $stats['skipped'],
            'errors'  => $stats['errors'],
        ]);
        // Explain a zero-chunk outcome instead of reporting a bare "0 indexed".
        if (!empty($stats['fatal'])) {
            redirect($pageurl,
                'Indexing could not run: ' . $stats['fatal']
                    . ' — check the embedding provider and API key in Settings.',
                null, \core\output\notification::NOTIFY_ERROR);
        }
        if ($stats['indexed'] === 0 && ($stats['sources'] ?? 0) > 0 && !empty($stats['embed_error'])) {
            redirect($pageurl,
                $msg . ' No chunks were embedded — first error: ' . $stats['embed_error']
                    . ' (most often the embedding API key or provider). The existing index was left untouched.',
                null, \core\output\notification::NOTIFY_ERROR);
        }
        if (($stats['sources'] ?? 0) === 0) {
            redirect($pageurl,
                $msg . ' No extractable content was found in this course (no pages, books, or supported files).',
                null, \core\output\notification::NOTIFY_WARNING);
        }
        redirect($pageurl, $msg, null, \core\output\notification::NOTIFY_SUCCESS);

    } else if ($action === 'deleteindex' && $courseid > 0) {
        content_indexer::delete_course_index($courseid);
        redirect($pageurl,
            get_string('ragadmin:deleteindex_done', 'local_ai_course_assistant'),
            null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

// Fetch per-course index statistics.
// Include: courses that have chunks OR are active (visible + have enrolments).
$sql = "SELECT c.id, c.fullname,
               COUNT(ch.id) AS chunks,
               SUM(CASE WHEN ch.embedding IS NOT NULL THEN 1 ELSE 0 END) AS embedded,
               MAX(ch.timeindexed) AS lastindexed
          FROM {course} c
          LEFT JOIN {local_ai_course_assistant_chunks} ch ON ch.courseid = c.id
         WHERE c.id > 1
         GROUP BY c.id, c.fullname
        HAVING COUNT(ch.id) > 0
         ORDER BY c.fullname ASC";
$indexedcourses = $DB->get_records_sql($sql);

// Also get active (enrolled) courses not yet indexed.
$sql = "SELECT DISTINCT c.id, c.fullname
          FROM {course} c
          JOIN {enrol} e ON e.courseid = c.id AND e.status = 0
          JOIN {user_enrolments} ue ON ue.enrolid = e.id AND ue.status = 0
         WHERE c.id > 1 AND c.visible = 1
         ORDER BY c.fullname ASC";
$activecourses = $DB->get_records_sql($sql);

$ragenabled  = (bool) get_config('local_ai_course_assistant', 'rag_enabled');
$settingsurl = new moodle_url('/admin/category.php', ['category' => 'local_ai_course_assistant']);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('ragadmin:title', 'local_ai_course_assistant'));

// Back link.
echo html_writer::div(
    html_writer::link($settingsurl,
        '&larr; ' . get_string('ragadmin:back_to_settings', 'local_ai_course_assistant'),
        ['class' => 'btn btn-sm btn-outline-secondary mb-3']),
    'mb-2'
);

// RAG disabled notice.
if (!$ragenabled) {
    echo $OUTPUT->notification(
        get_string('ragadmin:rag_disabled_notice', 'local_ai_course_assistant'),
        \core\output\notification::NOTIFY_WARNING
    );
}

// Content-source status card. Shows each extractor's gate (config flag) and
// runtime prerequisite (binary present, network allowlisted) so admins can
// see at a glance why content isn't being indexed. v3.9.6+.
$statusrows = [];

// Embedding provider is the hard prerequisite: without a working provider and
// (for hosted providers) an API key, every embedding call fails and a reindex
// produces zero chunks. Show it first so a misconfiguration is obvious.
$embedprovider = (string) (get_config('local_ai_course_assistant', 'embed_provider') ?: 'openai');
$embedmodel    = (string) (get_config('local_ai_course_assistant', 'embed_model') ?: '');
$embedkey      = (string) (get_config('local_ai_course_assistant', 'embed_apikey') ?: '');
$embedneedskey = ($embedprovider !== 'ollama'); // Local Ollama needs no key.
$embedok       = $embedneedskey ? ($embedkey !== '') : true;
$statusrows[] = [
    'label' => 'Embedding provider (required for RAG)',
    'ok'    => $embedok,
    'detail' => $embedok
        ? ('Provider: <code>' . s($embedprovider) . '</code>'
            . ($embedmodel !== '' ? ', model: <code>' . s($embedmodel) . '</code>' : '')
            . ($embedneedskey ? ', API key set.' : ', no key required (local).'))
        : ('Provider <code>' . s($embedprovider) . '</code> needs an API key, but '
            . '<code>embed_apikey</code> is empty. Set it in Settings — without it every '
            . 'embedding call fails and a reindex produces zero chunks.'),
];

$pdfon = (bool) get_config('local_ai_course_assistant', 'rag_extract_pdf');
if (class_exists('\\local_ai_course_assistant\\extractors\\file_extractor')) {
    $pdfavail = \local_ai_course_assistant\extractors\file_extractor::pdftotext_available();
} else {
    $pdfavail = false;
}
$statusrows[] = [
    'label' => 'PDF (mod_resource)',
    'ok'    => $pdfon && $pdfavail,
    'detail' => !$pdfon
        ? 'Disabled in settings.'
        : ($pdfavail
            ? 'On. pdftotext binary found.'
            : 'On, but pdftotext binary not found. Install poppler-utils (apt: <code>poppler-utils</code>; brew: <code>poppler</code>) or set the path in settings.'),
];
$statusrows[] = [
    'label' => 'DOCX (mod_resource)',
    'ok'    => (bool) get_config('local_ai_course_assistant', 'rag_extract_docx'),
    'detail' => get_config('local_ai_course_assistant', 'rag_extract_docx')
        ? 'On. Uses native PHP ZipArchive, no external dependency.'
        : 'Disabled in settings.',
];
$statusrows[] = [
    'label' => 'PPTX (mod_resource)',
    'ok'    => (bool) get_config('local_ai_course_assistant', 'rag_extract_pptx'),
    'detail' => get_config('local_ai_course_assistant', 'rag_extract_pptx')
        ? 'On. Uses native PHP ZipArchive, no external dependency. Walks slide and speaker-note XML. (Legacy binary .ppt is not supported; re-save as .pptx.)'
        : 'Disabled in settings.',
];
$statusrows[] = [
    'label' => 'H5P content',
    'ok'    => (bool) get_config('local_ai_course_assistant', 'rag_extract_h5p'),
    'detail' => get_config('local_ai_course_assistant', 'rag_extract_h5p')
        ? 'On. Walks content JSON for text fields.'
        : 'Disabled in settings.',
];
$statusrows[] = [
    'label' => 'SCORM packages',
    'ok'    => (bool) get_config('local_ai_course_assistant', 'rag_extract_scorm'),
    'detail' => get_config('local_ai_course_assistant', 'rag_extract_scorm')
        ? 'On. Max package size ' . (get_config('local_ai_course_assistant', 'rag_scorm_max_mb') ?: 100) . ' MB.'
        : 'Off by default. Enable in settings to index Articulate Storyline and other packaged content.',
];
$statusrows[] = [
    'label' => 'Embedded video / interactive transcripts',
    'ok'    => (bool) get_config('local_ai_course_assistant', 'rag_fetch_transcripts'),
    'detail' => get_config('local_ai_course_assistant', 'rag_fetch_transcripts')
        ? 'On. Scans pages/books for Synthesia, YouTube, Articulate, and Genially iframes; pairs each with the closest transcript link (above or below).'
        : 'Off. Enable in settings once the transcript host has allowlisted this server.',
];

echo '<div class="card mb-4"><div class="card-body">';
echo '<h4 class="mb-2">Content sources</h4>';
echo '<p class="text-muted" style="font-size:13px;">Each row shows a content type the RAG indexer can extract text from, and whether it is currently enabled and ready to run. Click <a href="' . (new moodle_url('/admin/settings.php', ['section' => 'local_ai_course_assistant_general']))->out() . '#sec-content">Settings</a> to toggle.</p>';
echo '<table class="table table-sm mb-0"><tbody>';
foreach ($statusrows as $r) {
    $badge = $r['ok']
        ? '<span class="badge badge-success" style="background:#0f9d58;color:#fff;">Ready</span>'
        : '<span class="badge badge-secondary" style="background:#6c757d;color:#fff;">Off</span>';
    echo '<tr>'
        . '<td style="width:260px;font-weight:500;">' . s($r['label']) . '</td>'
        . '<td style="width:70px;">' . $badge . '</td>'
        . '<td style="font-size:13px;color:#495057;">' . $r['detail'] . '</td>'
        . '</tr>';
}
echo '</tbody></table>';
echo '</div></div>';

// Summary totals.
$totalchunks   = array_sum(array_column((array) $indexedcourses, 'chunks'));
$totalembedded = array_sum(array_column((array) $indexedcourses, 'embedded'));
?>

<div class="row mb-4">
    <div class="col-md-3">
        <div class="card text-center">
            <div class="card-body">
                <h3 class="card-title"><?php echo count($indexedcourses); ?></h3>
                <p class="card-text text-muted"><?php echo get_string('ragadmin:stat_courses_indexed', 'local_ai_course_assistant'); ?></p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center">
            <div class="card-body">
                <h3 class="card-title"><?php echo number_format($totalchunks); ?></h3>
                <p class="card-text text-muted"><?php echo get_string('ragadmin:stat_total_chunks', 'local_ai_course_assistant'); ?></p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center">
            <div class="card-body">
                <h3 class="card-title"><?php echo number_format($totalembedded); ?></h3>
                <p class="card-text text-muted"><?php echo get_string('ragadmin:stat_embedded_chunks', 'local_ai_course_assistant'); ?></p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center">
            <div class="card-body">
                <h3 class="card-title"><?php echo count($activecourses); ?></h3>
                <p class="card-text text-muted"><?php echo get_string('ragadmin:stat_active_courses', 'local_ai_course_assistant'); ?></p>
            </div>
        </div>
    </div>
</div>

<!-- Reindex All button -->
<form method="post" action="<?php echo $pageurl->out(false); ?>" class="mb-4">
    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
    <input type="hidden" name="action" value="reindexall">
    <button type="submit" class="btn btn-primary"
        onclick="return confirm('<?php echo get_string('ragadmin:reindexall_confirm', 'local_ai_course_assistant'); ?>')">
        <?php echo get_string('ragadmin:reindexall', 'local_ai_course_assistant'); ?>
    </button>
    <small class="text-muted ml-2"><?php echo get_string('ragadmin:reindexall_desc', 'local_ai_course_assistant'); ?></small>
</form>

<!-- Status table -->
<h4><?php echo get_string('ragadmin:index_status', 'local_ai_course_assistant'); ?></h4>

<?php if (empty($indexedcourses) && empty($activecourses)): ?>
    <p class="text-muted"><?php echo get_string('ragadmin:no_courses', 'local_ai_course_assistant'); ?></p>
<?php else: ?>
<table class="table table-sm generaltable">
    <thead>
        <tr>
            <th><?php echo get_string('ragadmin:col_course', 'local_ai_course_assistant'); ?></th>
            <th class="text-right"><?php echo get_string('ragadmin:col_chunks', 'local_ai_course_assistant'); ?></th>
            <th class="text-right"><?php echo get_string('ragadmin:col_embedded', 'local_ai_course_assistant'); ?></th>
            <th><?php echo get_string('ragadmin:col_lastindexed', 'local_ai_course_assistant'); ?></th>
            <th><?php echo get_string('ragadmin:col_actions', 'local_ai_course_assistant'); ?></th>
        </tr>
    </thead>
    <tbody>
        <?php
        // Merge indexed and active courses, deduplicated.
        $allcourses = $indexedcourses;
        foreach ($activecourses as $ac) {
            if (!isset($allcourses[$ac->id])) {
                $ac->chunks    = 0;
                $ac->embedded  = 0;
                $ac->lastindexed = null;
                $allcourses[$ac->id] = $ac;
            }
        }
        uasort($allcourses, fn($a, $b) => strcmp($a->fullname, $b->fullname));

        foreach ($allcourses as $course): ?>
        <tr>
            <td>
                <?php echo html_writer::link(
                    new moodle_url('/course/view.php', ['id' => $course->id]),
                    s($course->fullname),
                    ['target' => '_blank']
                ); ?>
            </td>
            <td class="text-right">
                <?php echo $course->chunks > 0
                    ? html_writer::span(number_format($course->chunks), 'badge badge-info')
                    : html_writer::span('0', 'badge badge-secondary'); ?>
            </td>
            <td class="text-right">
                <?php echo $course->embedded > 0
                    ? html_writer::span(number_format($course->embedded), 'badge badge-success')
                    : html_writer::span('0', 'badge badge-secondary'); ?>
            </td>
            <td>
                <?php if (!empty($course->lastindexed)): ?>
                    <?php echo userdate((int)$course->lastindexed, get_string('strftimedatetimeshort', 'langconfig')); ?>
                <?php else: ?>
                    <span class="text-muted"><?php echo get_string('ragadmin:never', 'local_ai_course_assistant'); ?></span>
                <?php endif; ?>
            </td>
            <td>
                <form method="post" action="<?php echo $pageurl->out(false); ?>" class="d-inline">
                    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                    <input type="hidden" name="action" value="reindexcourse">
                    <input type="hidden" name="courseid" value="<?php echo (int)$course->id; ?>">
                    <button type="submit" class="btn btn-sm btn-outline-primary">
                        <?php echo get_string('ragadmin:reindex', 'local_ai_course_assistant'); ?>
                    </button>
                </form>
                <?php if ($course->chunks > 0): ?>
                <form method="post" action="<?php echo $pageurl->out(false); ?>" class="d-inline ml-1">
                    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                    <input type="hidden" name="action" value="deleteindex">
                    <input type="hidden" name="courseid" value="<?php echo (int)$course->id; ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger"
                        onclick="return confirm('<?php echo get_string('ragadmin:deleteindex_confirm', 'local_ai_course_assistant'); ?>')">
                        <?php echo get_string('ragadmin:deleteindex', 'local_ai_course_assistant'); ?>
                    </button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<?php
echo $OUTPUT->footer();
