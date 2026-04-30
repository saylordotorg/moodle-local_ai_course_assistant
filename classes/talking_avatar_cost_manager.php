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
 * Per-minute cost manager for talking-avatar streaming sessions.
 *
 * Mirrors {@see token_cost_manager} but uses USD-per-minute rates instead
 * of USD-per-1M-tokens. Bundled defaults reflect 2026-04-30 list pricing
 * for D-ID, HeyGen, Tavus, and Synthesia Agents (verify against vendor
 * dashboards for the institution's actual contracted rate). Admins
 * override individual rates via the `avatar_rate_card_overrides` JSON
 * setting; format mirrors the LLM rate-card overrides.
 *
 * Provider keys: did, heygen, tavus, synthesia.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class talking_avatar_cost_manager {

    /**
     * Bundled USD per streaming minute by provider key. List-price midpoints
     * as of 2026-04-30 — institutions on enterprise contracts will see
     * lower effective rates and should set the override JSON.
     *
     * @var array<string, float>
     */
    private static array $rate_cards = [
        'did'       => 0.30,
        'heygen'    => 0.50,
        'tavus'     => 0.30,
        'synthesia' => 0.40,
    ];

    /**
     * USD cost for a session of the given provider and duration in seconds.
     * Returns 0.0 for unknown providers (callers should not silently bill
     * an unknown vendor a default rate).
     *
     * @param string $provider Provider key.
     * @param int $durationsec Streaming duration in seconds.
     * @return float
     */
    public static function cost_for_session(string $provider, int $durationsec): float {
        $rates = self::get_effective_rate_cards();
        if (!isset($rates[$provider])) {
            return 0.0;
        }
        $minutes = max(0, $durationsec) / 60.0;
        return round($minutes * $rates[$provider], 4);
    }

    /**
     * Per-minute USD rate for a provider, after admin overrides.
     *
     * @param string $provider
     * @return float|null Null when unknown.
     */
    public static function rate_for(string $provider): ?float {
        $rates = self::get_effective_rate_cards();
        return $rates[$provider] ?? null;
    }

    /**
     * All known per-minute USD rates, after admin overrides. Useful for
     * the analytics surface and for the rate-card admin preview.
     *
     * @return array<string, float>
     */
    public static function get_all_rates(): array {
        return self::get_effective_rate_cards();
    }

    /**
     * Format a USD cost as `$0.0123` (4dp under one cent, 2dp otherwise).
     *
     * @param float|null $cost
     * @return string
     */
    public static function format_cost(?float $cost): string {
        if ($cost === null) {
            return '—';
        }
        return $cost < 0.01
            ? '$' . number_format($cost, 4)
            : '$' . number_format($cost, 2);
    }

    /**
     * Merge admin overrides over the bundled rate card. Override JSON shape:
     * <pre>{ "did": 0.18, "heygen": 0.30 }</pre>
     * Malformed JSON is ignored at runtime so a bad paste does not break
     * cost estimation.
     *
     * @return array<string, float>
     */
    private static function get_effective_rate_cards(): array {
        $base = self::$rate_cards;
        $rawjson = (string) (get_config('local_ai_course_assistant', 'avatar_rate_card_overrides') ?: '');
        if (trim($rawjson) === '') {
            return $base;
        }
        $decoded = json_decode($rawjson, true);
        if (!is_array($decoded)) {
            return $base;
        }
        foreach ($decoded as $provider => $rate) {
            if (!is_string($provider) || !is_numeric($rate)) {
                continue;
            }
            $base[strtolower(trim($provider))] = (float) $rate;
        }
        return $base;
    }
}
