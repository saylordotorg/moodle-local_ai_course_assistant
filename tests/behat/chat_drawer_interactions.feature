@local @local_ai_course_assistant
Feature: SOLA chat drawer interactions
  As a learner
  I want the chat drawer to open, close, and route me to settings / help / data
  So that I can use SOLA without losing track of where I am in the UI

  Background:
    Given the following "courses" exist:
      | fullname     | shortname | format |
      | Test Course  | DRWR1     | topics |
    And the following "users" exist:
      | username | firstname | lastname | email             |
      | learner1 | Learner   | One      | l1@example.com    |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | learner1 | DRWR1  | student |
    And the following config values are set as admin:
      | enabled             | 1      | local_ai_course_assistant |
      | provider            | claude | local_ai_course_assistant |
      | default_course_mode | all    | local_ai_course_assistant |
      | voice_tab_enabled   | 0      | local_ai_course_assistant |

  @javascript
  Scenario: Help panel opens via question-mark button
    Given I log in as "learner1"
    And I am on "Test Course" course homepage
    When I click on "#local-ai-course-assistant-toggle" "css_element"
    And I click on ".local-ai-course-assistant__btn-help" "css_element"
    Then ".aica-help-panel" "css_element" should be visible

  @javascript
  Scenario: Help panel hides Voice section when voice tab is off (regression for v5.3.3)
    Given I log in as "learner1"
    And I am on "Test Course" course homepage
    When I click on "#local-ai-course-assistant-toggle" "css_element"
    And I click on ".local-ai-course-assistant__btn-help" "css_element"
    Then ".aica-help-panel" "css_element" should be visible
    # When voiceenabled is false (no voice provider configured) AND
    # voicetabenabled is false, the Voice section should not appear at all.
    And I should not see "switch to the Voice tab" in the ".aica-help-panel" "css_element"

  @javascript
  Scenario: Help panel mentions My SOLA data (regression for v5.3.4)
    Given I log in as "learner1"
    And I am on "Test Course" course homepage
    When I click on "#local-ai-course-assistant-toggle" "css_element"
    And I click on ".local-ai-course-assistant__btn-help" "css_element"
    Then I should see "My SOLA data" in the ".aica-help-panel" "css_element"

  @javascript
  Scenario: Reset button is rendered in the header
    # Asserts the button exists in the mustache-rendered DOM. Click
    # behaviour is hard to assert deterministically because the starters
    # overlay timing depends on JS hydration; the existence assertion is
    # the load-bearing one for catching template regressions.
    Given I log in as "learner1"
    And I am on "Test Course" course homepage
    When I click on "#local-ai-course-assistant-toggle" "css_element"
    Then ".local-ai-course-assistant__btn-reset" "css_element" should exist

  @javascript
  Scenario: Close button is rendered in the header
    Given I log in as "learner1"
    And I am on "Test Course" course homepage
    When I click on "#local-ai-course-assistant-toggle" "css_element"
    Then ".local-ai-course-assistant__btn-close" "css_element" should exist

  @javascript
  Scenario: Clear button is rendered in the header
    Given I log in as "learner1"
    And I am on "Test Course" course homepage
    When I click on "#local-ai-course-assistant-toggle" "css_element"
    Then ".local-ai-course-assistant__btn-clear" "css_element" should exist
