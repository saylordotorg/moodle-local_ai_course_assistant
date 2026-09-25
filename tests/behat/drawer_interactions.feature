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
  Scenario: Every panel a learner can open is free of template artefacts
    # The bottom-nav panels, Progress and History, are reachable by any learner
    # and were reached by no scenario until v7.5.4. The help panel was in the
    # same position: it HAD a scenario, which asserted only that it was visible,
    # and it stayed green through seven weeks of showing internal notes.
    #
    # This walks the learner-reachable surfaces and asserts the thing that is
    # cheap to check and expensive to miss: no Mustache tag, no unresolved
    # branding token, no unsubstituted placeholder, no raw string key.
    Given I log in as "student1"
    And I am on "Test Course" course homepage
    When I click on "#local-ai-course-assistant-toggle" "css_element"
    Then "#local-ai-course-assistant-drawer" should not leak template syntax
    And I click on "[data-mode=\"progress\"]" "css_element"
    And ".local-ai-course-assistant__progress-panel" should not leak template syntax
    And I click on "[data-mode=\"history\"]" "css_element"
    And ".local-ai-course-assistant__history-panel" should not leak template syntax
    And I click on "[data-mode=\"chat\"]" "css_element"
    And ".local-ai-course-assistant__starters" should not leak template syntax
