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
 * LLM cost-and-quality optimizer.
 *
 * For each capability (chat, voice, rag, analytics) and each provider+model
 * pair the site has used recently, compute:
 *   - $/request (mean cost per assistant message, from token_cost_manager)
 *   - satisfaction (thumbs-up rate from msg_ratings, with confidence filtering)
 *   - sample count (for sparse-data warnings)
 *
 * Rank providers by a simple composite: cost weight + quality weight.
 * Admins can tune the weights in settings; defaults prioritize cost (0.7)
 * over quality (0.3) because cost signals are more reliable than sparse
 * ratings.
 *
 * Monthly spend projection: trailing 30 days at current mix, scaled to 30
 * elapsed days. Never projects from less than 7 days of data — that avoids
 * wild extrapolation on fresh installs.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025-2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class llm_optimizer {

    /** Minimum rated messages for a quality signal to be trusted. */
    private const MIN_RATED_SAMPLE = 30;

    /** Minimum cost-sample count before we trust the mean $/request. */
    private const MIN_COST_SAMPLE = 30;

    /** Minimum days of data before projecting monthly spend. */
    private const MIN_PROJECTION_DAYS = 7;

    /**
     * Build a ranked list of provider+model options per capability plus
     * a monthly spend projection for the current site mix.
     *
     * @return array {
     *   'projected_monthly' => float USD,
     *   'projection_confidence' => 'low'|'medium'|'high',
     *   'projection_days' => int,
     *   'capabilities' => array of ['capability' => str, 'rankings' => array, 'active' => str]
     * }
     */
    public static function recommend(): array {
        $out = [
            'projected_monthly' => 0.0,
            'projection_confidence' => 'low',
            'projection_days' => 0,
            'capabilities' => [],
        ];

        $projection = self::project_monthly_spend();
        $out = array_merge($out, $projection);

        foreach (['chat', 'voice', 'rag', 'analytics'] as $cap) {
            $out['capabilities'][] = [
                'capability' => $cap,
                'rankings'   => self::rank_providers($cap),
                'active'     => self::active_provider_for($cap),
            ];
        }

        return $out;
    }

    /**
     * Rank providers for a capability by composite (cost, quality) score.
     *
     * @param string $capability
     * @return array Each entry: {
     *   'provider', 'model', 'cost_per_request', 'satisfaction', 'sample',
     *   'confidence' => 'low'|'medium'|'high', 'composite_score' => float
     * }
     */
    private static function rank_providers(string $capability): array {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/local/ai_course_assistant/classes/token_cost_manager.php');

        $since = time() - 30 * 86400;
        $capclause = self::capability_clause($capability);

        $rows = $DB->get_records_sql(
            "SELECT " . $DB->sql_concat('m.provider', "'|'", 'm.model_name') . " AS id,
                    m.provider AS provider,
                    m.model_name AS model,
                    COUNT(m.id) AS sample,
                    SUM(COALESCE(m.prompt_tokens, 0)) AS prompt,
                    SUM(COALESCE(m.completion_tokens, 0)) AS completion
               FROM {local_ai_course_assistant_msgs} m
              WHERE m.role = 'assistant' AND m.model_name IS NOT NULL
                AND m.timecreated >= :since
                AND {$capclause}
              GROUP BY m.provider, m.model_name
             HAVING COUNT(m.id) >= :minsample",
            ['since' => $since, 'minsample' => self::MIN_COST_SAMPLE]
        );

        $options = [];
        foreach ($rows as $r) {
            $cost = token_cost_manager::estimate_cost(
                (string) $r->model,
                (int) $r->prompt,
                (int) $r->completion
            );
            if ($cost === null || $r->sample == 0) {
                continue;
            }
            $costperreq = (float) $cost / (int) $r->sample;
            $sat = self::satisfaction_rate((string) $r->provider, (string) $r->model, $since);
            $conf = self::confidence_for_sample((int) $r->sample, $sat['rated']);
            $options[] = [
                'provider'          => (string) $r->provider,
                'model'             => (string) $r->model,
                'cost_per_request'  => $costperreq,
                'satisfaction'      => $sat['rate'],
                'sample'            => (int) $r->sample,
                'rated'             => $sat['rated'],
                'confidence'        => $conf,
            ];
        }

        if (empty($options)) {
            return [];
        }

        // Normalize: lowest cost → 1.0 score on cost axis. Highest satisfaction → 1.0.
        $mincost = min(array_column($options, 'cost_per_request'));
        $maxcost = max(array_column($options, 'cost_per_request'));
        $costrange = max($maxcost - $mincost, 0.000001);
        $costweight = (float) (get_config('local_ai_course_assistant', 'opt_cost_weight') ?: 0.7);
        $qweight = (float) (get_config('local_ai_course_assistant', 'opt_quality_weight') ?: 0.3);
        // Normalise weights.
        $wsum = max($costweight + $qweight, 0.0001);
        $costweight /= $wsum;
        $qweight /= $wsum;

        foreach ($options as &$o) {
            $coststar = 1.0 - (($o['cost_per_request'] - $mincost) / $costrange);
            // If we have no rating data, assume neutral satisfaction (0.5).
            $qstar = $o['satisfaction'] !== null ? $o['satisfaction'] : 0.5;
            $o['composite_score'] = $costweight * $coststar + $qweight * $qstar;
        }
        unset($o);

        usort($options, function($a, $b) {
            return $b['composite_score'] <=> $a['composite_score'];
        });

        return $options;
    }

    /**
     * Thumbs-up rate for a provider+model pair, trailing 30 days.
     * Returns 'rated' count so callers can decide if the signal is trustworthy.
     *
     * @param string $provider
     * @param string $model
     * @param int $since
     * @return array ['rate' => float|null (0..1), 'rated' => int]
     */
    private static function satisfaction_rate(string $provider, string $model, int $since): array {
        global $DB;
        $row = $DB->get_record_sql(
            "SELECT COUNT(r.id) AS rated,
                    SUM(CASE WHEN r.rating > 0 THEN 1 ELSE 0 END) AS up
               FROM {local_ai_course_assistant_msg_ratings} r
               JOIN {local_ai_course_assistant_msgs} m ON m.id = r.messageid
              WHERE m.provider = :p
                AND m.model_name = :mod
                AND m.timecreated >= :since
                AND m.role = 'assistant'",
            ['p' => $provider, 'mod' => $model, 'since' => $since]
        );
        $rated = (int) ($row->rated ?? 0);
        if ($rated < self::MIN_RATED_SAMPLE) {
            return ['rate' => null, 'rated' => $rated];
        }
        return ['rate' => (float) $row->up / max($rated, 1), 'rated' => $rated];
    }

    /**
     * Confidence tier for a sample.
     *
     * @param int $totalsamples
     * @param int $ratedsamples
     * @return string
     */
    private static function confidence_for_sample(int $totalsamples, int $ratedsamples): string {
        if ($totalsamples < 50 || $ratedsamples < self::MIN_RATED_SAMPLE) {
            return 'low';
        }
        if ($totalsamples < 250 || $ratedsamples < 100) {
            return 'medium';
        }
        return 'high';
    }

    /**
     * Projected monthly spend based on trailing 30-day cost.
     *
     * @return array ['projected_monthly' => float, 'projection_confidence' => str, 'projection_days' => int]
     */
    private static function project_monthly_spend(): array {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/local/ai_course_assistant/classes/token_cost_manager.php');

        $since = time() - 30 * 86400;
        $earliest = (int) ($DB->get_field_sql(
            "SELECT MIN(timecreated)
               FROM {local_ai_course_assistant_msgs}
              WHERE role = 'assistant' AND timecreated >= :since",
            ['since' => $since]) ?: time());
        $days = max(1, (int) floor((time() - $earliest) / 86400));

        if ($days < self::MIN_PROJECTION_DAYS) {
            return ['projected_monthly' => 0.0, 'projection_confidence' => 'low', 'projection_days' => $days];
        }

        $rows = $DB->get_records_sql(
            "SELECT m.model_name AS model,
                    SUM(COALESCE(m.prompt_tokens, 0))     AS prompt,
                    SUM(COALESCE(m.completion_tokens, 0)) AS completion
               FROM {local_ai_course_assistant_msgs} m
              WHERE m.role = 'assistant' AND m.model_name IS NOT NULL AND m.timecreated >= :since
              GROUP BY m.model_name",
            ['since' => $since]
        );

        $windowcost = 0.0;
        foreach ($rows as $r) {
            $c = token_cost_manager::estimate_cost((string) $r->model, (int) $r->prompt, (int) $r->completion);
            if ($c !== null) {
                $windowcost += (float) $c;
            }
        }

        // Scale to 30-day period. If we have < 30 days of data, extrapolate.
        $perday = $windowcost / $days;
        $monthly = $perday * 30;

        $confidence = $days >= 21 ? 'high' : ($days >= 14 ? 'medium' : 'low');
        return [
            'projected_monthly' => $monthly,
            'projection_confidence' => $confidence,
            'projection_days' => $days,
        ];
    }

    /**
     * Which provider label is currently active for a capability.
     *
     * @param string $capability
     * @return string
     */
    private static function active_provider_for(string $capability): string {
        if ($capability === 'voice') {
            $lbl = get_config('local_ai_course_assistant', 'voice_active_realtime') ?: '';
            return (string) ($lbl ?: (get_config('local_ai_course_assistant', 'realtime_apikey') ? 'openai' : '(legacy)'));
        }
        if ($capability === 'rag') {
            return (string) (get_config('local_ai_course_assistant', 'embed_provider') ?: 'openai');
        }
        // chat, analytics both use the primary provider.
        return (string) (get_config('local_ai_course_assistant', 'provider') ?: 'openai');
    }

    /**
     * Capability → SQL fragment over interaction_type. Mirrors spend_guard::capability_sql.
     *
     * @param string $capability
     * @return string
     */
    private static function capability_clause(string $capability): string {
        switch ($capability) {
            case 'chat':
                return "(m.interaction_type IS NULL OR m.interaction_type IN ('chat','quiz',''))";
            case 'voice':
                return "m.interaction_type IN ('voice','openai_tts','xai_tts','openai_whisper','openai_stt','xai_stt')";
            case 'rag':
                return "m.interaction_type IN ('embedding','embed')";
            case 'analytics':
                return "m.interaction_type = 'meta'";
            default:
                return '1=1';
        }
    }
}
