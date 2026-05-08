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
 * Test-only provider that returns canned responses.
 *
 * Wired into base_provider::instantiate() under provider id 'stub'. Tests
 * that exercise the LLM-calling external services can flip the configured
 * provider to 'stub' and either accept the default canned response for the
 * detected prompt category (quiz, flashcards, essay, insights, or generic
 * chat) or program a custom response with stub_provider::program_response().
 *
 * Static fields are reset at the start of each test by calling reset() in
 * setUp; resetAfterTest() also rolls back the set_config('provider', 'stub')
 * change so production never sees this provider in non-test code paths.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class stub_provider extends base_provider {

    /** @var array<string,string> Programmed responses keyed by detected prompt kind. */
    public static array $programmed = [];

    /** @var array<int,array<string,mixed>> Call log: every chat_completion call landed here. */
    public static array $calls = [];

    /** @var \Throwable|null If set, the next chat_completion call throws this exception. */
    public static ?\Throwable $throw_next = null;

    /**
     * Reset all static state. Call this in setUp() of any test that uses the stub.
     */
    public static function reset(): void {
        self::$programmed = [];
        self::$calls = [];
        self::$throw_next = null;
    }

    /**
     * Programmatically set the response for a given prompt kind.
     *
     * Recognised kinds: 'quiz', 'flashcards', 'essay', 'insights', 'chat'.
     * Anything else is treated as 'chat'.
     *
     * @param string $kind
     * @param string $body Raw response body (usually a JSON string).
     */
    public static function program_response(string $kind, string $body): void {
        self::$programmed[$kind] = $body;
    }

    public function chat_completion(string $systemprompt, array $messages, array $options = []): string {
        $kind = self::detect_kind($systemprompt);
        self::$calls[] = [
            'kind' => $kind,
            'systemprompt' => $systemprompt,
            'messages' => $messages,
            'options' => $options,
        ];

        if (self::$throw_next !== null) {
            $err = self::$throw_next;
            self::$throw_next = null;
            throw $err;
        }

        if (isset(self::$programmed[$kind])) {
            return self::$programmed[$kind];
        }
        return self::default_response_for($kind);
    }

    public function chat_completion_stream(string $systemprompt, array $messages, callable $callback, array $options = []): void {
        $full = $this->chat_completion($systemprompt, $messages, $options);
        // Emit in 64-byte chunks so streaming callers see more than one chunk.
        $offset = 0;
        $len = strlen($full);
        while ($offset < $len) {
            $chunk = substr($full, $offset, 64);
            $callback($chunk);
            $offset += 64;
        }
    }

    public function get_last_token_usage(): ?array {
        // Fixed numbers so spend-tracking tests that read this can rely on stable values.
        return [
            'prompt_tokens' => 100,
            'completion_tokens' => 50,
            'model' => 'stub-model',
        ];
    }

    protected function get_default_model(): string {
        return 'stub-model';
    }

    protected function get_default_base_url(): string {
        return 'stub://local';
    }

    /**
     * Inspect the system prompt to decide which canned shape applies.
     *
     * @param string $systemprompt
     * @return string
     */
    private static function detect_kind(string $systemprompt): string {
        if (stripos($systemprompt, 'quiz generator') !== false || stripos($systemprompt, 'multiple-choice quiz') !== false) {
            return 'quiz';
        }
        if (stripos($systemprompt, 'flashcard') !== false) {
            return 'flashcards';
        }
        if (stripos($systemprompt, 'writing coach') !== false || stripos($systemprompt, 'essay') !== false) {
            return 'essay';
        }
        if (stripos($systemprompt, 'product analyst') !== false || stripos($systemprompt, 'feature request') !== false) {
            return 'insights';
        }
        return 'chat';
    }

    /**
     * Default canned response for each kind. Shape matches what the
     * corresponding external service expects to parse.
     *
     * @param string $kind
     * @return string
     */
    private static function default_response_for(string $kind): string {
        switch ($kind) {
            case 'quiz':
                return json_encode([
                    'topic' => 'Stub quiz topic',
                    'questions' => [
                        [
                            'id' => 1,
                            'question' => 'Stub question 1?',
                            'choices' => ['A) one', 'B) two', 'C) three', 'D) four'],
                            'correct' => 'A',
                            'explanation' => 'Stub explanation 1.',
                        ],
                        [
                            'id' => 2,
                            'question' => 'Stub question 2?',
                            'choices' => ['A) one', 'B) two', 'C) three', 'D) four'],
                            'correct' => 'B',
                            'explanation' => 'Stub explanation 2.',
                        ],
                        [
                            'id' => 3,
                            'question' => 'Stub question 3?',
                            'choices' => ['A) one', 'B) two', 'C) three', 'D) four'],
                            'correct' => 'C',
                            'explanation' => 'Stub explanation 3.',
                        ],
                    ],
                ]);

            case 'flashcards':
                return json_encode([
                    'cards' => [
                        ['question' => 'Stub front 1', 'answer' => 'Stub back 1'],
                        ['question' => 'Stub front 2', 'answer' => 'Stub back 2'],
                        ['question' => 'Stub front 3', 'answer' => 'Stub back 3'],
                    ],
                ]);

            case 'essay':
                return json_encode([
                    'criteria' => [
                        ['name' => 'Thesis', 'score' => 3, 'feedback' => 'Stub feedback on thesis.'],
                        ['name' => 'Evidence', 'score' => 2, 'feedback' => 'Stub feedback on evidence.'],
                    ],
                    'overall' => 'Stub overall comment.',
                    'revisions' => ['Tighten thesis.', 'Add a source.', 'Vary sentence length.'],
                ]);

            case 'insights':
                return "## Stub Insights\n\n- Theme A from feedback.\n- Theme B from surveys.";

            case 'chat':
            default:
                return 'Stub assistant reply.';
        }
    }
}
