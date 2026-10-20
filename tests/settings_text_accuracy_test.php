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
 * Eight admin descriptions say what the code does (7.12.2).
 *
 * Found in the 7.12.1 review: the English text of eight settings and notices said
 * something the code does not do. Nothing else reads these strings by name, so a
 * description can drift from the code without any test noticing. This file pins the
 * new meaning in both directions: the false claim is gone from the English value, and
 * the behavior the new sentence describes is what spend_guard really does, so the
 * text and the code cannot part again unnoticed.
 *
 * No test compares a translation with the English it came from, so the 45 other
 * locales would have kept the old, wrong text silently. The locale lines for every
 * corrected key were therefore removed, and Moodle falls back to the accurate English
 * (the keys are listed in KNOWN_UNTRANSLATED, lang_completeness_test); a test below
 * keeps a stale translation from coming back.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\spend_guard
 */
final class settings_text_accuracy_test extends \advanced_testcase {
    /** @var string[] The keys this release corrected. */
    private const KEYS = [
        'settings:spend_cap_site_desc',
        'settings:spend_cap_chat_desc',
        'settings:spend_cap_rag_desc',
        'settings:spend_cap_analytics_desc',
        'settings:spend_guard_heading_desc',
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
        $this->assertStringContainsString('for information only', $text);
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

    // The other spend cap descriptions.

    /**
     * The Chat cap text says what it is compared with and what replaces it when it is 0.
     *
     * @return void
     */
    public function test_the_chat_cap_text_says_per_course_and_what_zero_means(): void {
        $text = $this->en('settings:spend_cap_chat_desc');
        $this->assertStringNotContainsString('use site-wide cap', $text);
        $this->assertStringContainsString('each course on its own', $text);
        $this->assertStringContainsString('site-wide chat spend', $text);
        $this->assertStringContainsString('default per-course cap applies, then the site-wide cap', $text);
    }

    /**
     * The behavior the Chat cap text states: a course's own cap wins, a 0 falls to the default, then the site cap.
     *
     * @return void
     */
    public function test_a_zero_chat_cap_falls_to_the_default_per_course_cap_then_the_site_cap(): void {
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $cid = (int) $course->id;
        set_config('spend_cap_site', '500', 'local_ai_course_assistant');
        set_config('spend_cap_per_course_default', '30', 'local_ai_course_assistant');
        set_config('spend_cap_chat', '0', 'local_ai_course_assistant');
        $this->assertEquals(30.0, spend_guard::get_cap($cid, 'chat'));

        set_config('spend_cap_per_course_default', '0', 'local_ai_course_assistant');
        $this->assertEquals(500.0, spend_guard::get_cap($cid, 'chat'));
        $this->assertEquals(500.0, spend_guard::get_cap(0, 'chat'));

        set_config('spend_cap_chat', '200', 'local_ai_course_assistant');
        $this->assertEquals(200.0, spend_guard::get_cap($cid, 'chat'));
        $this->assertEquals(200.0, spend_guard::get_cap(0, 'chat'));

        course_config_manager::save($cid, ['enabled' => 1, 'spend_cap_monthly' => '2']);
        $this->assertEquals(2.0, spend_guard::get_cap($cid, 'chat'));
    }

    /**
     * The RAG cap text says embedding and rerank, site-wide, and what happens when it is reached.
     *
     * @return void
     */
    public function test_the_rag_cap_text_covers_searches_and_says_what_stops(): void {
        $text = $this->en('settings:spend_cap_rag_desc');
        $this->assertStringNotContainsString('made during course indexing', $text);
        $this->assertStringContainsString('site-wide RAG spend', $text);
        $this->assertStringContainsString('when a question is searched', $text);
        $this->assertStringContainsString('reranking', $text);
        $this->assertStringContainsString('indexing stops and searches return no passages', $text);
        $this->assertStringContainsString('<code>0</code> = use the site-wide cap', $text);
    }

    /**
     * The analytics cap text says plainly that nothing enforces it.
     *
     * @return void
     */
    public function test_the_analytics_cap_text_says_it_is_not_enforced(): void {
        $text = $this->en('settings:spend_cap_analytics_desc');
        $this->assertStringStartsWith('Not enforced.', $text);
        $this->assertStringContainsString('Learning Radar queries are checked against the Chat cap', $text);
        $this->assertStringNotContainsString('Cap for Learning Radar admin queries', $text);
    }

    /**
     * The behavior the analytics text states: no enforcement path ever asks for the analytics capability.
     *
     * Two halves. A request-time check never reads the analytics cap, so exceeding it blocks nothing; and no
     * production file passes 'analytics' to spend_guard::check(), get_cap() or get_spend() (status_rows() builds
     * its list from a variable, for the Token Cost page only). If someone starts enforcing it, this fails and the
     * description has to change with the code.
     *
     * @return void
     */
    public function test_nothing_enforces_the_analytics_cap(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->redirectEmails();
        set_config('spend_cap_analytics', '0.01', 'local_ai_course_assistant');
        $DB->insert_record('local_ai_course_assistant_msgs', (object) [
            'conversationid' => 0,
            'userid' => 0,
            'courseid' => SITEID,
            'role' => 'assistant',
            'message' => '[meta]',
            'tokens_used' => 4000000,
            'prompt_tokens' => 4000000,
            'completion_tokens' => 0,
            'model_name' => 'gpt-4o-mini',
            'provider' => 'openai',
            'interaction_type' => 'meta',
            'timecreated' => time(),
        ]);
        $this->assertGreaterThan(0.01, spend_guard::get_spend(0, 'analytics'), 'the analytics spend is not over its cap');
        $this->assertSame(spend_guard::CAP_OK, spend_guard::check(0, 'chat', false));
        $this->assertSame(spend_guard::CAP_OK, spend_guard::check(0, 'rag', false));
        $this->assertSame(spend_guard::CAP_OK, spend_guard::check(0, 'voice', false));

        $root = dirname(__DIR__);
        $skip = ['tests/', 'lang/', 'vendor/', 'node_modules/', '.superpowers/', 'scripts/'];
        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $path = $file->getPathname();
            $rel = substr($path, strlen($root) + 1);
            if (substr($path, -4) !== '.php') {
                continue;
            }
            foreach ($skip as $prefix) {
                if (strpos($rel, $prefix) === 0) {
                    continue 2;
                }
            }
            $call = "/spend_guard::(?:check|get_cap|get_spend)\\(\\s*[^;]*?'analytics'/s";
            if (preg_match($call, (string) file_get_contents($path))) {
                $offenders[] = $rel;
            }
        }
        $this->assertSame([], $offenders, 'a file now asks spend_guard about the analytics cap; update its description');
    }

    /**
     * The section heading text says what a hit cap does and when the emails go out.
     *
     * @return void
     */
    public function test_the_spend_guard_heading_text_describes_the_failover_and_the_emails(): void {
        $text = $this->en('settings:spend_guard_heading_desc');
        $this->assertStringNotContainsString('pauses requests when a cap is hit', $text);
        $this->assertStringNotContainsString('cheaper provider', $text);
        $this->assertStringContainsString('moves chat and voice to the first backup in your failover chain', $text);
        $this->assertStringContainsString('80%, 95% and 100%', $text);
        $this->assertStringContainsString('{$a}', $text);
        $this->assertStringContainsString('[[tutorshort]]', $text);
    }

    // No stale translation.

    /**
     * No locale carries a translation of a corrected key: the old text said something wrong.
     *
     * The lines were removed in 7.12.2 so Moodle shows the accurate English. A translation batch that restores
     * them removes the key from KNOWN_UNTRANSLATED in the same change, and from this list.
     *
     * @return void
     */
    public function test_no_locale_holds_a_stale_translation_of_a_corrected_key(): void {
        $locales = array_filter(glob(__DIR__ . '/../lang/*', GLOB_ONLYDIR), fn($d) => basename($d) !== 'en');
        $this->assertGreaterThan(40, count($locales), 'expected the full locale set');
        $stale = [];
        foreach ($locales as $dir) {
            $body = (string) file_get_contents($dir . '/local_ai_course_assistant.php');
            foreach (self::KEYS as $key) {
                if (strpos($body, '$string[\'' . $key . '\']') !== false) {
                    $stale[] = basename($dir) . ' ' . $key;
                }
            }
        }
        $this->assertSame([], $stale, "A locale holds a translation of the old English:\n  " . implode("\n  ", $stale));
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
