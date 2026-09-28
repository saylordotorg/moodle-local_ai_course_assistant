@local @local_ai_course_assistant
Feature: SOLA drawer interactions beyond send-receive
  As a student
  I want the header buttons (settings, reset, clear, help) to do what they say
  So that I can manage my chat state and access support without surprises

  Background:
    Given the following "courses" exist:
      | fullname     | shortname | format |
      | Test Course  | DI1       | topics |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Student   | One      | student1@example.com |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | DI1    | student |
    And the following config values are set as admin:
      | enabled             | 1    | local_ai_course_assistant |
      | provider            | stub | local_ai_course_assistant |
      | apikey              | x    | local_ai_course_assistant |
      | default_course_mode | all  | local_ai_course_assistant |
    And the following "user preferences" exist:
      | user     | preference                                | value |
      | student1 | aica_sola_consent_given                   | 1     |
      | student1 | local_ai_course_assistant_intro_dismissed | 1     |

  @javascript
  Scenario: Settings panel opens from the gear icon
    # The header gear button mounts an in-drawer settings panel for
    # language / avatar / voice toggles. Pinning the button-to-panel wire
    # so a refactor of the in-drawer settings UI surfaces here.
    Given I log in as "student1"
    And I am on "Test Course" course homepage
    When I click on "#local-ai-course-assistant-toggle" "css_element"
    And I click on ".local-ai-course-assistant__btn-settings-panel" "css_element"
    Then ".aica-settings-panel, .local-ai-course-assistant__settings-panel" "css_element" should be visible
    And ".aica-settings-panel, .local-ai-course-assistant__settings-panel" should not leak template syntax

  @javascript
  Scenario: Reset (home) icon shows starters without clearing message history
    # The home icon is documented (CLAUDE.md) as showing the starters
    # overlay WITHOUT clearing the message log. v5.3.x had a regression
    # where reset would also wipe history; this pin prevents recurrence.
    # We send a stub-provider message first so there is a message in the
    # log, then click reset, then assert starters re-appear AND the
    # earlier message is still in the scrollback.
    Given I log in as "student1"
    And I am on "Test Course" course homepage
    When I click on "#local-ai-course-assistant-toggle" "css_element"
    And I set the field "Ask a question..." to "Hello tutor"
    And I click on ".local-ai-course-assistant__btn-send" "css_element"
    Then I should see "Stub assistant reply" in the ".local-ai-course-assistant__messages" "css_element"
    When I click on ".local-ai-course-assistant__btn-reset" "css_element"
    Then ".local-ai-course-assistant__starters" "css_element" should be visible
    And I should see "Hello tutor" in the ".local-ai-course-assistant__messages" "css_element"

  @javascript
  Scenario: Help button surfaces the in-drawer help panel
    # The help button (question-mark icon) opens an in-drawer panel with
    # short feature explanations. The button is unconditional — pin it so
    # the v5.3.x null-guard refactor cannot silently drop the wiring again.
    Given I log in as "student1"
    And I am on "Test Course" course homepage
    When I click on "#local-ai-course-assistant-toggle" "css_element"
    And I click on ".local-ai-course-assistant__btn-help" "css_element"
    Then ".aica-help-panel" "css_element" should be visible
    # "Should be visible" is what this scenario asserted from v5.3 until
    # v7.5.4, and it passed for the seven weeks the panel was showing the
    # learner nine lines of our own engineering notes. Visible it was. These
    # two steps say what may NOT appear, which is the half that catches
    # anything.
    And ".aica-help-panel" should not leak template syntax
    And ".aica-help-panel" should contain readable text

  @javascript
  Scenario: A second send-receive cycle with the stub provider works after reset
    # Confirms the conversation_manager and SSE client both handle the
    # post-reset state correctly — sending a NEW message after pressing the
    # home icon should still produce a streamed reply, and the new message
    # plus the old one should both appear in the messages container.
    Given I log in as "student1"
    And I am on "Test Course" course homepage
    When I click on "#local-ai-course-assistant-toggle" "css_element"
    And I set the field "Ask a question..." to "First message"
    And I click on ".local-ai-course-assistant__btn-send" "css_element"
    Then I should see "Stub assistant reply" in the ".local-ai-course-assistant__messages" "css_element"
    When I click on ".local-ai-course-assistant__btn-reset" "css_element"
    And I set the field "Ask a question..." to "Second message"
    And I click on ".local-ai-course-assistant__btn-send" "css_element"
    Then I should see "First message" in the ".local-ai-course-assistant__messages" "css_element"
    And I should see "Second message" in the ".local-ai-course-assistant__messages" "css_element"

  @javascript
  Scenario: Opening the assistant puts the keyboard in it
    # The drawer has had role="dialog", an aria-label and a Tab trap since
    # v6.x, and until v7.5.5 it never took focus when it opened. Every
    # assertion about the markup passed the whole time, because the markup was
    # right; what was missing was one call, and its absence is only observable
    # as document.activeElement, which only a browser has.
    #
    # The consequence for a keyboard user was a dialog opening in front of them
    # with the keyboard still behind it, and a Tab trap that could not engage
    # because it acts only once focus is already inside.
    Given I log in as "student1"
    And I am on "Test Course" course homepage
    When I click on "#local-ai-course-assistant-toggle" "css_element"
    Then "#local-ai-course-assistant-drawer" "css_element" should be visible
    And focus should be inside "#local-ai-course-assistant-drawer"
    And focus should be on ".local-ai-course-assistant__input"

  @javascript
  Scenario: With consent still pending, focus goes into the consent notice
    # The case the first version of this fix missed, found in review.
    #
    # While consent is pending, consent_gate.js puts `inert` on every sibling of
    # the banner, which is the whole rest of the drawer. focus() on an inert
    # element is a silent no-op, so targeting the message box did nothing:
    # focus stayed on <body> and a screen reader announced nothing, in the one
    # case where a modal notice most needs to be found and read.
    #
    # The learner here has dismissed the intro but NOT given consent, which is
    # the state that isolates this. A brand-new learner does not reach it: the
    # welcome panel renders a moment later and focuses its own Continue button
    # in a requestAnimationFrame, so it wins whatever this code does. That is
    # also why the review's predicted symptom is not visible on a first-ever
    # open, and why this scenario sets intro_dismissed.
    #
    # Every other scenario in this plugin sets aica_sola_consent_given in its
    # Background, which is why none of them saw any of it.
    Given the following "users" exist:
      | username | firstname | lastname | email              |
      | newbie   | New       | Learner  | newbie@example.com |
    And the following "course enrolments" exist:
      | user   | course | role    |
      | newbie | DI1    | student |
    And the following "user preferences" exist:
      | user   | preference                                | value |
      | newbie | local_ai_course_assistant_intro_dismissed | 1     |
    And I log in as "newbie"
    And I am on "Test Course" course homepage
    When I click on "#local-ai-course-assistant-toggle" "css_element"
    Then "#local-ai-course-assistant-drawer" "css_element" should be visible
    And ".aica-consent-banner" "css_element" should be visible
    And focus should be inside "#local-ai-course-assistant-drawer"
    And focus should be on ".aica-consent-scroll"

  @javascript
  Scenario: After accepting the notice, reopening still puts focus in the drawer
    # Found in the third review round, in the fix from the second.
    #
    # Accepting consent sets banner.style.display = 'none' and calls release().
    # It never removes the banner and never marks the banner itself inert, since
    # inert only ever went on its siblings. So on every later open the selector
    # still matched, focus was aimed at a display:none scroll region, which
    # focus() ignores, and the input and drawer fallbacks were skipped because a
    # target had already been chosen.
    #
    # The learner accepted the notice, closed the drawer, reopened it, and was
    # back to focus on <body>. The same WCAG 2.4.3 defect, one interaction later,
    # introduced by the fix for the interaction before it.
    Given the following "users" exist:
      | username | firstname | lastname | email              |
      | newbie   | New       | Learner  | newbie@example.com |
    And the following "course enrolments" exist:
      | user   | course | role    |
      | newbie | DI1    | student |
    And the following "user preferences" exist:
      | user   | preference                                | value |
      | newbie | local_ai_course_assistant_intro_dismissed | 1     |
    And I log in as "newbie"
    And I am on "Test Course" course homepage
    When I click on "#local-ai-course-assistant-toggle" "css_element"
    And I read and accept the SOLA consent notice
    And I press the escape key
    Then "#local-ai-course-assistant-drawer" "css_element" should not be visible
    When I click on "#local-ai-course-assistant-toggle" "css_element"
    Then "#local-ai-course-assistant-drawer" "css_element" should be visible
    And focus should be inside "#local-ai-course-assistant-drawer"
    And focus should be on ".local-ai-course-assistant__input"

  @javascript
  Scenario: Reopening after the History tab still puts focus in the message box
    # Found in the fourth review round, and the reason the focus code stopped
    # predicting which element can take focus.
    #
    # The drawer keeps its --mode-history class between opens, and the CSS hides
    # the input area with display:none in that mode. handleToggle called
    # UI.toggleDrawer() before setBottomMode('chat'), so the focus code chose an
    # input that was not being rendered, focus() did nothing, and the learner
    # got no focus at all. Nothing was inert, so every inert-aware check passed
    # it as a fine target.
    #
    # Two changes make this pass: setBottomMode now runs before the drawer
    # opens, and the focus code verifies document.activeElement instead of
    # trusting that its chosen element accepted focus.
    Given I log in as "student1"
    And I am on "Test Course" course homepage
    When I click on "#local-ai-course-assistant-toggle" "css_element"
    And I click on "[data-mode=\"history\"]" "css_element"
    And I press the escape key
    Then "#local-ai-course-assistant-drawer" "css_element" should not be visible
    When I click on "#local-ai-course-assistant-toggle" "css_element"
    Then "#local-ai-course-assistant-drawer" "css_element" should be visible
    And focus should be inside "#local-ai-course-assistant-drawer"
    And focus should be on ".local-ai-course-assistant__input"

  @javascript
  Scenario: A brand-new learner cannot dismiss the intro past a pending consent notice
    # Found in the fourth review round. The most common first run of all, and
    # no scenario could see it: every consent scenario sets intro_dismissed,
    # and every other scenario sets consent. This one sets neither.
    #
    # showIntroModal inserts the welcome panel as a NEW child of the drawer and
    # focuses its Continue button a frame later. consent_gate.js sealed the
    # drawer's children with `inert` once, at init, so a panel inserted after
    # that snapshot escapes the seal entirely. The result was a Continue button
    # focused, tabbable and clickable in front of a modal consent notice the
    # learner had not read: the intro could be dismissed without the notice
    # ever being acknowledged.
    #
    # The panel now marks itself inert while consent is pending and releases
    # that when the notice does, via a MutationObserver on the pending class.
    # Marking it inert without the release would have been worse than the bug:
    # a Continue button the learner can see and never press.
    Given the following "users" exist:
      | username | firstname | lastname | email            |
      | fresh    | Fresh     | Start    | fresh@example.com |
    And the following "course enrolments" exist:
      | user  | course | role    |
      | fresh | DI1    | student |
    And I log in as "fresh"
    And I am on "Test Course" course homepage
    When I click on "#local-ai-course-assistant-toggle" "css_element"
    Then ".aica-consent-banner" "css_element" should be visible
    And focus should be inside "#local-ai-course-assistant-drawer"
    And the welcome panel should be sealed while consent is pending

  @javascript
  Scenario: On a phone the assistant takes focus without opening the keyboard
    # The mobile half of the same fix, and the reason it is not simply "focus
    # the message box". Focusing a textarea on a phone opens the on-screen
    # keyboard, which would cover the drawer the learner just opened with a
    # keyboard they did not ask for. So under 600px focus goes to the dialog
    # container instead: still inside, still announced by its aria-label, the
    # Tab trap still engages on the first Tab, and nothing is typed into until
    # the learner picks the message box themselves.
    #
    # 600px is the breakpoint updatePagePush already uses to decide the drawer
    # overlays rather than pushes the page.
    Given I log in as "student1"
    And I am on "Test Course" course homepage
    And I change window size to "mobile"
    When I click on "#local-ai-course-assistant-toggle" "css_element"
    Then "#local-ai-course-assistant-drawer" "css_element" should be visible
    And focus should be inside "#local-ai-course-assistant-drawer"
    And focus should be on "#local-ai-course-assistant-drawer"

  @javascript
  Scenario: Every panel a learner can open is free of template artefacts
    # The bottom-nav panels, Progress and History, are reachable by any learner
    # and were reached by no scenario until v7.5.4. The help panel was in the
    # same position: it HAD a scenario, which asserted only that it was visible,
    # and it stayed green through seven weeks of showing internal notes.
    #
    # This walks the learner-reachable surfaces and asserts the thing that is
    # cheap to check and expensive to miss: no Mustache tag, no unresolved
    # branding token, no unsubstituted placeholder, no raw string key.
    #
    # The Progress control does not exist unless mastery AND the mastery
    # dashboard are on for THIS course. The first version of this scenario
    # clicked it without turning either on and failed with "not found", which was
    # the truthful answer: there was no control. The second gate's config key
    # contains the course id, so it cannot be written in a Background table
    # before the generator has assigned one; hence a step, not a row.
    Given the mastery progress tab is enabled for course "DI1"
    And I log in as "student1"
    And I am on "Test Course" course homepage
    When I click on "#local-ai-course-assistant-toggle" "css_element"
    Then "#local-ai-course-assistant-drawer" should not leak template syntax
    And I click on "[data-mode=\"progress\"]" "css_element"
    And ".local-ai-course-assistant__progress-panel" should not leak template syntax
    And I click on "[data-mode=\"history\"]" "css_element"
    And ".local-ai-course-assistant__history-panel" should not leak template syntax
    And I click on "[data-mode=\"chat\"]" "css_element"
    And ".local-ai-course-assistant__starters" should not leak template syntax

  @javascript
  Scenario: An oversized recording is refused before it reaches the provider
    # The guard this pins is at transcribe.php, and until now nothing proved it
    # FIRES. PHPUnit cannot enter that file: it declares AJAX_SCRIPT and gates on
    # is_uploaded_file(), so the unit tests can only read the source and assert the
    # guard is present and correctly ordered. Deleting just the `exit;` from the
    # guard body leaves every one of those assertions green while an oversized clip
    # goes to the provider and is billed, and the provider's JSON refusal reaches
    # the learner base64-encoded as audio that decodes to silence.
    #
    # A real browser POST carries the session cookie and the page's sesskey, so it
    # satisfies require_login, require_sesskey and require_capability and arrives at
    # the guard the way a learner's own recording does.
    #
    # The first version of this scenario got HTTP 200 and I could not say why,
    # because the step captured only the status. The answer, once the body was
    # captured: PHP discards a body over post_max_size (default 8 MB) in its
    # entirety, so $_POST and $_FILES both arrive empty, require_sesskey throws on
    # a sesskey that went in the bin with the audio, and Moodle's AJAX handler
    # answers that at HTTP 200. MAX_AUDIO_BYTES is 25 MB, so on a stock PHP the
    # 25 MB guard was never the guard that ran. transcribe.php now detects the
    # discarded body before require_sesskey and answers 413 itself, so this
    # scenario gets the same answer whatever post_max_size is set to.
    #
    # 26214401 bytes is one over security::MAX_AUDIO_BYTES (25 * 1024 * 1024).
    Given I log in as "student1"
    And I am on "Test Course" course homepage
    When I post a 26214401 byte audio clip to the transcription endpoint
    Then the transcription endpoint should have refused with 413

  @javascript
  Scenario: An empty recording is refused before it reaches the provider
    # The same guard, the cheap half of it: the condition is `$size <= 0 || $size >
    # MAX`, so a zero-byte clip exercises the identical refusal without pushing 26MB
    # through the browser. Worth having as well as the oversized case, because a
    # microphone that produced nothing is the failure a learner actually hits, and
    # because if the oversized scenario ever gets skipped for being slow this one
    # still holds the line.
    Given I log in as "student1"
    And I am on "Test Course" course homepage
    When I post a 0 byte audio clip to the transcription endpoint
    Then the transcription endpoint should have refused with 413

