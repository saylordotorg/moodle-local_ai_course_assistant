@local @local_ai_course_assistant
Feature: AI Course Assistant widget
  As a student
  I want to use the AI tutor chat widget on course pages
  So that I can get help understanding course material

  Background:
    Given the following "courses" exist:
      | fullname     | shortname | format |
      | Test Course  | TC1       | topics |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Student   | One      | student1@example.com |
      | teacher1 | Teacher   | One      | teacher1@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | TC1    | student        |
      | teacher1 | TC1    | editingteacher |
    And the following config values are set as admin:
      | enabled             | 1      | local_ai_course_assistant |
      | provider            | claude | local_ai_course_assistant |
      | default_course_mode | all    | local_ai_course_assistant |
    # Pre-grant SOLA consent so the first-run consent banner does not
    # overlay the drawer interior and intercept clicks. The banner is
    # required for production GDPR/FERPA compliance but obstructs Behat
    # interactions; the existing escape-key scenario only worked because
    # escape is a keyboard event, not a click.
    And the following "user preferences" exist:
      | user     | preference              | value |
      | student1 | aica_sola_consent_given | 1     |
      | teacher1 | aica_sola_consent_given | 1     |

  Scenario: Student sees chat widget on course page
    Given I log in as "student1"
    When I am on "Test Course" course homepage
    Then "#local-ai-course-assistant-toggle" "css_element" should exist

  @javascript
  Scenario: Student can open and close chat drawer
    Given I log in as "student1"
    And I am on "Test Course" course homepage
    When I click on "#local-ai-course-assistant-toggle" "css_element"
    Then "#local-ai-course-assistant-drawer" "css_element" should be visible
    When I press the escape key
    Then "#local-ai-course-assistant-drawer" "css_element" should not be visible

  @javascript
  Scenario: Drawer renders conversation starter buttons on open
    # The starters overlay is what students see first. Several v5.3 bugs
    # shipped with empty starter labels or a crashing render — this asserts
    # the overlay actually exposes clickable buttons with non-empty text.
    Given I log in as "student1"
    And I am on "Test Course" course homepage
    When I click on "#local-ai-course-assistant-toggle" "css_element"
    Then ".local-ai-course-assistant__starters" "css_element" should be visible
    And ".local-ai-course-assistant__starter" "css_element" should exist

  @javascript
  Scenario: Close button closes the drawer
    # Companion to the escape-key scenario — verifies the X icon in the header
    # actually wires up to the close handler. This was silently broken once
    # when null-guards were added to bindEvents.
    Given I log in as "student1"
    And I am on "Test Course" course homepage
    When I click on "#local-ai-course-assistant-toggle" "css_element"
    Then "#local-ai-course-assistant-drawer" "css_element" should be visible
    When I click on ".local-ai-course-assistant__btn-close" "css_element"
    Then "#local-ai-course-assistant-drawer" "css_element" should not be visible

  Scenario: Chat widget not visible when plugin is disabled
    Given the following config values are set as admin:
      | enabled | 0 | local_ai_course_assistant |
    And I log in as "student1"
    When I am on "Test Course" course homepage
    Then "#local-ai-course-assistant-toggle" "css_element" should not exist

  Scenario: Teacher sees chat widget on course page
    Given I log in as "teacher1"
    When I am on "Test Course" course homepage
    Then "#local-ai-course-assistant-toggle" "css_element" should exist

  Scenario: Chat widget not visible on site homepage
    Given I log in as "student1"
    When I am on site homepage
    Then "#local-ai-course-assistant-toggle" "css_element" should not exist
