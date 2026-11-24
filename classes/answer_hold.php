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

/**
 * Keepalives for a held answer (8.4.0).
 *
 * When a risky question is answered in enforce mode, sse.php holds the answer: no token reaches the browser until
 * the whole reply has been checked. A proxy or browser may close an SSE connection that has been silent for a
 * while, so the held stream sends an SSE comment line (KEEPALIVE_FRAME, ignored by the browser's parser) at once and
 * then at most once every KEEPALIVE_SECONDS. tick() is called on every chunk the provider streams (and on every chunk
 * of the one correction retry), so the interval holds while the model is producing text. While the provider has
 * sent nothing at all (before the first byte) nothing ticks; that is the same silence an unheld answer has today.
 *
 * Never throws: a failed write (the learner closed the tab) must not cost the answer.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class answer_hold {

    /** @var int Seconds between keepalives. */
    public const KEEPALIVE_SECONDS = 10;

    /** @var string An SSE comment frame. */
    public const KEEPALIVE_FRAME = ": keepalive\n\n";

    /** @var callable fn(string $frame): void writes and flushes one frame */
    private $send;

    /** @var callable fn(): float the current time in seconds */
    private $clock;

    /** @var float|null when the last keepalive went out */
    private ?float $last = null;

    /**
     * Constructor.
     *
     * @param callable $send fn(string $frame): void
     * @param callable|null $clock fn(): float, microtime(true) when null
     */
    public function __construct(callable $send, ?callable $clock = null) {
        $this->send = $send;
        $this->clock = $clock ?? static fn(): float => microtime(true);
    }

    /**
     * The answer is now held: send a keepalive straight away.
     *
     * @return void
     */
    public function start(): void {
        $this->last = null;
        $this->tick();
    }

    /**
     * Send a keepalive if KEEPALIVE_SECONDS have passed since the last one.
     *
     * @return void
     */
    public function tick(): void {
        try {
            $now = (float) ($this->clock)();
            if ($this->last !== null && ($now - $this->last) < self::KEEPALIVE_SECONDS) {
                return;
            }
            $this->last = $now;
            ($this->send)(self::KEEPALIVE_FRAME);
        } catch (\Throwable $e) {
            debugging('answer_hold: keepalive failed: ' . get_class($e), DEBUG_DEVELOPER);
        }
    }
}
