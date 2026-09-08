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
use local_ai_course_assistant\embedding_migration;

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
            redirect(
                $pageurl,
                $msg . ' ' . get_string(
                    'ragadmin:nothing_embedded_all',
                    'local_ai_course_assistant',
                    $samplereason
                ),
                null,
                \core\output\notification::NOTIFY_ERROR
            );
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
            redirect(
                $pageurl,
                get_string(
                    'ragadmin:indexing_failed',
                    'local_ai_course_assistant',
                    $stats['fatal']
                ),
                null,
                \core\output\notification::NOTIFY_ERROR
            );
        }
        if ($stats['indexed'] === 0 && ($stats['sources'] ?? 0) > 0 && !empty($stats['embed_error'])) {
            redirect(
                $pageurl,
                $msg . ' ' . get_string(
                    'ragadmin:no_chunks_embedded',
                    'local_ai_course_assistant',
                    $stats['embed_error']
                ),
                null,
                \core\output\notification::NOTIFY_ERROR
            );
        }
        if (($stats['sources'] ?? 0) === 0) {
            redirect(
                $pageurl,
                $msg . ' ' . get_string('ragadmin:no_extractable_content', 'local_ai_course_assistant'),
                null,
                \core\output\notification::NOTIFY_WARNING
            );
        }
        // Surface documents that produced no indexable text, so a page that
        // silently generated no chunk (e.g. mostly an embedded interactive, or
        // shorter than the minimum) is visible instead of missed.
        if (!empty($stats['no_content'])) {
            $titles = array_map(
                fn($d) => format_string($d['title']),
                array_slice($stats['no_content'], 0, 8)
            );
            if (count($stats['no_content']) > 8) {
                $titles[] = '…';
            }
            redirect(
                $pageurl,
                $msg . ' ' . get_string('ragadmin:no_content_documents', 'local_ai_course_assistant', (object) [
                    'count'  => count($stats['no_content']),
                    'titles' => implode('; ', $titles),
                ]),
                null,
                \core\output\notification::NOTIFY_WARNING
            );
        }
        redirect($pageurl, $msg, null, \core\output\notification::NOTIFY_SUCCESS);
    } else if ($action === 'deleteindex' && $courseid > 0) {
        content_indexer::delete_course_index($courseid);
        redirect(
            $pageurl,
            get_string('ragadmin:deleteindex_done', 'local_ai_course_assistant'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } else if ($action === 'migrateone' && $courseid > 0) {
        // One adhoc task per course. Queued, not run inline: a page request
        // must not sit there re-embedding a course, and cron is what makes the
        // work parallelize and retry per course.
        $course = $DB->get_record('course', ['id' => $courseid], 'id, fullname');
        if (embedding_migration::queue_course($courseid, (int) $USER->id)) {
            redirect(
                $pageurl,
                get_string(
                    'embedmigration:queued_one',
                    'local_ai_course_assistant',
                    $course ? format_string($course->fullname) : $courseid
                ),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
        }
        redirect(
            $pageurl,
            get_string('embedmigration:already_queued', 'local_ai_course_assistant'),
            null,
            \core\output\notification::NOTIFY_WARNING
        );
    } else if ($action === 'migrateall') {
        $queued = embedding_migration::queue_all((int) $USER->id);
        if ($queued === 0) {
            redirect(
                $pageurl,
                get_string('embedmigration:queued_none', 'local_ai_course_assistant'),
                null,
                \core\output\notification::NOTIFY_WARNING
            );
        }
        redirect(
            $pageurl,
            get_string('embedmigration:queued_many', 'local_ai_course_assistant', $queued),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } else if ($action === 'migratepurge') {
        $deleted = embedding_migration::purge_superseded();
        redirect(
            $pageurl,
            $deleted > 0
                ? get_string('embedmigration:purged', 'local_ai_course_assistant', number_format($deleted))
                : get_string('embedmigration:purge_none', 'local_ai_course_assistant'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } else if ($action === 'faqreembed') {
        // Forced, because index_faq() is gated on a hash of the FAQ TEXT and
        // not on the embedding model: after a model change it would otherwise
        // decide it had nothing to do, stay invisible to the retriever, and
        // send context_builder back to injecting the whole FAQ inline.
        $faqstats = \local_ai_course_assistant\faq_manager::index_faq(true);
        if (!empty($faqstats['error'])) {
            redirect(
                $pageurl,
                get_string('embedmigration:faq_error', 'local_ai_course_assistant', $faqstats['error']),
                null,
                \core\output\notification::NOTIFY_ERROR
            );
        }
        redirect(
            $pageurl,
            get_string(
                'embedmigration:faq_reembedded',
                'local_ai_course_assistant',
                (int) ($faqstats['indexed'] ?? 0)
            ),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
}

// Fetch per-course index statistics.
// Include: courses that have chunks OR are active (visible + have enrolments).
$sql = "SELECT c.id, c.fullname,
               COUNT(ch.id) AS chunks,
               SUM(CASE WHEN ch.embedding IS NOT NULL OR ch.embedding_bin IS NOT NULL
                        THEN 1 ELSE 0 END) AS embedded,
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

// Content-source status card. Shows each extractor's gate (config flag) and
// runtime prerequisite (binary present, network allowlisted) so admins can see
// at a glance why content isn't being indexed. v3.9.6+.
$statusrows = [];

// Embedding provider is the hard prerequisite: without a working provider and
// (for hosted providers) an API key, every embedding call fails and a reindex
// produces zero chunks. Show it first so a misconfiguration is obvious.
$embedprovider = (string) (get_config('local_ai_course_assistant', 'embed_provider') ?: 'openai');
$embedmodel    = (string) (get_config('local_ai_course_assistant', 'embed_model') ?: '');
$embedkey      = (string) (get_config('local_ai_course_assistant', 'embed_apikey') ?: '');
$embedneedskey = ($embedprovider !== 'ollama'); // Local Ollama needs no key.
$embedok       = $embedneedskey ? ($embedkey !== '') : true;
if ($embedok) {
    $embeddetail = [get_string('ragadmin:src_embedding_provider', 'local_ai_course_assistant', s($embedprovider))];
    if ($embedmodel !== '') {
        $embeddetail[] = get_string('ragadmin:src_embedding_model', 'local_ai_course_assistant', s($embedmodel));
    }
    $embeddetail[] = $embedneedskey
        ? get_string('ragadmin:src_embedding_keyset', 'local_ai_course_assistant')
        : get_string('ragadmin:src_embedding_nokey', 'local_ai_course_assistant');
    $embeddetail = implode(' ', $embeddetail);
} else {
    $embeddetail = get_string(
        'ragadmin:src_embedding_missingkey',
        'local_ai_course_assistant',
        s($embedprovider)
    );
}
$statusrows[] = [
    'label'  => get_string('ragadmin:src_embedding', 'local_ai_course_assistant'),
    'ok'     => $embedok,
    'detail' => $embeddetail,
];

$pdfon = (bool) get_config('local_ai_course_assistant', 'rag_extract_pdf');
if (class_exists('\\local_ai_course_assistant\\extractors\\file_extractor')) {
    $pdfavail = \local_ai_course_assistant\extractors\file_extractor::pdftotext_available();
} else {
    $pdfavail = false;
}
$statusrows[] = [
    'label'  => get_string('ragadmin:src_pdf', 'local_ai_course_assistant'),
    'ok'     => $pdfon && $pdfavail,
    'detail' => !$pdfon
        ? get_string('ragadmin:src_disabled', 'local_ai_course_assistant')
        : ($pdfavail
            ? get_string('ragadmin:src_pdf_ok', 'local_ai_course_assistant')
            : get_string('ragadmin:src_pdf_missing', 'local_ai_course_assistant')),
];
$docxon = (bool) get_config('local_ai_course_assistant', 'rag_extract_docx');
$statusrows[] = [
    'label'  => get_string('ragadmin:src_docx', 'local_ai_course_assistant'),
    'ok'     => $docxon,
    'detail' => $docxon
        ? get_string('ragadmin:src_docx_ok', 'local_ai_course_assistant')
        : get_string('ragadmin:src_disabled', 'local_ai_course_assistant'),
];
$pptxon = (bool) get_config('local_ai_course_assistant', 'rag_extract_pptx');
$statusrows[] = [
    'label'  => get_string('ragadmin:src_pptx', 'local_ai_course_assistant'),
    'ok'     => $pptxon,
    'detail' => $pptxon
        ? get_string('ragadmin:src_pptx_ok', 'local_ai_course_assistant')
        : get_string('ragadmin:src_disabled', 'local_ai_course_assistant'),
];
$h5pon = (bool) get_config('local_ai_course_assistant', 'rag_extract_h5p');
$statusrows[] = [
    'label'  => get_string('ragadmin:src_h5p', 'local_ai_course_assistant'),
    'ok'     => $h5pon,
    'detail' => $h5pon
        ? get_string('ragadmin:src_h5p_ok', 'local_ai_course_assistant')
        : get_string('ragadmin:src_disabled', 'local_ai_course_assistant'),
];
$scormon = (bool) get_config('local_ai_course_assistant', 'rag_extract_scorm');
$statusrows[] = [
    'label'  => get_string('ragadmin:src_scorm', 'local_ai_course_assistant'),
    'ok'     => $scormon,
    'detail' => $scormon
        ? get_string(
            'ragadmin:src_scorm_ok',
            'local_ai_course_assistant',
            (int) (get_config('local_ai_course_assistant', 'rag_scorm_max_mb') ?: 100)
        )
        : get_string('ragadmin:src_scorm_off', 'local_ai_course_assistant'),
];
$transcriptson = (bool) get_config('local_ai_course_assistant', 'rag_fetch_transcripts');
$statusrows[] = [
    'label'  => get_string('ragadmin:src_transcripts', 'local_ai_course_assistant'),
    'ok'     => $transcriptson,
    'detail' => $transcriptson
        ? get_string('ragadmin:src_transcripts_ok', 'local_ai_course_assistant')
        : get_string('ragadmin:src_transcripts_off', 'local_ai_course_assistant'),
];

// The template needs the badge label per row alongside the ok flag.
foreach ($statusrows as $idx => $row) {
    $statusrows[$idx]['statelabel'] = $row['ok']
        ? get_string('ragadmin:badge_ready', 'local_ai_course_assistant')
        : get_string('ragadmin:badge_off', 'local_ai_course_assistant');
}

// Summary totals.
$totalchunks   = array_sum(array_column((array) $indexedcourses, 'chunks'));
$totalembedded = array_sum(array_column((array) $indexedcourses, 'embedded'));

// v7.0.3: what the stored vectors actually occupy, and what re-indexing at a
// lower precision would occupy instead. Measured from the column rather than
// estimated, because the index may contain a mixture of encodings while a
// re-index is still in progress.
// Derived from the recorded encoding rather than measured with a SQL length
// function. There is no portable byte-length across Moodle's supported
// databases: sql_length() maps to CHAR_LENGTH() on MySQL and LENGTH() on
// PostgreSQL, and SQL Server needs DATALENGTH() -- and applying a text-length
// helper to a binary column is the wrong tool even where it happens to work.
// Counting rows per encoding and multiplying is portable, and it stays correct
// while an index holds a mixture of encodings mid-reindex.
$rawdim = (int) get_config('local_ai_course_assistant', 'embed_dimensions');
$vectorbytes = 0;
if ($rawdim > 0) {
    // Group on the bare column, with no placeholder in the GROUP BY. Wrapping it
    // in COALESCE(embed_dtype, ?) would be rejected by PostgreSQL: the two
    // placeholders are distinct expression nodes, so GROUP BY COALESCE(col, $2)
    // does not match SELECT COALESCE(col, $1) and the column is reported as
    // neither grouped nor aggregated. Null is resolved in PHP instead, where
    // normalize_dtype() already treats it as full precision.
    //
    // A recordset rather than get_records_sql(): the first column is the array
    // key there, and a null dtype would key on the empty string and could
    // collide with a literal empty value.
    $rs = $DB->get_recordset_sql(
        'SELECT embed_dtype, COUNT(*) AS n
           FROM {local_ai_course_assistant_chunks}
          WHERE embedding_bin IS NOT NULL
       GROUP BY embed_dtype'
    );
    foreach ($rs as $row) {
        $vectorbytes += (int) $row->n * \local_ai_course_assistant\embedding_compat::vector_bytes(
            $rawdim,
            \local_ai_course_assistant\embedding_compat::normalize_dtype($row->embed_dtype ?? null)
        );
    }
    $rs->close();
}
$currentdtype = \local_ai_course_assistant\embedding_compat::normalize_dtype(
    (string) get_config('local_ai_course_assistant', 'embed_dtype')
);
$storageprojection = '';
if ($totalembedded > 0 && $rawdim > 0) {
    $alt = [];
    foreach (\local_ai_course_assistant\embedding_compat::DTYPES as $d) {
        if ($d === $currentdtype) {
            continue;
        }
        $alt[] = get_string('ragadmin:storage_alt_item', 'local_ai_course_assistant', [
            // Short name, not the dropdown label: the label already carries its own
            // size description, which read as a double explanation here
            // ("Reduced precision - about a quarter of the space - about 1.0 KB").
            'dtype' => get_string('settings:embed_dtype_short' . $d, 'local_ai_course_assistant'),
            'size'  => display_size(
                $totalembedded * \local_ai_course_assistant\embedding_compat::vector_bytes($rawdim, $d)
            ),
        ]);
    }
    if (!empty($alt)) {
        $storageprojection = get_string(
            'ragadmin:storage_projection',
            'local_ai_course_assistant',
            implode('; ', $alt)
        );
    }
}

// Merge indexed and active courses, deduplicated, for the per-course table.
$allcourses = $indexedcourses;
foreach ($activecourses as $ac) {
    if (!isset($allcourses[$ac->id])) {
        $ac->chunks      = 0;
        $ac->embedded    = 0;
        $ac->lastindexed = null;
        $allcourses[$ac->id] = $ac;
    }
}
uasort($allcourses, fn($a, $b) => strcmp($a->fullname, $b->fullname));

$courserows = [];
foreach ($allcourses as $c) {
    $courserows[] = [
        'id'          => (int) $c->id,
        'fullname'    => $c->fullname,
        'courseurl'   => (new moodle_url('/course/view.php', ['id' => $c->id]))->out(false),
        'chunks'      => number_format((int) $c->chunks),
        'haschunks'   => ((int) $c->chunks) > 0,
        'embedded'    => number_format((int) $c->embedded),
        'hasembedded' => ((int) $c->embedded) > 0,
        'hasindexed'  => !empty($c->lastindexed),
        'lastindexed' => !empty($c->lastindexed)
            ? userdate((int) $c->lastindexed, get_string('strftimedatetimeshort', 'langconfig'))
            : '',
    ];
}

// ── v7.4.0: embedding-model migration ───────────────────────────────────────
// The whole section is gated on a configured target model. With no target
// there is nothing to show and nothing safe to offer, and the superseded-row
// query (a self-join over the chunk table) is not worth running on every page
// load of a site that is not migrating.
$migrationtarget = embedding_migration::target();
$migration = [
    'configured' => false,
    // Shown rather than hidden: an operator who has never migrated needs to
    // know the capability exists and which setting turns it on. Hiding it is
    // how a feature becomes undiscoverable after the deploy window closes.
    'notarget'   => get_string('embedmigration:notarget', 'local_ai_course_assistant'),
    'heading'    => get_string('embedmigration:heading', 'local_ai_course_assistant'),
];
if ($migrationtarget['configured']) {
    $states = embedding_migration::course_states($migrationtarget);
    $progress = embedding_migration::progress($states);
    $statelabels = [
        embedding_migration::STATE_NOINDEX    => get_string('embedmigration:state_noindex', 'local_ai_course_assistant'),
        embedding_migration::STATE_NOTSTARTED => get_string('embedmigration:state_notstarted', 'local_ai_course_assistant'),
        embedding_migration::STATE_COMPLETE   => get_string('embedmigration:state_complete', 'local_ai_course_assistant'),
        embedding_migration::STATE_QUEUED     => get_string('embedmigration:state_queued', 'local_ai_course_assistant'),
        embedding_migration::STATE_RUNNING    => get_string('embedmigration:state_running', 'local_ai_course_assistant'),
    ];
    $migrationrows = [];
    foreach ($states as $row) {
        $state = (string) $row['state'];
        $migrationrows[] = [
            'id'       => (int) $row['courseid'],
            // Never truncated: the course name is the identity column, and an
            // eight-character truncation of one is what made a per-course
            // readout unusable in the first place.
            'fullname' => format_string($row['fullname']),
            'chunks'   => number_format((int) $row['chunks']),
            'migrated' => number_format((int) $row['migrated']),
            'state'    => $state,
            'statelabel' => $state === embedding_migration::STATE_PARTIAL
                ? get_string('embedmigration:state_partial', 'local_ai_course_assistant', (int) $row['pct'])
                : ($statelabels[$state] ?? $state),
            'complete' => $state === embedding_migration::STATE_COMPLETE,
            'pending'  => in_array($state, [
                embedding_migration::STATE_QUEUED, embedding_migration::STATE_RUNNING,
            ], true),
            'canqueue' => in_array($state, [
                embedding_migration::STATE_NOTSTARTED, embedding_migration::STATE_PARTIAL,
            ], true),
        ];
    }
    $superseded = embedding_migration::superseded_count();
    $faqmodel = embedding_migration::faq_model();
    $dimlabel = $migrationtarget['dimensions'] > 0
        ? (string) $migrationtarget['dimensions']
        : get_string('embedmigration:target_native', 'local_ai_course_assistant');
    $livedimlabel = $migrationtarget['livedimensions'] > 0
        ? (string) $migrationtarget['livedimensions']
        : get_string('embedmigration:target_native', 'local_ai_course_assistant');

    $migration = [
        'configured'  => true,
        'heading'     => get_string('embedmigration:heading', 'local_ai_course_assistant'),
        'desc'        => get_string('embedmigration:desc', 'local_ai_course_assistant'),
        'summary'     => get_string('embedmigration:target_summary', 'local_ai_course_assistant', (object) [
            'model'              => s($migrationtarget['model']),
            'provider'           => s($migrationtarget['provider']),
            'dimensions'         => s($dimlabel),
            'currentmodel'       => s($migrationtarget['livemodel']),
            'currentdimensions'  => s($livedimlabel),
        ]),
        'sameaslive'  => $migrationtarget['sameaslive']
            ? get_string('embedmigration:same_as_live', 'local_ai_course_assistant')
            : '',
        'progress'    => get_string('embedmigration:progress', 'local_ai_course_assistant', (object) [
            'done'    => $progress['done'],
            'total'   => $progress['total'],
            'pending' => $progress['pending'],
        ]),
        'allcomplete' => $progress['allcomplete']
            ? get_string(
                'embedmigration:all_complete',
                'local_ai_course_assistant',
                $migrationtarget['model']
            )
            : '',
        'rows'        => $migrationrows,
        'hasrows'     => !empty($migrationrows),
        'colcourse'   => get_string('ragadmin:col_course', 'local_ai_course_assistant'),
        'colchunks'   => get_string('ragadmin:col_chunks', 'local_ai_course_assistant'),
        'colmigrated' => get_string('embedmigration:col_migrated', 'local_ai_course_assistant'),
        'colstate'    => get_string('embedmigration:col_state', 'local_ai_course_assistant'),
        'colactions'  => get_string('ragadmin:col_actions', 'local_ai_course_assistant'),
        'queueone'    => get_string('embedmigration:queue_one', 'local_ai_course_assistant'),
        'queueall'    => get_string('embedmigration:queue_all', 'local_ai_course_assistant'),
        'queueallconfirm' => get_string('embedmigration:queue_all_confirm', 'local_ai_course_assistant'),
        'purgeheading' => get_string('embedmigration:purge_heading', 'local_ai_course_assistant'),
        'purgedesc'   => get_string(
            'embedmigration:purge_desc',
            'local_ai_course_assistant',
            number_format($superseded)
        ),
        'haspurge'    => $superseded > 0,
        'purgenone'   => get_string('embedmigration:purge_none', 'local_ai_course_assistant'),
        'purge'       => get_string('embedmigration:purge', 'local_ai_course_assistant'),
        'purgeconfirm' => get_string('embedmigration:purge_confirm', 'local_ai_course_assistant'),
        'faqheading'  => get_string('embedmigration:faq_heading', 'local_ai_course_assistant'),
        'faqstate'    => $faqmodel === null
            ? get_string('embedmigration:faq_none', 'local_ai_course_assistant')
            : get_string('embedmigration:faq_state', 'local_ai_course_assistant', $faqmodel),
        // Stale = embedded with something other than the model retrieval is
        // querying with. The symptom is silent: the retriever skips the rows
        // and the prompt falls back to injecting the FAQ inline.
        'faqstale'    => ($faqmodel !== null && $faqmodel !== $migrationtarget['livemodel'])
            ? get_string('embedmigration:faq_stale', 'local_ai_course_assistant')
            : '',
        'faqreembed'  => get_string('embedmigration:faq_reembed', 'local_ai_course_assistant'),
    ];
}

$templatedata = [
    'backurl'   => $settingsurl->out(false),
    'backlabel' => get_string('ragadmin:back_to_settings', 'local_ai_course_assistant'),
    'sourcestitle' => get_string('ragadmin:content_sources', 'local_ai_course_assistant'),
    'sourcesdesc'  => get_string(
        'ragadmin:content_sources_desc',
        'local_ai_course_assistant',
        (new moodle_url(
            '/admin/settings.php',
            ['section' => 'local_ai_course_assistant_general'],
            'sec-content'
        ))->out()
    ),
    'statusrows' => array_values($statusrows),
    'storageprojection' => $storageprojection,
    'statcards'  => [
        [
            'value' => count($indexedcourses),
            'label' => get_string('ragadmin:stat_courses_indexed', 'local_ai_course_assistant'),
        ],
        [
            'value' => number_format($totalchunks),
            'label' => get_string('ragadmin:stat_total_chunks', 'local_ai_course_assistant'),
        ],
        [
            'value' => number_format($totalembedded),
            'label' => get_string('ragadmin:stat_embedded_chunks', 'local_ai_course_assistant'),
        ],
        [
            'value' => $vectorbytes > 0 ? display_size($vectorbytes) : '—',
            'label' => get_string('ragadmin:stat_vector_storage', 'local_ai_course_assistant'),
        ],
        [
            'value' => count($activecourses),
            'label' => get_string('ragadmin:stat_active_courses', 'local_ai_course_assistant'),
        ],
    ],
    'posturl' => $pageurl->out(false),
    'sesskey' => sesskey(),
    'reindexall'        => get_string('ragadmin:reindexall', 'local_ai_course_assistant'),
    'reindexallconfirm' => get_string('ragadmin:reindexall_confirm', 'local_ai_course_assistant'),
    'reindexalldesc'    => get_string('ragadmin:reindexall_desc', 'local_ai_course_assistant'),
    'indexstatus'   => get_string('ragadmin:index_status', 'local_ai_course_assistant'),
    'nocourses'     => get_string('ragadmin:no_courses', 'local_ai_course_assistant'),
    'hascourses'    => !empty($courserows),
    'colcourse'     => get_string('ragadmin:col_course', 'local_ai_course_assistant'),
    'colchunks'     => get_string('ragadmin:col_chunks', 'local_ai_course_assistant'),
    'colembedded'   => get_string('ragadmin:col_embedded', 'local_ai_course_assistant'),
    'collastindexed' => get_string('ragadmin:col_lastindexed', 'local_ai_course_assistant'),
    'colactions'    => get_string('ragadmin:col_actions', 'local_ai_course_assistant'),
    'never'         => get_string('ragadmin:never', 'local_ai_course_assistant'),
    'reindex'       => get_string('ragadmin:reindex', 'local_ai_course_assistant'),
    'clearindex'    => get_string('ragadmin:deleteindex', 'local_ai_course_assistant'),
    'clearconfirm'  => get_string('ragadmin:deleteindex_confirm', 'local_ai_course_assistant'),
    'courses'       => $courserows,
    'migration'     => $migration,
];

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('ragadmin:title', 'local_ai_course_assistant'));

if (!$ragenabled) {
    echo $OUTPUT->notification(
        get_string('ragadmin:rag_disabled_notice', 'local_ai_course_assistant'),
        \core\output\notification::NOTIFY_WARNING
    );
}

echo $OUTPUT->render_from_template('local_ai_course_assistant/rag_admin', $templatedata);
echo $OUTPUT->footer();
