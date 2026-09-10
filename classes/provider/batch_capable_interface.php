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

namespace local_ai_course_assistant\provider;

/**
 * Offline batch submission contract, for callers nobody is waiting on.
 *
 * SEPARATE from provider_interface on purpose. provider_interface is
 * implemented by every provider class in the plugin plus failover_chain, and
 * batch is supported by exactly one API shape today; widening the base
 * interface would force nine classes to carry a method that throws. A caller
 * therefore asks `$llm instanceof batch_capable_interface && $llm->supports_batch()`
 * and falls back to the synchronous call when the answer is no. That fallback
 * is not an edge case, it is the normal path for most configurations.
 *
 * WHY supports_batch() exists as well as the instanceof check: the interface is
 * implemented on {@see openai_compatible_provider}, which is the parent of ten
 * concrete providers pointing at ten different hosts. Implementing the methods
 * says "this class knows the OpenAI Batch wire format". supports_batch() says
 * "and the endpoint this instance is configured against actually serves it".
 * Only the second is safe to act on: submitting a JSONL file to a host that has
 * no /v1/batches route returns a 404, and a Learning Radar report that 404s at
 * submit time is a report nobody ever receives.
 *
 * LIFECYCLE. Batch is submit / poll / collect, and the turnaround is up to 24
 * hours, so the three steps land in three different cron runs and in three
 * different PHP processes:
 *
 *   1. submit_batch()  -> a provider batch id. Persist it. The process ends.
 *   2. fetch_batch()   -> status 'pending' on every poll until it is not.
 *   3. fetch_batch()   -> status 'completed' plus per-request results.
 *
 * Nothing may be held in memory between those steps, which is why the id is a
 * plain string and the results are addressed by a caller-chosen custom_id
 * rather than by array position.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface batch_capable_interface {
    /** Batch accepted and still being processed upstream. */
    public const BATCH_PENDING = 'pending';

    /** Batch finished; every result is available. */
    public const BATCH_COMPLETED = 'completed';

    /** Batch failed as a unit (bad input file, auth, quota). */
    public const BATCH_FAILED = 'failed';

    /** Batch was cancelled, by us or in the vendor console. */
    public const BATCH_CANCELLED = 'cancelled';

    /** Batch ran past its completion window without finishing. */
    public const BATCH_EXPIRED = 'expired';

    /**
     * Can THIS instance submit a batch, given the endpoint it is pointed at?
     *
     * Callers must treat false as "use the synchronous path", never as an
     * error: a site running an OpenAI-compatible gateway, a local ollama, or
     * Anthropic is a perfectly normal site that simply has no batch tier.
     *
     * @return bool
     */
    public function supports_batch(): bool;

    /**
     * Submit one or more chat completions for offline processing.
     *
     * @param array<string, array{systemprompt: string, messages: array, options?: array}> $requests
     *        Keyed by custom_id. The key is echoed back by fetch_batch(), and is
     *        how a multi-request batch is demultiplexed on collection.
     * @return string Provider batch id, to be persisted and passed to fetch_batch().
     * @throws \moodle_exception When the submission itself fails.
     */
    public function submit_batch(array $requests): string;

    /**
     * Poll a batch and, once it has finished, collect its results.
     *
     * Returns a status of BATCH_PENDING for as long as the batch is queued or
     * running. `results` is only populated on BATCH_COMPLETED, and is keyed by
     * the same custom_ids submit_batch() was given.
     *
     * Each result carries `usage` in the canonical shape
     * {@see openai_compatible_provider::shape_usage()} produces, so the spend
     * pipeline records what the provider actually charged rather than a
     * character-count approximation.
     *
     * @param string $batchid Id returned by submit_batch().
     * @return array{status: string,
     *               results: array<string, array{content: ?string, usage: ?array, error: ?string}>,
     *               error: ?string}
     * @throws \moodle_exception On a transport or authentication failure. A batch
     *         that merely has not finished is NOT an exception.
     */
    public function fetch_batch(string $batchid): array;

    /**
     * Ask the provider to cancel a batch that has not finished.
     *
     * Best effort: a batch that already completed cannot be cancelled, and the
     * caller must not treat false as fatal.
     *
     * @param string $batchid
     * @return bool True when the provider accepted the cancellation.
     */
    public function cancel_batch(string $batchid): bool;
}
