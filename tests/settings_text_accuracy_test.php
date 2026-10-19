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
 * Four admin descriptions say what the code does (7.12.2).
 *
 * Found in the 7.12.1 review: the English text of four settings and notices said
 * something the code does not do. Nothing else reads these strings by name, so a
 * description can drift from the code without any test noticing. This file pins the
 * new meaning in both directions: the false claim is gone from the English value, and
 * the behavior the new sentence describes is what spend_guard really does, so the
 * text and the code cannot part again unnoticed.
 *
 * The 45 other locales still hold translations of the old English; no test compares
 * a translation with the English it came from, only placeholders and brand tokens.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\spend_guard
 */
final class settings_text_accuracy_test extends \advanced_testcase {
    /** @var string[] The four keys this release corrected. */
    private const KEYS = [
        'settings:spend_cap_site_desc',
        'settings:spend_failover_chain_desc',
        'settings:soapbox_deprecated_inuse',
        'soapbox:assign_deprecated',
    ];

    /**
     * One English value, read from the lang file itself so no customised string or cache can stand in for it.
     *
     * @param string $key
     * @return string
     */
    private function en(string $key): string {
        $string = [];
        include(__DIR__ . '/../lang/en/local_ai_course_assistant.php');
        $this->assertArrayHasKey($key, $string, "lang/en has no '{$key}'");
        return $string[$key];
    }

    /**
     * Insert one billable chat row for a course, inside the current spend period.
     *
     * @param int $courseid
     * @param int $prompttokens Prompt tokens on gpt-4o-mini ($0.15 per million).
     * @return void
     */
    private function chat_row(int $courseid, int $prompttokens): void {
        global $DB;
        $DB->insert_record('local_ai_course_assistant_msgs', (object) [
            'conversationid' => 0,
            'userid' => 0,
            'courseid' => $courseid,
            'role' => 'assistant',
            'message' => 'x',
            'tokens_used' => $prompttokens,
            'prompt_tokens' => $prompttokens,
            'completion_tokens' => 0,
            'model_name' => 'gpt-4o-mini',
            'provider' => 'openai',
            'interaction_type' => 'chat',
            'timecreated' => time(),
        ]);
    }

    // The site-wide spend cap.

    /**
     * The description no longer promises a total across the site, and says what the cap compares.
     *
     * @return void
     */
    public function test_the_site_cap_text_does_not_claim_a_site_total(): void {
        $text = $this->en('settings:spend_cap_site_desc');
        $this->assertStringNotContainsString('Total USD cap across all courses', $text);
        $this->assertStringNotContainsString('across all courses and capabilities', $text);
        $this->assertStringContainsString('not a site total', $text);
        $this->assertStringContainsString("that one course's spend", $text);
        $this->assertStringContainsString('site-wide chat spend', $text);
        $this->assertStringContainsString('<code>0</code> = unlimited', $text);
    }

    /**
     * The behavior the new text states: chat spend is not added up across courses.
     *
     * Two courses each spend $0.60 against a $1.00 site cap. A real site total would be $1.20 and block both;
     * the cap is checked per course, so neither is blocked, and a course that spends $1.20 on its own is. A request
     * with no course is checked against the site-wide chat spend.
     *
     * @return void
     */
    public function test_chat_spend_is_checked_one_course_at_a_time(): void {
        $this->resetAfterTest(true);
        $this->redirectEmails();
        set_config('spend_cap_site', '1.00', 'local_ai_course_assistant');
        $a = $this->getDataGenerator()->create_course();
        $b = $this->getDataGenerator()->create_course();
        $c = $this->getDataGenerator()->create_course();
        $this->chat_row((int) $a->id, 4000000);
        $this->chat_row((int) $b->id, 4000000);
        $this->chat_row((int) $c->id, 8000000);

        $this->assertEqualsWithDelta(0.60, spend_guard::get_spend((int) $a->id, 'chat'), 1e-9);
        $this->assertSame(spend_guard::CAP_OK, spend_guard::check((int) $a->id, 'chat', false));
        $this->assertSame(spend_guard::CAP_OK, spend_guard::check((int) $b->id, 'chat', false));
        $this->assertSame(spend_guard::CAP_BLOCKED, spend_guard::check((int) $c->id, 'chat', false));
        // A request with no course (a Learning Radar query) is checked against the site-wide chat spend, $2.40 here.
        $this->assertEqualsWithDelta(2.40, spend_guard::get_spend(0, 'chat'), 1e-9);
        $this->assertSame(spend_guard::CAP_BLOCKED, spend_guard::check(0, 'chat', false));
    }

    /**
     * The behavior the new text states: retrieval spend is a site-wide total for that one capability.
     *
     * Two courses each embed $0.06 of content against a $0.10 RAG cap; neither course is over on its own,
     * the site-wide RAG total is, and the check has no course in it.
     *
     * @return void
     */
    public function test_rag_spend_is_one_site_wide_total(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->redirectEmails();
        set_config('spend_cap_site', '0.10', 'local_ai_course_assistant');
        foreach ([$this->getDataGenerator()->create_course(), $this->getDataGenerator()->create_course()] as $course) {
            $DB->insert_record('local_ai_course_assistant_msgs', (object) [
                'conversationid' => 0,
                'userid' => 0,
                'courseid' => (int) $course->id,
                'role' => 'system',
                'message' => '[Embedding]',
                'tokens_used' => 1000000,
                'prompt_tokens' => 1000000,
                'completion_tokens' => 0,
                'model_name' => 'voyage-3.5',
                'provider' => 'embedding',
                'interaction_type' => 'embedding',
                'timecreated' => time(),
            ]);
        }

        $this->assertEqualsWithDelta(0.12, spend_guard::get_spend(0, 'rag'), 1e-9);
        $this->assertSame(spend_guard::CAP_BLOCKED, spend_guard::check(0, 'rag', false));
    }

    // The failover chain.

    /**
     * The description says a hit cap uses the first line only, and no longer lists analytics lines.
     *
     * Nothing resolves an `analytics:` line (spend_guard::resolve_failover_chain() is asked for chat and voice
     * only), so the example and the capability list name only those two.
     *
     * @return void
     */
    public function test_the_failover_text_says_a_hit_cap_uses_the_first_line_only(): void {
        $text = $this->en('settings:spend_failover_chain_desc');
        $this->assertStringNotContainsString('tries these providers in order', $text);
        $this->assertStringNotContainsString('analytics', $text);
        $this->assertStringContainsString('first <code>chat</code> line', $text);
        $this->assertStringContainsString('per-call failover', $text);
        $this->assertStringContainsString('own model and base URL', $text);
        $this->assertStringContainsString('<code>capability:label</code>', $text);
        $this->assertStringContainsString('[[tutorshort]]', $text);
    }

    /**
     * The behavior the new text states: the cap path takes the first line that resolves, the chain keeps the rest.
     *
     * The first chat line has a row with no key (unusable, skipped), the next two are usable. A tripped cap uses
     * the first of those; the per-call chain would hold both.
     *
     * @return void
     */
    public function test_a_hit_cap_takes_the_first_usable_chat_line_and_the_chain_keeps_the_rest(): void {
        $this->resetAfterTest(true);
        $rows = [
            'openai||gpt-4o-mini',
            'claude|sk-ant-test-key-0000000000|claude-haiku-4-5',
            'gemini|AIzaTestKey000000000000000|gemini-2.5-flash',
        ];
        set_config('comparison_providers', implode("\n", $rows), 'local_ai_course_assistant');
        set_config('spend_failover_chain', "chat:openai\nchat:claude\nchat:gemini", 'local_ai_course_assistant');

        $chain = spend_guard::resolve_failover_chain('chat');
        $this->assertSame(['claude', 'gemini'], array_column($chain, 'provider'));
        $first = spend_guard::resolve_failover('chat');
        $this->assertSame('claude', $first['provider']);
    }

    // The two Soapbox notices.

    /**
     * The settings notice no longer says nothing changes or that the cleanup is unchanged.
     *
     * @return void
     */
    public function test_the_soapbox_settings_notice_describes_the_interlock_and_the_import(): void {
        $text = $this->en('settings:soapbox_deprecated_inuse');
        $this->assertStringNotContainsString('Nothing changes in this release', $text);
        $this->assertStringNotContainsString('the daily cleanup task still runs', $text);
        $this->assertStringNotContainsString('install PresenterAI alongside it when it is released', $text);
        $this->assertStringContainsString('PresenterAI 0.5.0', $text);
        $this->assertStringContainsString('skips every recording PresenterAI', $text);
        $this->assertStringContainsString('refuses to delete an assignment', $text);
        $this->assertStringContainsString('v8.0.0', $text);
    }

    /**
     * The teacher's notice no longer waits for a release that has happened.
     *
     * @return void
     */
    public function test_the_soapbox_assignment_notice_mentions_the_importer(): void {
        $text = $this->en('soapbox:assign_deprecated');
        $this->assertStringNotContainsString('When PresenterAI is released', $text);
        $this->assertStringNotContainsString('will appear in the activity chooser', $text);
        $this->assertStringContainsString('importer', $text);
        $this->assertStringContainsString('moved', $text);
    }

    // House rules for the text itself.

    /**
     * No em or en dashes in the four values.
     *
     * @return void
     */
    public function test_the_four_values_have_no_dashes(): void {
        foreach (self::KEYS as $key) {
            $text = $this->en($key);
            $this->assertStringNotContainsString("\u{2014}", $text, $key . ' has an em dash');
            $this->assertStringNotContainsString("\u{2013}", $text, $key . ' has an en dash');
        }
    }
}
