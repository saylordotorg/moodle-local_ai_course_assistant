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
 * Cache definitions for local_ai_course_assistant.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025-2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$definitions = [
    // Per-session UI toggles (admin "view as student", reveal-real-names).
    // MODE_SESSION replaces ad-hoc $_SESSION superglobal use.
    'uistate' => [
        'mode' => cache_store::MODE_SESSION,
        'simplekeys' => true,
        'simpledata' => true,
    ],
    // Rate limiting cache.
    'ratelimit' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => false,
        // INVARIANT: this ttl must be >= the longest window passed to
        // rate_limiter::is_rate_limited(). rate_limiter stores the whole sliding
        // window as ONE entry (window_start + count) and restarts the window on a
        // cache miss, so an entry that expires mid-window silently resets the
        // count. At ttl=120 the soapbox_stt bucket (12 per 600s) was enforcing
        // 12 per 120s -- five times its intended allowance -- with no error.
        // The worst case was NOT soapbox: sse.php limits Zendesk escalation to
        // 2 tickets per learner per 3600s, and each ticket ships the learner's
        // name, email and full transcript to an external desk. At ttl=120 that
        // control was enforcing 2 per 120s -- up to 60 tickets an hour. It is the
        // v7.0.5 anti-abuse fix, and it was silently 30x weaker than written.
        // Longest window in the codebase today is 3600s (escalation); the ttl is
        // set above it rather than equal to it so an early eviction under memory
        // pressure cannot clip the tail of a window.
        // tests/rate_limit_ttl_test.php pins this invariant.
        'ttl' => 7200, // 2 hours: headroom over every window in use.
    ],
    // System prompt cache (per-course).
    'systemprompt' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => false,
        'ttl' => 3600, // 1 hour.
        'invalidationevents' => [
            'changesincourse',
        ],
    ],
    // Remote config cache (fetched from GitHub-hosted JSON, 1 hour TTL).
    'remoteconfig' => [
        'mode'       => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'ttl'        => 3600, // 1 hour.
    ],
    // Spend-guard per-period spend totals. Short TTL: the cached value is only
    // consulted on the hot path (every LLM call). 60s is a reasonable
    // accuracy-vs-performance trade, and our thresholds are coarse (80/95/100%).
    'spend' => [
        'mode'       => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => false,
        'ttl'        => 60,
    ],
    // v7.4.8: per-course RAG vector index, stored as ONE concatenated binary
    // blob plus parallel metadata arrays -- deliberately NOT as decoded float
    // arrays. Measured on the dev fleet against a 2,020-chunk course at 2048
    // dimensions: reading the vectors out of the database costs ~4,380 ms and
    // decoding them ~230 ms, so the database read is the entire cost. But
    // serializing the DECODED arrays for a cache costs ~3,580 ms and 117 MB,
    // which is slower than the read it was meant to avoid. The blob form
    // serializes in ~6 ms at 16 MB and reads back in ~7 ms, leaving only the
    // ~210 ms decode on a warm hit.
    //
    // simpledata is false: the value is a nested array holding a binary string.
    // TTL bounds how long an entry can survive a missed invalidation; staleness
    // is otherwise handled by the per-course version counter that
    // rag_retriever::flush_cache() increments, so a reindex on one web node is
    // seen by every other node. The old per-process static could not do that.
    //
    // That cross-node guarantee holds only for a SHARED store. The default file
    // store in shared moodledata qualifies; APCu, a documented mapping for
    // application caches, does not, and on such a site both the generation bump
    // and purge_by_definition() are node-local -- which would make per-node
    // staleness worse than the old static, not better, because the window grows
    // from one page load to this TTL. Sites mapping this definition to APCu
    // should map it elsewhere or shorten the TTL. Note also that stores cap item
    // size (memcached at 1 MB by default) well below MAX_CACHED_INDEX_BYTES; a
    // rejected set() is reported via debugging() rather than passing silently.
    'vectors' => [
        'mode'       => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => false,
        'ttl'        => 86400, // 24 hours.
    ],
    // v5.5.0: per-provider failover circuit state. Stores the timestamp at which
    // a label's circuit was opened (after a failed call). Lookups treat an
    // open circuit as "skip this provider until TTL elapses." TTL of 900s
    // matches the 15-minute back-off in failover_chain. Keyed by failover
    // label (e.g. "fireworks-llama8b") so the same provider used by
    // multiple groups shares circuit state.
    'failover_circuit' => [
        'mode'       => cache_store::MODE_APPLICATION,
        // simplekeys is false because failover labels routinely contain hyphens
        // (e.g. "fireworks-llama8b"). Moodle's simple-key validator only allows
        // [a-zA-Z0-9_], so labels with hyphens would trigger a coding exception.
        'simplekeys' => false,
        'simpledata' => false,
        'ttl'        => 900,
    ],
    // v7.5.3: the learner's pooled program-outcome attainment, per user per course.
    //
    // Why a cache at all. Reading it walks every course where the learner holds a
    // current result and builds a release-gated report for each, which is roughly
    // 22 queries per contributing course as measured on a seeded fixture. That is
    // fine for one course and is linear in a degree learner's history, and it
    // would run on every open of the Progress tab.
    //
    // Why a short TTL rather than event invalidation. The underlying figures move
    // when a batch calculation runs upstream, which this plugin is not told about
    // and has no business subscribing to. Five minutes is well inside the interval
    // at which attainment actually changes, and the panel is a view of someone
    // else's system of record rather than the record itself: a learner seeing a
    // figure five minutes late is not wrong in any way they could act on.
    //
    // Keys are "userid_courseid". simpledata is false because the value is a
    // nested array of programs and outcomes.
    'outcomesattainment' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => false,
        'ttl' => 300,
    ],
];
