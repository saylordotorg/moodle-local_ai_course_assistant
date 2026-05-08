@local @local_ai_course_assistant
Feature: GDPR self-service data page
  As a learner
  I want to download or delete the data SOLA has stored about me
  So that I can exercise my privacy rights without asking an admin

  Background:
    Given the following "courses" exist:
      | fullname     | shortname | format |
      | Test Course  | GDPR1     | topics |
    And the following "users" exist:
      | username | firstname | lastname | email             |
      | learner1 | Learner   | One      | l1@example.com    |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | learner1 | GDPR1  | student |
    And the following config values are set as admin:
      | enabled             | 1      | local_ai_course_assistant |
      | provider            | claude | local_ai_course_assistant |
      | default_course_mode | all    | local_ai_course_assistant |

  Scenario: Self-service page renders the delete-all button
    Given I log in as "learner1"
    When I visit "/local/ai_course_assistant/settings_user.php"
    Then I should see "Delete All My Data"

  @javascript
  Scenario: Delete-all uses POST so a hosting WAF cannot strip the action
    Given I log in as "learner1"
    When I visit "/local/ai_course_assistant/settings_user.php"
    Then "input[name='action'][value='delete_all']" "css_element" should exist
    And "input[name='sesskey']" "css_element" should exist

  @javascript
  Scenario: Download button is a POST form
    Given I log in as "learner1"
    When I visit "/local/ai_course_assistant/settings_user.php"
    Then "input[name='action'][value='download']" "css_element" should exist

  Scenario: Delete-all flow goes through confirmation and reports success
    # Real form submit, confirm page, success notification — exercises the
    # path that silently no-op'd on Catalyst staging when the WAF stripped
    # the action POST. The rsynced fix turned both buttons into POST forms;
    # this scenario asserts the click-confirm-redirect path actually works.
    Given I log in as "learner1"
    And I visit "/local/ai_course_assistant/settings_user.php"
    When I press "Delete All My Data"
    # Moodle's confirm() output ships with "Continue" / "Cancel" buttons.
    Then I should see "Are you sure you want to permanently delete"
    When I press "Continue"
    Then I should see "Your data has been deleted"
