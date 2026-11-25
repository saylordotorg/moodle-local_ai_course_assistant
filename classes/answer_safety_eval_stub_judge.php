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

use local_ai_course_assistant\provider\provider_interface;

/**
 * The offline judge answer_safety_eval uses unless --real is given: no network, no key, no spend.
 *
 * It answers the claim checker's request with one claim per sentence of the answer (up to the checker's limit),
 * each 'supported' by passage 1 when passages were sent and 'uncheckable' otherwise, and never flags the question.
 * So it measures the judge's plumbing and the size of the request, NOT the judge's judgement: its false-alarm rate is
 * zero by construction. Token counts are estimates (token_estimator, English rate) of what was sent and returned, and
 * the reported model is the price model, so the cost is an estimate at that model's list price.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class answer_safety_eval_stub_judge implements provider_interface {
    /** @var string The model the usage names, for pricing. */
    private string $pricemodel;

    /** @var array|null */
    private ?array $usage = null;

    /**
     * Constructor.
     *
     * @param string $pricemodel the model whose list price the estimate uses
     */
    public function __construct(string $pricemodel = 'gpt-4o-mini') {
        $this->pricemodel = $pricemodel;
    }

    /**
     * The model the cost estimate is priced at.
     *
     * @return string
     */
    public function price_model(): string {
        return $this->pricemodel;
    }

    /**
     * Answer the judge request.
     *
     * @param string $systemprompt
     * @param array $messages
     * @param array $options
     * @return string
     */
    public function chat_completion(string $systemprompt, array $messages, array $options = []): string {
        $user = '';
        foreach ($messages as $m) {
            $user .= (string) ($m['content'] ?? '') . "\n";
        }
        $haspassages = str_contains($user, '<<<BEGIN COURSE PASSAGE');
        $answer = self::answer_from($user);
        $claims = [];
        foreach (preg_split('/(?<=[.!?])\s+/u', trim($answer), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $sentence) {
            if (count($claims) >= claim_checker::MAX_CLAIMS) {
                break;
            }
            $claims[] = ['text' => $sentence, 'type' => 'general',
                'status' => $haspassages ? 'supported' : 'uncheckable', 'evidence' => $haspassages ? 1 : 0];
        }
        $response = (string) json_encode(['claims' => $claims, 'question_wellbeing' => false]);
        $this->usage = [
            'prompt_tokens' => token_estimator::estimate_tokens($systemprompt . "\n" . $user, 'en'),
            'completion_tokens' => token_estimator::estimate_tokens($response, 'en'),
            'model' => $this->pricemodel,
        ];
        return $response;
    }

    /**
     * The answer block of the checker's user message (the whole message when the block is not found).
     *
     * @param string $user
     * @return string
     */
    private static function answer_from(string $user): string {
        if (preg_match('/<<<BEGIN TUTOR ANSWER>>>\s*(.*?)\s*<<<END TUTOR ANSWER>>>/su', $user, $m)) {
            return $m[1];
        }
        return $user;
    }

    /**
     * Streaming is not used by the claim checker; answers in one piece.
     *
     * @param string $systemprompt
     * @param array $messages
     * @param callable $callback
     * @param array $options
     * @return void
     */
    public function chat_completion_stream(string $systemprompt, array $messages, callable $callback,
            array $options = []): void {
        $callback($this->chat_completion($systemprompt, $messages, $options));
    }

    /**
     * Estimated usage of the last call.
     *
     * @return array|null
     */
    public function get_last_token_usage(): ?array {
        return $this->usage;
    }

    /**
     * Always a complete answer.
     *
     * @return string|null
     */
    public function get_last_finish_reason(): ?string {
        return $this->usage === null ? null : 'stop';
    }
}
