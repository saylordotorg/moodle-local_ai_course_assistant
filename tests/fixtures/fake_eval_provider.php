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
 * Test fixture: a scripted provider for the automatic evaluation tests.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_course_assistant;

use local_ai_course_assistant\provider\provider_interface;

/**
 * A provider that answers golden prompts, jailbreak probes and judge calls by script.
 *
 * Behaviour per instance: `quality` is the rubric total the judge gives its
 * answers, `tokens` the completion tokens per answer, `leak` makes it recite
 * the system prompt on extraction probes, `fail` makes every call throw, and
 * `truncate` the share of golden answers that end at the length limit.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fake_eval_provider implements provider_interface {
    /** @var array<string, array> Behaviour per model id. */
    public static array $behaviour = [];

    /** @var array<int, string> Every call: "model:kind". */
    public static array $calls = [];

    /** @var string */
    private string $model;

    /** @var array|null */
    private ?array $usage = null;

    /** @var string|null */
    private ?string $finish = null;

    /** @var int Golden answers given, for the truncation share. */
    private int $answers = 0;

    /**
     * Constructor.
     *
     * @param string $model
     */
    public function __construct(string $model) {
        $this->model = $model;
    }

    /**
     * Reset the script.
     *
     * @return void
     */
    public static function reset(): void {
        self::$behaviour = [];
        self::$calls = [];
    }

    /**
     * What this model does.
     *
     * @return array
     */
    private function b(): array {
        return (self::$behaviour[$this->model] ?? []) + ['quality' => 12, 'tokens' => 100, 'leak' => false,
            'fail' => false, 'truncate' => 0.0, 'judge' => false];
    }

    /**
     * Non-streaming: judge calls and jailbreak probes.
     *
     * @param string $systemprompt
     * @param array $messages
     * @param array $options
     * @return string
     */
    public function chat_completion(string $systemprompt, array $messages, array $options = []): string {
        $b = $this->b();
        $this->finish = 'stop';
        if ($b['judge']) {
            self::$calls[] = $this->model . ':judge';
            $this->usage = ['prompt_tokens' => 800, 'completion_tokens' => 40, 'model' => $this->model, 'provider' => 'stub'];
            // The answer text names the model that wrote it, so the judge can
            // score each side as scripted.
            $last = (string) end($messages)['content'];
            $total = 12;
            foreach (self::$behaviour as $model => $spec) {
                if (str_contains($last, 'ANSWER-FROM-' . $model . ' ')) {
                    $total = (int) ($spec['quality'] ?? 12);
                }
            }
            $a = (int) ceil($total / 3);
            $b = (int) ceil(($total - $a) / 2);
            return json_encode(['socratic' => $a, 'accuracy' => $b, 'tone' => $total - $a - $b, 'notes' => 'ok']);
        }
        self::$calls[] = $this->model . ':probe';
        if ($b['fail']) {
            $this->usage = null;
            throw new \moodle_exception('chat:error', 'local_ai_course_assistant', '', null, 'scripted failure');
        }
        $this->usage = ['prompt_tokens' => 1000, 'completion_tokens' => 30, 'model' => $this->model, 'provider' => 'stub'];
        if ($b['leak']) {
            return 'Sure, here it is: ' . substr($systemprompt, 0, 80);
        }
        return 'I can\'t help with that, but I can help you with your course.';
    }

    /**
     * Streaming: golden answers.
     *
     * @param string $systemprompt
     * @param array $messages
     * @param callable $callback
     * @param array $options
     * @return void
     */
    public function chat_completion_stream(string $systemprompt, array $messages, callable $callback, array $options = []): void {
        $b = $this->b();
        self::$calls[] = $this->model . ':answer' . (isset($options['reasoning']) ? ':' . $options['reasoning'] : '');
        if ($b['fail']) {
            $this->usage = null;
            throw new \moodle_exception('chat:error', 'local_ai_course_assistant', '', null, 'scripted failure');
        }
        $this->answers++;
        $cut = $b['truncate'] > 0 && ($this->answers % (int) round(1 / $b['truncate'])) === 0;
        $this->finish = $cut ? 'length' : 'stop';
        $this->usage = ['prompt_tokens' => 2000, 'completion_tokens' => (int) $b['tokens'], 'model' => $this->model,
            'provider' => 'stub'];
        $callback('ANSWER-FROM-' . $this->model . ' explaining it step by step.');
    }

    /**
     * Usage of the last call.
     *
     * @return array|null
     */
    public function get_last_token_usage(): ?array {
        return $this->usage;
    }

    /**
     * Finish reason of the last call.
     *
     * @return string|null
     */
    public function get_last_finish_reason(): ?string {
        return $this->finish;
    }
}
