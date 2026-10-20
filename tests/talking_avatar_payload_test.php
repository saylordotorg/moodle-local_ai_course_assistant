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

use local_ai_course_assistant\talking_avatar\did_provider;
use local_ai_course_assistant\talking_avatar\heygen_provider;
use local_ai_course_assistant\talking_avatar\synthesia_provider;
use local_ai_course_assistant\talking_avatar\tavus_provider;

/**
 * What the server sends a talking-avatar vendor when it opens a session.
 *
 * CONTRIB-10574 #340 (7.12.2): Synthesia's session request carried the learner's
 * Moodle user id in a metadata block nothing ever read back. The privacy metadata
 * says the server sends the vendor nothing about the learner (the learner's voice,
 * camera and IP address reach it from the browser, and are declared). This pins
 * that for all four drivers: no request body names the learner.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\talking_avatar\base_provider
 */
final class talking_avatar_payload_test extends \advanced_testcase {
    /** A user id distinctive enough that finding it in a body can only mean it was sent. */
    private const USERID = 9876543;

    /** A response that satisfies every driver's session parser. */
    private const FAKE = [
        'data' => ['token' => 'tok', 'url' => 'https://room.example/r', 'session_id' => 'sess'],
        'conversation_url' => 'https://conv.example/c',
        'conversation_id' => 'conv',
        'embed_url' => 'https://embed.example/e',
        'session_id' => 'sess',
        'id' => 'stream',
    ];

    /**
     * Wrap a driver so its HTTP calls are recorded instead of sent.
     *
     * @param string $class Driver class name.
     * @return object The wrapped driver, with a public $sent list of request bodies.
     */
    private static function recording(string $class): object {
        $fake = self::FAKE;
        switch ($class) {
            case synthesia_provider::class:
                return new class ($fake) extends synthesia_provider {
                    /** @var array */
                    public array $sent = [];
                    /** @var array */
                    private array $fake;

                    /**
                     * Constructor.
                     *
                     * @param array $fake Response to return.
                     */
                    public function __construct(array $fake) {
                        $this->fake = $fake;
                    }

                    /**
                     * Record instead of sending.
                     *
                     * @param string $url
                     * @param array $headers
                     * @param array $payload
                     * @return array
                     */
                    protected function http_post_json(string $url, array $headers, array $payload): array {
                        $this->sent[] = $payload;
                        return $this->fake;
                    }
                };
            case tavus_provider::class:
                return new class ($fake) extends tavus_provider {
                    /** @var array */
                    public array $sent = [];
                    /** @var array */
                    private array $fake;

                    /**
                     * Constructor.
                     *
                     * @param array $fake Response to return.
                     */
                    public function __construct(array $fake) {
                        $this->fake = $fake;
                    }

                    /**
                     * Record instead of sending.
                     *
                     * @param string $url
                     * @param array $headers
                     * @param array $payload
                     * @return array
                     */
                    protected function http_post_json(string $url, array $headers, array $payload): array {
                        $this->sent[] = $payload;
                        return $this->fake;
                    }
                };
            case heygen_provider::class:
                return new class ($fake) extends heygen_provider {
                    /** @var array */
                    public array $sent = [];
                    /** @var array */
                    private array $fake;

                    /**
                     * Constructor.
                     *
                     * @param array $fake Response to return.
                     */
                    public function __construct(array $fake) {
                        $this->fake = $fake;
                    }

                    /**
                     * Record instead of sending.
                     *
                     * @param string $url
                     * @param array $headers
                     * @param array $payload
                     * @return array
                     */
                    protected function http_post_json(string $url, array $headers, array $payload): array {
                        $this->sent[] = $payload;
                        return $this->fake;
                    }
                };
            default:
                return new class ($fake) extends did_provider {
                    /** @var array */
                    public array $sent = [];
                    /** @var array */
                    private array $fake;

                    /**
                     * Constructor.
                     *
                     * @param array $fake Response to return.
                     */
                    public function __construct(array $fake) {
                        $this->fake = $fake;
                    }

                    /**
                     * Record instead of sending.
                     *
                     * @param string $url
                     * @param array $headers
                     * @param array $payload
                     * @return array
                     */
                    protected function http_post_json(string $url, array $headers, array $payload): array {
                        $this->sent[] = $payload;
                        return $this->fake;
                    }
                };
        }
    }

    /**
     * The four drivers.
     *
     * @return array
     */
    public static function driver_provider(): array {
        return [
            'did' => ['did', did_provider::class],
            'heygen' => ['heygen', heygen_provider::class],
            'tavus' => ['tavus', tavus_provider::class],
            'synthesia' => ['synthesia', synthesia_provider::class],
        ];
    }

    /**
     * No session request names the learner.
     *
     * @dataProvider driver_provider
     * @param string $key Driver config prefix.
     * @param string $class Driver class name.
     * @return void
     */
    public function test_no_session_request_carries_the_learner_id(string $key, string $class): void {
        $this->resetAfterTest();
        set_config($key . '_api_key', 'test-key-0000', 'local_ai_course_assistant');
        set_config($key . '_persona_id', 'persona-1', 'local_ai_course_assistant');

        $driver = self::recording($class);
        $driver->start_session(['courseid' => 12, 'userid' => self::USERID, 'lang' => 'en', 'greeting' => '']);

        $this->assertNotEmpty($driver->sent, 'The driver made no request, so this test proves nothing.');
        $json = json_encode($driver->sent);
        $this->assertStringNotContainsString((string) self::USERID, $json, "{$key} sends the learner's user id.");
        $this->assertDoesNotMatchRegularExpression('/"[^"]*user[^"]*"\s*:/i', $json, "{$key} sends a user field.");
    }

    /**
     * Synthesia's session request is the language and the opening line, nothing else.
     *
     * @return void
     */
    public function test_synthesia_sends_only_the_language_and_opening_line(): void {
        $this->resetAfterTest();
        set_config('synthesia_api_key', 'test-key-0000', 'local_ai_course_assistant');
        set_config('synthesia_persona_id', 'agent-1', 'local_ai_course_assistant');

        $driver = self::recording(synthesia_provider::class);
        $driver->start_session(['courseid' => 12, 'userid' => self::USERID, 'lang' => 'fr', 'greeting' => '']);

        $this->assertCount(1, $driver->sent);
        $this->assertSame(['language' => 'fr', 'opening_line' => ''], $driver->sent[0]);
    }
}
