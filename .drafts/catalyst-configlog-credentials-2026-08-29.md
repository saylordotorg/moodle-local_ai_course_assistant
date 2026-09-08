Subject: Credentials stored in clear text in config_log on Learn and Degrees

Hi team,

We found API keys and secrets sitting in clear text in the Moodle configuration
change log on both production sites, and would like your help clearing them.

Most of them are not ours. Flagging the whole picture rather than only the SOLA
entry, since the remedy is the same query for all of them.

What we found

34 credential values are readable at /report/configlog by anyone who can reach
site administration: 24 on Learn, 10 on Degrees. The oldest dates from November
2014, the newest from August 2026.

Learn
   block_openai_chat          apikey                     7
   core                       discoursesso_api_key       3
   core                       recaptchaprivatekey        3
   qtype_coderunner           jobe_apikey                3
   auth_nsdc                  apikey                     2
   local_corolair             apikey                     2
   core                       accredible_api_key         1
   core                       discoursesso_secret_key    1
   core                       eportlink_api_key          1
   local_ai_course_assistant  redash_api_key             1

Degrees
   local_ai_course_assistant  redash_api_key             4
   block_openai_chat          apikey                     3
   core                       recaptchaprivatekey        1
   local_corolair             apikey                     1
   qtype_coderunner           jobe_apikey                1

We identified these with a read-only query that returns the setting name, the
column, the value length and the date. No credential value was returned by the
query, printed, or copied anywhere, and none appears in this email.

Why it happens

Registering a setting as a password field masks it when an administrator saves it
through the settings form. It does nothing when the value is written by a
command-line script, an install step or an upgrade step, and core logs those
verbatim. That is why the SOLA provider keys are correctly masked on production
while redash_api_key is not: the provider keys were set through the form and that
one was not.

Two columns are affected, not one. config_log stores both value and oldvalue, and
/report/configlog renders oldvalue as "Original value" — just as readable. Seven
of Learn's 24 and three of Degrees' 10 are in that second column.

What we would like

Overwrite the affected values while keeping the log rows themselves. Who changed
which setting and when is exactly what the log is for; only the value needs to
go. We are not asking for row deletion.

Two ways to do it, whichever suits you:

1. A direct UPDATE on mdl_config_log setting value and oldvalue to a masked
   string for the affected rows. Happy to send you the exact WHERE clause.

2. Once SOLA is upgraded on production, our plugin ships a command that does this
   site-wide across every plugin, not just ours:
   php local/ai_course_assistant/admin/cli/audit_config_log_secrets.php --redact
   It reports before it changes anything, never prints a value, and preserves the
   rows. This arrives with v7.2.5, which is the version in the upgrade estimate we
   asked you about separately.

One ordering point that is easy to miss

Redaction is not rotation, and rotating re-creates the problem. When a
replacement key is saved, core writes the retired key into the next log row's
oldvalue column. So the sequence has to be: rotate, then redact, then re-check —
not the other way around.

We will handle rotation of the SOLA and Redash credentials ourselves. The
Discourse, reCAPTCHA, CodeRunner, Accredible, eportlink, auth_nsdc, Corolair and
block_openai_chat keys are not ours; let us know if you would rather raise those
with the relevant owners or if we should.

No evidence any of these has been misused. Treating them as disclosed because
they were readable, not because we think anything happened.

Thanks,
Tom
