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
            window.__solaProbeBody = null;
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
                    return r.text().then(function (body) {
                        window.__solaProbeBody = (body || '').substring(0, 400);
                        window.__solaProbeStatus = r.status;
                    });
                }).catch(function (e) {
                    window.__solaProbeBody = 'fetch failed: ' + e;
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

        $body = (string) $this->getSession()->evaluateScript('return window.__solaProbeBody;');

        // The body is what names the answer. Moodle's AJAX exception handler emits
        // HTTP 200 for ANY uncaught exception in an AJAX_SCRIPT, so a 200 here does
        // not mean the upload succeeded; it usually means the request never reached
        // the guard at all. Without the body that is indistinguishable from the
        // guard failing to fire, which is the difference this step exists to report.
        throw new ExpectationException(
            $meaning . ' Got HTTP ' . $status . '. Body: ' . $body,
            $this->getSession()
        );
    }

    /**
     * Turn on both gates the Progress tab needs, for one course, at run time.
     *
     * The Progress button and panel are wrapped in {{#masterydashboardenabled}},
     * which objective_manager::is_dashboard_enabled_for_course() grants only when
     * mastery is enabled for the course AND mastery_dashboard_enabled_course_<id>
     * is set. The second key has no site-wide fallback, so it cannot be expressed
     * in a Background config table: the key contains the course id, which does not
     * exist until the generator has run.
     *
     * A scenario that clicks Progress without this gets "not found", which is the
     * truthful answer. The control is absent, not hidden.
     *
     * @Given /^the mastery progress tab is enabled for course "(?P<shortname>[^"]*)"$/
     * @param string $shortname Course shortname.
     */
    public function the_mastery_progress_tab_is_enabled_for_course(string $shortname): void {
        global $DB;

        $courseid = (int) $DB->get_field('course', 'id', ['shortname' => $shortname]);
        if ($courseid <= 0) {
            // MUST_EXIST's own message is "Can't find data record in database table
            // course", which does not say which course, and the shortname is the one
            // thing likely to be wrong here: it comes from the feature's Background,
            // not from anything this step can see.
            throw new ExpectationException(
                'No course with shortname "' . $shortname . '". Check it against the'
                    . ' Background of this feature.',
                $this->getSession()
            );
        }
        \local_ai_course_assistant\objective_manager::set_enabled_for_course($courseid, true);
        \local_ai_course_assistant\objective_manager::set_dashboard_enabled_for_course($courseid, true);
    }

    /**
     * Assert that keyboard focus is inside the given element.
     *
     * A dialog that opens without taking focus is the defect this pins. It is
     * invisible to every assertion about markup: the drawer is present, it has
     * role="dialog", it has an aria-label, and a focus trap is bound to it. All
     * of that was true while a keyboard user pressing Tab walked the page behind
     * the open dialog, because the trap only acts once activeElement is already
     * the first or last control inside the drawer, and nothing had put it there.
     *
     * document.activeElement is the only thing that distinguishes the two, and
     * only a real browser has one.
     *
     * @Then /^focus should be inside "(?P<selector>[^"]*)"$/
     * @param string $selector CSS selector for the container.
     * @throws ExpectationException
     */
    public function focus_should_be_inside(string $selector): void {
        $escaped = json_encode($selector);
        $script = <<<JS
            (function () {
                var box = document.querySelector({$escaped});
                var active = document.activeElement;
                if (!box) { return 'NO CONTAINER'; }
                if (!active) { return 'NO ACTIVE ELEMENT'; }
                if (!box.contains(active)) {
                    return 'OUTSIDE: ' + active.tagName.toLowerCase()
                        + (active.className ? '.' + String(active.className).split(' ').join('.') : '');
                }
                return 'INSIDE';
            })();
JS;
        $result = (string) $this->getSession()->evaluateScript('return ' . trim($script));

        if ($result !== 'INSIDE') {
            throw new ExpectationException(
                'Focus is not inside "' . $selector . '": ' . $result
                    . '. A dialog that opens without taking focus leaves a keyboard user'
                    . ' tabbing through the page behind it, and its focus trap never engages.',
                $this->getSession()
            );
        }
    }

    /**
     * Assert that keyboard focus is on exactly the given element.
     *
     * Stricter than focus_should_be_inside(), and needed because the drawer has
     * two correct answers depending on viewport. On a desktop width focus goes to
     * the message box, which is what someone opening an assistant wants. Under
     * 600px it goes to the dialog container instead: focusing a textarea on a
     * phone opens the on-screen keyboard, which would cover the drawer the
     * learner just opened. Both put focus inside the dialog; only this step can
     * tell which one actually happened.
     *
     * @Then /^focus should be on "(?P<selector>[^"]*)"$/
     * @param string $selector CSS selector for the element expected to have focus.
     * @throws ExpectationException
     */
    public function focus_should_be_on(string $selector): void {
        $escaped = json_encode($selector);
        $script = <<<JS
            (function () {
                var want = document.querySelector({$escaped});
                var active = document.activeElement;
                if (!want) { return 'NO SUCH ELEMENT'; }
                if (!active) { return 'NO ACTIVE ELEMENT'; }
                if (want === active) { return 'MATCH'; }
                return 'ON: ' + active.tagName.toLowerCase()
                    + (active.id ? '#' + active.id : '')
                    + (active.className ? '.' + String(active.className).trim().split(/\s+/).join('.') : '');
            })();
JS;
        $result = (string) $this->getSession()->evaluateScript('return ' . trim($script));

        if ($result !== 'MATCH') {
            throw new ExpectationException(
                'Focus is not on "' . $selector . '": ' . $result . '.',
                $this->getSession()
            );
        }
    }

    /**
     * Read and accept the consent notice the way a learner has to.
     *
     * The Accept button starts disabled and consent_gate.js only enables it once
     * the notice has been scrolled to the bottom, or once it is short enough not
     * to need scrolling. A step that clicked the button directly would be
     * clicking a disabled control, so this scrolls first and waits for the gate
     * to release it.
     *
     * @Given /^I read and accept the SOLA consent notice$/
     * @throws ExpectationException
     */
    public function i_read_and_accept_the_sola_consent_notice(): void {
        $this->getSession()->evaluateScript(
            "(function () {"
            . " var s = document.querySelector('.aica-consent-scroll');"
            . " if (s) { s.scrollTop = s.scrollHeight; s.dispatchEvent(new Event('scroll')); }"
            . "})();"
        );

        // The gate reacts to the scroll event and to a ResizeObserver, so give
        // it a moment rather than assuming the next statement sees the result.
        $this->spin(
            function () {
                $enabled = $this->getSession()->evaluateScript(
                    "return !!document.querySelector('.aica-consent-accept:not([disabled])');"
                );
                if (!$enabled) {
                    throw new ExpectationException(
                        'The consent Accept button is still disabled after scrolling the notice.',
                        $this->getSession()
                    );
                }
                return true;
            },
            false,
            10
        );

        $this->execute('behat_general::i_click_on', ['.aica-consent-accept', 'css_element']);
    }

    /**
     * The welcome panel must not be reachable while the consent notice is up.
     *
     * Asserts the three things that together make it unreachable, because any
     * one of them alone can be true while the learner still gets to the button:
     * the panel carries `inert`, focus is not on its Continue button, and the
     * button reports itself as not focusable. The middle one is what actually
     * went wrong: the panel focused its own button a frame after the drawer had
     * correctly focused the notice.
     *
     * @Then /^the welcome panel should be sealed while consent is pending$/
     * @throws ExpectationException
     */
    public function the_welcome_panel_should_be_sealed_while_consent_is_pending(): void {
        $result = (string) $this->getSession()->evaluateScript(
            "return (function () {"
            . " var panel = document.querySelector('.local-ai-course-assistant__welcome');"
            . " if (!panel) { return 'NO PANEL'; }"
            . " if (!panel.hasAttribute('inert')) { return 'PANEL NOT INERT'; }"
            . " var cta = panel.querySelector('.local-ai-course-assistant__welcome-cta');"
            . " if (cta && document.activeElement === cta) { return 'FOCUS ON CONTINUE'; }"
            . " if (cta) {"
            . "   cta.focus();"
            . "   if (document.activeElement === cta) { return 'CONTINUE STILL FOCUSABLE'; }"
            . " }"
            . " return 'SEALED';"
            . "})();"
        );

        if ($result !== 'SEALED') {
            throw new ExpectationException(
                'The welcome panel is reachable while the consent notice is pending: ' . $result
                    . '. A learner could dismiss the intro without the notice ever being read.',
                $this->getSession()
            );
        }
    }
}
