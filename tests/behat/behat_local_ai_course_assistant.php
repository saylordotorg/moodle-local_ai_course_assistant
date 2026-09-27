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

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

use Behat\Mink\Exception\ExpectationException;

/**
 * Steps that check what a learner actually reads, not just that it rendered.
 *
 * WHY THIS FILE EXISTS. A scenario in drawer_interactions.feature has opened the
 * help panel as a student, in a real browser, on every pull request since v5.3.
 * For seven weeks, from v7.4.7 to v7.5.3, that panel displayed nine lines of our
 * own internal engineering notes above the help text, because a Mustache comment
 * quoted a tag inline and therefore ended early. The scenario passed every time.
 *
 * It passed because it asserted the panel "should be visible". It was visible.
 *
 * The lesson is not that we needed one more scenario. It is that asserting
 * something APPEARED is nearly free of meaning, and the assertions that catch
 * real defects are the ones that say what may NOT appear. A learner-facing
 * surface should never contain template syntax, an unresolved branding token, an
 * unsubstituted placeholder, or a raw language-string key, and none of those
 * requires knowing what the surface is supposed to say.
 *
 * @package    local_ai_course_assistant
 * @category   test
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_local_ai_course_assistant extends behat_base {
    /**
     * Artefacts that must never reach a learner, with what each one means.
     *
     * @return array<string, string>
     */
    protected function leak_patterns(): array {
        return [
            '/\{\{/' => 'an opening Mustache tag, so a template comment ended early '
                . 'or a tag was not processed',
            '/\}\}/' => 'a closing Mustache tag, the usual tail of a comment that '
                . 'terminated at an inline tag',
            '/\[\[[a-z_]+\]\]/i' => 'an unresolved branding token such as [[tutorshort]], '
                . 'so branding::apply() did not run on this path',
            '/\{\$a(->[a-z0-9_]+)?\}/i' => 'an unsubstituted language-string placeholder, '
                . 'so get_string() was called without its $a',
            '/\[\[[a-z_]+:[a-z0-9_]+\]\]/i' => 'a raw language-string key, so the string '
                . 'is missing from lang/en',
        ];
    }

    /**
     * Assert that a region contains nothing a learner should never see.
     *
     * @Then /^"(?P<selector>[^"]*)" should not leak template syntax$/
     * @param string $selector CSS selector for the region to inspect.
     * @throws ExpectationException
     */
    public function region_should_not_leak_template_syntax(string $selector): void {
        $node = $this->find('css', $selector);
        $text = $node->getText();

        foreach ($this->leak_patterns() as $pattern => $meaning) {
            if (preg_match($pattern, $text, $match)) {
                $where = strpos($text, $match[0]);
                $excerpt = trim(substr($text, max(0, $where - 60), 180));
                throw new ExpectationException(
                    "The region '{$selector}' shows a learner " . $meaning . ".\n"
                        . "Found: '" . $match[0] . "'\n"
                        . "Context: ..." . $excerpt . "...",
                    $this->getSession()
                );
            }
        }
    }

    /**
     * Assert a region has real prose in it, not just structure.
     *
     * A panel whose strings all resolved to empty passes a leak check and is
     * still broken. This is the cheap complement: somebody wrote words here and
     * the learner can read them.
     *
     * @Then /^"(?P<selector>[^"]*)" should contain readable text$/
     * @param string $selector CSS selector for the region to inspect.
     * @throws ExpectationException
     */
    public function region_should_contain_readable_text(string $selector): void {
        $text = trim($this->find('css', $selector)->getText());
        $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);

        if (count($words) < 10) {
            throw new ExpectationException(
                "The region '{$selector}' has almost no text in it ("
                    . count($words) . " words). A panel that renders empty passes every "
                    . "'is visible' assertion and is still broken.\nGot: '" . $text . "'",
                $this->getSession()
            );
        }
    }

    /**
     * POST an audio clip of a given size to transcribe.php and remember the status.
     *
     * WHY THIS IS A BEHAT STEP AND NOT A UNIT TEST. transcribe.php declares
     * AJAX_SCRIPT and gates on is_uploaded_file(), so PHPUnit cannot enter it:
     * there is no uploaded file in a CLI process and the script terminates before
     * anything testable runs. The unit tests therefore assert that the size guard
     * is PRESENT, complete and ordered ahead of the provider call, by reading the
     * source. That is worth having, and it is not the same claim as "the request
     * stops here".
     *
     * It matters which claim you have. Deleting only the `exit;` from the guard
     * body leaves every source assertion intact while the defect is fully live: an
     * oversized clip reaches the transcription provider and is billed, and the
     * provider's JSON refusal comes back to the learner base64-encoded as audio
     * that decodes to silence.
     *
     * A real browser closes that gap. The fetch below carries the session cookie
     * and the page's sesskey, so the request satisfies require_login(),
     * require_sesskey() and require_capability() and reaches the guard the way a
     * learner's recording does.
     *
     * @When /^I post a (?P<bytes>\d+) byte audio clip to the transcription endpoint$/
     * @param int $bytes Size of the synthetic clip.
     * @throws ExpectationException
     */
    public function i_post_an_audio_clip_of_size(int $bytes): void {
        $script = <<<JS
            window.__solaProbeStatus = null;
            (function () {
                var body = new Blob([new Uint8Array($bytes)], {type: 'audio/webm'});
                var form = new FormData();
                form.append('audio', body, 'probe.webm');
                form.append('sesskey', M.cfg.sesskey);
                form.append('courseid', M.cfg.courseId || 0);
                form.append('lang', 'en');
                fetch(M.cfg.wwwroot + '/local/ai_course_assistant/transcribe.php', {
                    method: 'POST', body: form, credentials: 'same-origin'
                }).then(function (r) {
                    window.__solaProbeStatus = r.status;
                }).catch(function () {
                    window.__solaProbeStatus = -1;
                });
            })();
JS;
        $this->getSession()->executeScript($script);

        // Poll rather than sleep: a large upload over a local socket is fast but
        // not instant, and a fixed wait is either flaky or slow.
        $deadline = time() + 30;
        do {
            $status = $this->getSession()->evaluateScript('return window.__solaProbeStatus;');
            if ($status !== null) {
                return;
            }
            usleep(200000);
        } while (time() < $deadline);

        throw new ExpectationException(
            'The transcription endpoint did not answer within 30 seconds. That is not a pass: '
                . 'the guard under test refuses in well under a second, so no answer means the '
                . 'request went somewhere it should not have.',
            $this->getSession()
        );
    }

    /**
     * Assert the status the endpoint actually returned.
     *
     * @Then /^the transcription endpoint should have refused with (?P<code>\d+)$/
     * @param int $code Expected HTTP status.
     * @throws ExpectationException
     */
    public function the_transcription_endpoint_should_have_refused_with(int $code): void {
        $status = (int) $this->getSession()->evaluateScript('return window.__solaProbeStatus;');

        if ($status === $code) {
            return;
        }

        $meaning = $status === 200
            ? 'The clip was ACCEPTED. The size guard did not stop the request, so this audio '
                . 'reached the transcription provider and was billed.'
            : 'Expected ' . $code . ' from the size guard.';

        throw new ExpectationException(
            $meaning . ' Got HTTP ' . $status . '.',
            $this->getSession()
        );
    }
}
