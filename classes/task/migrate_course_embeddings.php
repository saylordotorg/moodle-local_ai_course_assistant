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

namespace local_ai_course_assistant\task;

use local_ai_course_assistant\content_indexer;
use local_ai_course_assistant\embedding_migration;

/**
 * Adhoc task: re-embed ONE course against the migration target model.
 *
 * One task per course is the whole design (see embedding_migration for why):
 * the work parallelizes across cron passes, a failure retries one course rather
 * than the corpus, and progress stays visible per course.
 *
 * The task re-embeds in shadow mode, so the vectors currently serving retrieval
 * are untouched until an administrator changes the live embedding settings. It
 * never changes those settings itself — deciding when a corpus is ready to
 * switch over is not a decision a cron task should make.
 *
 * Custom data: {courseid, model, provider, dimensions, queuedby}. The target is
 * carried on the task rather than re-read from config at run time, so a target
 * changed after queueing cannot make one course land in a third embedding space
 * that neither the old nor the new settings can read.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class migrate_course_embeddings extends \core\task\adhoc_task {
    /**
     * Task name for the scheduled-task and queue admin screens.
     *
     * @return string
     */
    public function get_name(): string {
        return \local_ai_course_assistant\branding::str('task:migrate_course_embeddings');
    }

    /**
     * Re-embed the course.
     *
     * @return void
     */
    public function execute() {
        global $DB;

        \core_php_time_limit::raise(1800);

        $data = (array) $this->get_custom_data();
        $courseid = (int) ($data['courseid'] ?? 0);
        $model = trim((string) ($data['model'] ?? ''));
        $provider = trim((string) ($data['provider'] ?? ''));
        $dimensions = (int) ($data['dimensions'] ?? 0);

        if ($courseid <= 0 || $model === '') {
            mtrace('  embedding migration: no courseid or no target model in custom data; nothing to do.');
            return;
        }
        if (!$DB->record_exists('course', ['id' => $courseid])) {
            // A deleted course is not a failure. Throwing would make Moodle
            // retry this task with backoff, forever, over a course that will
            // never come back.
            mtrace("  embedding migration: course {$courseid} no longer exists; skipping.");
            return;
        }

        $target = [
            'configured' => true,
            'model'      => $model,
            'provider'   => $provider,
            'dimensions' => $dimensions,
            'apikey'     => trim((string) (get_config(
                'local_ai_course_assistant',
                embedding_migration::SETTING_APIKEY
            ) ?: '')),
        ];

        // Built before any work, so a bad key or an unknown provider fails the
        // task loudly on the first course instead of half-migrating the site.
        // The exception propagates: this IS worth a Moodle retry.
        $embedprovider = embedding_migration::target_provider($target);
        $actualmodel = $embedprovider->get_model();
        if ($actualmodel !== $model) {
            // The provider resolved a different model than the one this task
            // was queued for (an empty model falling back to a provider
            // default, for instance). Writing those vectors would record them
            // under a model nobody is migrating to, and the page would report
            // the course as still unmigrated forever.
            throw new \moodle_exception(
                'chat:error_notconfigured',
                'local_ai_course_assistant',
                '',
                null,
                "embedding migration for course {$courseid} expected model \"{$model}\" but the "
                    . "provider resolved \"{$actualmodel}\"; not writing vectors under a model "
                    . 'that is not the migration target'
            );
        }

        mtrace("  embedding migration: course {$courseid} -> {$model}"
            . ($dimensions > 0 ? " at {$dimensions} dimensions" : ' at the native width'));

        // force = false on purpose: content_indexer's skip test compares the
        // stored embed_model as well as the content hash, so chunks already
        // carrying the target model are skipped and only the rest are embedded.
        // That is what makes a re-queued or retried course resume instead of
        // billing the whole course again.
        $stats = content_indexer::index_course($courseid, false, [
            'provider' => $embedprovider,
            'shadow'   => true,
        ]);

        if (!empty($stats['fatal'])) {
            throw new \moodle_exception(
                'chat:error_generic',
                'local_ai_course_assistant',
                '',
                null,
                "embedding migration for course {$courseid} could not start: " . $stats['fatal']
            );
        }

        mtrace(sprintf(
            '    embedded=%d, skipped=%d, errors=%d%s',
            (int) ($stats['indexed'] ?? 0),
            (int) ($stats['skipped'] ?? 0),
            (int) ($stats['errors'] ?? 0),
            !empty($stats['cap_blocked']) ? ' (stopped by the RAG spend cap)' : ''
        ));

        if (!empty($stats['embed_error'])) {
            // A per-module embedding failure leaves the course partially
            // migrated, which the page shows as a percentage. Throw so Moodle
            // retries this one course: the alternative is a course that reads
            // as "87% migrated" and never moves again, with the reason only in
            // a cron log nobody reads.
            throw new \moodle_exception(
                'chat:error_generic',
                'local_ai_course_assistant',
                '',
                null,
                "embedding migration for course {$courseid} hit an embedding error: "
                    . $stats['embed_error']
            );
        }
        if (!empty($stats['cap_blocked'])) {
            throw new \moodle_exception(
                'chat:error_generic',
                'local_ai_course_assistant',
                '',
                null,
                "embedding migration for course {$courseid} stopped at the RAG spend cap; "
                    . 'raise the cap or wait for the period to roll over, then re-queue'
            );
        }
    }
}
