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
 * Tests for how SOLA chooses its reply language.
 *
 * The rule, in priority order: the language the question is written in (asking
 * before it changes a language the learner saved), then the learner's saved
 * SOLA language, then the browser's. The model applies it, so what is testable
 * here is that the prompt states the right rule for each situation and that the
 * confirmation marker is read and removed correctly.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_course_assistant;

/**
 * @covers \local_ai_course_assistant\language_support
 * @covers \local_ai_course_assistant\protocol_markers
 */
final class language_support_test extends \advanced_testcase {

    /**
     * SOLA answers in 46 languages, and the list matches the browser's picker.
     *
     * @return void
     */
    public function test_forty_six_languages_matching_the_browser_list(): void {
        $names = language_support::names();
        $this->assertCount(46, $names);

        $js = file_get_contents(__DIR__ . '/../amd/src/speech.js');
        preg_match_all("/^\s+'([a-z]{2})': \{name: '[^']+',\s+locale: '[^']+', native: '[^']+'\},/m", $js, $m);
        $browser = $m[1];
        sort($browser);
        $server = array_keys($names);
        sort($server);
        $this->assertSame($server, $browser, 'speech.js and language_support::names() must list the same languages.');
    }

    /**
     * Unknown or malformed codes never get through.
     *
     * @return void
     */
    public function test_normalise(): void {
        $this->assertSame('es', language_support::normalise(' ES '));
        $this->assertSame('', language_support::normalise('xx'));
        $this->assertSame('', language_support::normalise('english'));
        $this->assertSame('es,fr', language_support::normalise_list('es, FR,xx,es'));
        $this->assertSame('saved', language_support::normalise_source('SAVED'));
        $this->assertSame('default', language_support::normalise_source('whatever'));
        $this->assertSame('pinned', language_support::normalise_source('Pinned'));
        // Only the server may claim the English lock, never the browser.
        $this->assertSame('default', language_support::normalise_source('locked'));
    }

    /**
     * A saved language is protected: a message in another language is asked about, not obeyed.
     *
     * @return void
     */
    public function test_saved_language_asks_before_switching(): void {
        $p = language_support::prompt_section('en', 'saved');
        $this->assertStringContainsString('English (en)', $p);
        $this->assertStringContainsString('saved English (en) as their SOLA language', $p);
        $this->assertStringContainsString('[SOLA_LANG_SWITCH]xx[/SOLA_LANG_SWITCH]', $p);
        $this->assertStringContainsString('do NOT answer it yet', $p);
        $this->assertStringContainsString('translating, quoting, practicing', $p);
        // The old rule that made a saved language beat the question must be gone.
        $this->assertStringNotContainsString('regardless of what language the student writes in', $p);
        // Every supported language is offered to the model.
        $this->assertStringContainsString('Wolof (wo)', $p);
        $this->assertStringContainsString('Hebrew (he)', $p);
        $this->assertStringContainsString('Bulgarian (bg)', $p);
    }

    /**
     * A language the learner declined is not asked about again.
     *
     * @return void
     */
    public function test_declined_language_is_not_asked_again(): void {
        $p = language_support::prompt_section('en', 'saved', 'es,fr');
        $this->assertStringContainsString('Do NOT ask about Spanish (es) or French (fr) again', $p);
        // Other languages still get the question.
        $this->assertStringContainsString('SOLA_LANG_SWITCH', $p);
    }

    /**
     * With nothing saved there is nothing to protect: follow the question, no questions asked.
     *
     * @return void
     */
    public function test_browser_default_follows_the_question_without_asking(): void {
        $p = language_support::prompt_section('es', 'default');
        $this->assertStringContainsString('has not chosen a SOLA language', $p);
        $this->assertStringContainsString('language their LATEST message is written in', $p);
        $this->assertStringContainsString("use Spanish (es), their browser's language", $p);
        $this->assertStringNotContainsString('[SOLA_LANG_SWITCH]xx', $p);

        $none = language_support::prompt_section('', '');
        $this->assertStringContainsString('use English', $none);
        $this->assertStringNotContainsString('[SOLA_LANG_SWITCH]xx', $none);
    }

    /**
     * Voice and a question already asked cannot ask again: a saved language is pinned.
     *
     * @return void
     */
    public function test_pinned_language_answers_in_it_and_never_asks(): void {
        $p = language_support::prompt_section('fr', 'pinned');
        $this->assertStringContainsString('saved French (fr) as their SOLA language', $p);
        $this->assertStringContainsString('whatever language the student writes in', $p);
        $this->assertStringContainsString('Do not ask about switching language', $p);
        $this->assertStringNotContainsString('[SOLA_LANG_SWITCH]xx', $p);
    }

    /**
     * The question is the one reply that skips the suggestion block.
     *
     * @return void
     */
    public function test_question_reply_is_exempt_from_the_chips_rule(): void {
        $p = language_support::prompt_section('en', 'saved');
        $this->assertStringContainsString('does not end with the suggestion block', $p);
    }

    /**
     * An English-locked course never offers to change language.
     *
     * @return void
     */
    public function test_locked_course_never_asks(): void {
        $p = language_support::prompt_section('en', 'locked');
        $this->assertStringContainsString('locked to English', $p);
        $this->assertStringContainsString('Never offer to switch language', $p);
        $this->assertStringNotContainsString('[SOLA_LANG_SWITCH]xx', $p);
    }

    /**
     * The section reaches the real system prompt, with the source and held languages.
     *
     * @return void
     */
    public function test_prompt_reaches_the_system_prompt(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $user = $gen->create_user();
        $saved = context_builder::build_system_prompt($course->id, $user->id, 'fr', [], 0, '', '', 'saved', 'de');
        $this->assertStringContainsString('saved French (fr) as their SOLA language', $saved);
        $this->assertStringContainsString('German (de)', $saved);
        $default = context_builder::build_system_prompt($course->id, $user->id, 'fr', [], 0, '', '', 'default');
        $this->assertStringContainsString('has not chosen a SOLA language', $default);
        // Callers that pass no source (the mobile path) follow the question.
        $legacy = context_builder::build_system_prompt($course->id, $user->id, 'fr');
        $this->assertStringContainsString('has not chosen a SOLA language', $legacy);
    }

    /**
     * The confirmation marker is read, validated and removed.
     *
     * @return void
     */
    public function test_switch_marker_is_read_and_stripped(): void {
        $raw = "[SOLA_LANG_SWITCH]es[/SOLA_LANG_SWITCH]\nHe notado que escribes en español. ¿Cambio SOLA a español?";
        $this->assertSame('es', protocol_markers::lang_switch($raw));
        $this->assertSame('He notado que escribes en español. ¿Cambio SOLA a español?', protocol_markers::strip($raw));

        // No closer, still read and removed, sentence kept.
        $open = "[SOLA_LANG_SWITCH]fr\nJ'ai remarqué que vous écrivez en français.";
        $this->assertSame('fr', protocol_markers::lang_switch($open));
        $this->assertSame("J'ai remarqué que vous écrivez en français.", protocol_markers::strip($open));

        // A code SOLA does not support asks nothing, and the tag still goes.
        $bad = "[SOLA_LANG_SWITCH]xx[/SOLA_LANG_SWITCH]\nHello";
        $this->assertNull(protocol_markers::lang_switch($bad));
        $this->assertSame('Hello', protocol_markers::strip($bad));

        // A three-letter code the model might use for Filipino is read as Filipino.
        $fil = "[SOLA_LANG_SWITCH]fil[/SOLA_LANG_SWITCH]\nMagandang araw!";
        $this->assertSame('tl', protocol_markers::lang_switch($fil));
        $this->assertSame('Magandang araw!', protocol_markers::strip($fil));
        // An unsupported three-letter code asks nothing and leaves no stray letters behind.
        $jv = "[SOLA_LANG_SWITCH]jav[/SOLA_LANG_SWITCH]\nSugeng enjang.";
        $this->assertNull(protocol_markers::lang_switch($jv));
        $this->assertSame('Sugeng enjang.', protocol_markers::strip($jv));

        // An ordinary answer carries none.
        $this->assertNull(protocol_markers::lang_switch('Plain answer.'));
        $this->assertSame('Plain answer.', protocol_markers::strip('Plain answer. [SOLA_NEXT]a||b[/SOLA_NEXT]'));
    }

    /**
     * A typed yes or no is confirmed by SET or KEEP, which the browser reads and the learner never sees.
     *
     * @return void
     */
    public function test_typed_answer_markers_are_read_and_stripped(): void {
        $set = "Claro, aquí está la respuesta.\n[SOLA_LANG_SET]es[/SOLA_LANG_SET]";
        $this->assertSame(['set', 'es'], protocol_markers::lang_answer($set));
        $this->assertSame('Claro, aquí está la respuesta.', protocol_markers::strip($set));

        $keep = "Here is the answer.\n[SOLA_LANG_KEEP]fr";
        $this->assertSame(['keep', 'fr'], protocol_markers::lang_answer($keep));
        $this->assertSame('Here is the answer.', protocol_markers::strip($keep));

        $this->assertNull(protocol_markers::lang_answer("[SOLA_LANG_SET]xx[/SOLA_LANG_SET]\nHi"));
        $this->assertNull(protocol_markers::lang_answer('Plain answer.'));
        $this->assertSame('Hi', protocol_markers::strip("[SOLA_LANG_SET]xx[/SOLA_LANG_SET]\nHi"));
    }

    /**
     * The saved-language prompt tells the model how to read a typed answer.
     *
     * @return void
     */
    public function test_saved_prompt_covers_typed_answers(): void {
        $prompt = language_support::prompt_section('en', language_support::SOURCE_SAVED);
        $this->assertStringContainsString('SOLA_LANG_SET', $prompt);
        $this->assertStringContainsString('SOLA_LANG_KEEP', $prompt);
        $pinned = language_support::prompt_section('en', language_support::SOURCE_PINNED);
        $this->assertStringNotContainsString('SOLA_LANG_SET', $pinned);
    }

    /**
     * English is named as a language worth asking about when another language is saved.
     *
     * Measured on gemini-3.1-flash-lite: without this, an English question under a saved
     * French setting was answered in French and the switch question never appeared.
     *
     * @return void
     */
    public function test_saved_non_english_language_names_english_as_askable(): void {
        $fr = language_support::prompt_section('fr', language_support::SOURCE_SAVED);
        $this->assertStringContainsString('English is a supported language like any other', $fr);
        $this->assertStringContainsString('When French is saved', $fr);
        // With English itself saved there is no English special case.
        $en = language_support::prompt_section('en', language_support::SOURCE_SAVED);
        $this->assertStringNotContainsString('English is a supported language like any other', $en);
    }

    /**
     * The reply-language rule is the last thing in the built prompt, after the chips rule.
     *
     * Mid-prompt, gemini-3.1-flash-lite ignored the switch question on 6 of 6 runs; last, it
     * asked on every Spanish and German run.
     *
     * @return void
     */
    public function test_reply_language_section_is_last_in_the_built_prompt(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $prompt = context_builder::build_system_prompt(
            $course->id, $student->id, 'fr', [], 0, '', '', language_support::SOURCE_SAVED, ''
        );
        $multi = strrpos($prompt, '## Multilingual Support');
        $chips = strrpos($prompt, 'ALWAYS finish your response with this exact marker');
        $this->assertNotFalse($multi);
        $this->assertNotFalse($chips);
        $this->assertGreaterThan($chips, $multi, 'The language rule must come after the chips rule.');
        // Nothing but the language section follows the heading: no later "## " section.
        $this->assertSame(0, preg_match('/\n## /', substr($prompt, $multi + 5)));
    }
}
