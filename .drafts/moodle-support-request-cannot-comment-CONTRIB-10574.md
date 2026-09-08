Subject: Cannot add a comment to my plugin approval issue CONTRIB-10574

Hello,

I am the lead maintainer of the plugin under review in CONTRIB-10574 ("Plugin
approval: AI Course Assistant / local_ai_course_assistant"):

    https://moodle.atlassian.net/browse/CONTRIB-10574

I need to tell the reviewer that a new version is uploaded and to answer points
from their last comment, but I am unable to add a comment to the issue.

WHAT I SEE

The issue itself is readable. I can open it, read the description and both
reviewer comments. What I cannot do is reply: no comment box or "Add comment"
control is rendered anywhere on the page.

This is a permission state rather than a browser or interface problem. Querying
the Jira permissions API for my own account against that specific issue returns:

    GET /rest/api/3/mypermissions?issueKey=CONTRIB-10574&permissions=ADD_COMMENTS
    -> ADD_COMMENTS: havePermission = false
       BROWSE_PROJECTS: havePermission = true

So the account can browse the project but is not granted comment permission on
it. A second Atlassian account associated with our organization returns the same
result, which suggests it is a project-level grant rather than anything specific
to my user.

The Moodle Marketplace page for the plugin describes CONTRIB-10574 as a JSM
ticket, so I also tried the customer portal at

    https://moodle.atlassian.net/servicedesk/customer/portal/166/CONTRIB-10574

which asks for a separate customer login I do not appear to have set up against
the address the review is associated with.

WHAT I AM TRYING TO SAY ON THE ISSUE

Briefly, so you can judge urgency:

1. The reviewer's last comment, on 26 June, asked us to upload the latest plugin
   version. We have done so: version 7.0.5 (build 2026082400) was uploaded to the
   Marketplace on 24 August 2026, and it is the version we would like reviewed.
   Two further uploads happened between their comment and that one, so I want to
   make sure the review is not sitting on a superseded file.

2. One of those releases is a security release we initiated ourselves, and it
   changes two of the answers we previously gave the reviewer in their favor.
   That seems worth putting on the record before they spend time on the older
   responses.

3. There are two automated-test findings we believe are false positives, where we
   would like to set out evidence rather than ship a change that fixes nothing.

WHAT WOULD HELP

Any one of these would unblock me:

- Grant comment permission on CONTRIB-10574 to the Atlassian account associated
  with this request, or
- Tell me which account or portal login I should be using to reply to a plugin
  approval issue, and I will use that, or
- If maintainers are not expected to comment on approval issues at all, tell me
  the correct channel for replying to a reviewer and I will use it instead.

I am not asking anyone to act on the substance of the review here. I only need a
way to reply on the issue.

Thank you,
Tom Caswell
Saylor Academy
tom.caswell@saylor.org
