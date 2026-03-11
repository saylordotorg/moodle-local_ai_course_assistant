<?php
// One-time script to create the "Getting Started with Claude Code" course.
// Access: /local/ai_course_assistant/create_demo_course.php
// Remove this file after use.

require_once(__DIR__ . '/../../config.php');
require_login();
require_capability('moodle/site:config', \context_system::instance());

require_once($CFG->dirroot . '/course/lib.php');

$PAGE->set_context(\context_system::instance());
$PAGE->set_url(new moodle_url('/local/ai_course_assistant/create_demo_course.php'));
$PAGE->set_pagelayout('admin');

// Check if course already exists.
$existingid = $DB->get_field('course', 'id', ['shortname' => 'CLAUDECODE101']);
$coursecreated = false;

if ($existingid) {
    $courseid = $existingid;
    // Ensure section count is up to date.
    $formatoptions = $DB->get_record('course_format_options', [
        'courseid' => $courseid, 'name' => 'numsections', 'format' => 'topics',
    ]);
    if ($formatoptions && (int)$formatoptions->value < 10) {
        $formatoptions->value = 10;
        $DB->update_record('course_format_options', $formatoptions);
    }
    // Ensure section records exist.
    $existingsections = $DB->count_records('course_sections', ['course' => $courseid]);
    for ($i = $existingsections; $i <= 10; $i++) {
        $newsec = new stdClass();
        $newsec->course = $courseid;
        $newsec->section = $i;
        $newsec->summary = '';
        $newsec->summaryformat = FORMAT_HTML;
        $newsec->visible = 1;
        if (!$DB->record_exists('course_sections', ['course' => $courseid, 'section' => $i])) {
            $DB->insert_record('course_sections', $newsec);
        }
    }
} else {
    // Create the course.
    $coursedata = new stdClass();
    $coursedata->fullname = 'Getting Started with Claude Code';
    $coursedata->shortname = 'CLAUDECODE101';
    $coursedata->category = 1;
    $coursedata->summary = '<p>A practical guide for setting up and using Claude Code on Mac and PC, including how to organize projects with folders and .md files.</p>';
    $coursedata->summaryformat = FORMAT_HTML;
    $coursedata->format = 'topics';
    $coursedata->numsections = 10;
    $coursedata->visible = 1;
    $coursedata->enablecompletion = 1;

    $course = create_course($coursedata);
    $courseid = $course->id;
    $coursecreated = true;
}

// Define sections with their content.
$sections = [
    1 => [
        'name' => 'What is Claude Code?',
        'summary' => '
<h3>What is Claude Code?</h3>
<p>Claude Code is Anthropic\'s official command-line tool that lets you work with Claude directly in your terminal. It can read your files, search your codebase, write and edit code, run commands, and manage complex multi-step tasks.</p>
<p>Think of it as a senior developer sitting next to you who can see your entire project and make changes for you.</p>
<h4>What can it do?</h4>
<ul>
<li><strong>Read and understand</strong> your entire codebase</li>
<li><strong>Write and edit</strong> code across multiple files</li>
<li><strong>Run commands</strong> like tests, builds, and deploys</li>
<li><strong>Search</strong> for patterns, functions, and files</li>
<li><strong>Explain</strong> how code works</li>
<li><strong>Debug</strong> issues by reading logs and tracing code</li>
<li><strong>Commit and push</strong> changes to git</li>
</ul>
<p>Claude Code works in any terminal on Mac, Windows, or Linux, and also integrates into VS Code and Cursor as an extension.</p>',
    ],
    2 => [
        'name' => 'Installation: Mac',
        'summary' => '
<h3>Installing Claude Code on Mac</h3>
<h4>Step 1: Install Node.js</h4>
<p>Open Terminal (Applications > Utilities > Terminal) and run:</p>
<pre style="background:#f4f4f4;padding:12px;border-radius:6px;">brew install node</pre>
<p>If you don\'t have Homebrew yet, visit <strong>brew.sh</strong> and follow the one-line install command, then run the command above.</p>

<h4>Step 2: Install Claude Code</h4>
<pre style="background:#f4f4f4;padding:12px;border-radius:6px;">npm install -g @anthropic-ai/claude-code</pre>

<h4>Step 3: Launch Claude Code</h4>
<pre style="background:#f4f4f4;padding:12px;border-radius:6px;">cd /path/to/your/project
claude</pre>

<h4>Step 4: Authenticate</h4>
<p>On first launch, Claude Code will open a browser window to authenticate with your Anthropic account. Sign in and you\'re ready to go.</p>

<h4>VS Code / Cursor Integration</h4>
<p>Claude Code also works as an extension inside VS Code and Cursor. Search for "Claude Code" in the extensions marketplace and install it. This gives you Claude in a panel within your editor, with the same capabilities as the terminal version.</p>',
    ],
    3 => [
        'name' => 'Installation: Windows (PC)',
        'summary' => '
<h3>Installing Claude Code on Windows</h3>
<h4>Step 1: Install Node.js</h4>
<p>Download and install Node.js from <strong>nodejs.org</strong> (LTS version recommended). This includes npm automatically.</p>

<h4>Step 2: Open a Terminal</h4>
<p>Use PowerShell, Command Prompt, or Windows Terminal.</p>

<h4>Step 3: Install Claude Code</h4>
<pre style="background:#f4f4f4;padding:12px;border-radius:6px;">npm install -g @anthropic-ai/claude-code</pre>

<h4>Step 4: Launch Claude Code</h4>
<pre style="background:#f4f4f4;padding:12px;border-radius:6px;">cd C:\path\to\your\project
claude</pre>

<h4>Step 5: Authenticate</h4>
<p>On first launch, it will open a browser window. Sign in with your Anthropic account.</p>

<div style="background:#e7f3fe;border-left:4px solid #2196F3;padding:12px;margin:16px 0;border-radius:4px;">
<strong>Tip:</strong> Windows users may prefer using WSL (Windows Subsystem for Linux) for the best experience. Install WSL from the Microsoft Store, then follow the Mac/Linux instructions inside the WSL terminal.
</div>',
    ],
    4 => [
        'name' => 'Basic Usage',
        'summary' => '
<h3>Basic Usage</h3>
<p>Once you run <code>claude</code> inside a project folder, you can type natural language requests:</p>
<ul>
<li><code>Explain what this project does</code> &mdash; Claude reads your files and gives you an overview</li>
<li><code>Find all the API endpoints in this project</code> &mdash; searches the codebase</li>
<li><code>Add a "last modified" timestamp to the footer</code> &mdash; makes the code change for you</li>
<li><code>Fix the bug in login.php where the password check fails</code> &mdash; reads, diagnoses, and patches</li>
<li><code>Run the tests and fix anything that fails</code> &mdash; executes commands and iterates</li>
</ul>
<p>Claude will ask for permission before making changes or running commands. You can approve, deny, or modify what it proposes.</p>

<h4>Useful Slash Commands</h4>
<table border="1" cellpadding="8" cellspacing="0" style="border-collapse:collapse;width:100%;">
<tr style="background:#f0f0f0;"><th>Command</th><th>What it does</th></tr>
<tr><td><code>/help</code></td><td>Show all available commands and options</td></tr>
<tr><td><code>/clear</code></td><td>Clear the conversation and start fresh</td></tr>
<tr><td><code>/compact</code></td><td>Summarize the conversation to free up context space</td></tr>
<tr><td><code>/cost</code></td><td>Show token usage and cost for the current session</td></tr>
<tr><td><code>/init</code></td><td>Create a CLAUDE.md file for your project (covered in the next section)</td></tr>
</table>',
    ],
    5 => [
        'name' => 'CLAUDE.md: Your Project\'s Instruction Manual',
        'summary' => '
<h3>CLAUDE.md: Your Project\'s Instruction Manual</h3>
<p>Create a file called <code>CLAUDE.md</code> in the root of your project. This file is automatically loaded into every conversation. It tells Claude about your project: what it does, how to build it, coding conventions, important files, and anything else Claude should know.</p>

<h4>Example CLAUDE.md</h4>
<pre style="background:#f4f4f4;padding:16px;border-radius:6px;font-size:13px;line-height:1.5;">
# My Project

## Overview
This is a Django web application for managing
course enrollments. The database is PostgreSQL.
We deploy to AWS.

## Key Files
- settings.py: Django configuration
- apps/enrollment/views.py: Main enrollment logic
- apps/enrollment/models.py: Database models

## Build and Run
- Install dependencies: pip install -r requirements.txt
- Run locally: python manage.py runserver
- Run tests: pytest

## Conventions
- Use snake_case for Python, camelCase for JavaScript
- All API endpoints go in apps/api/views.py
- Never commit .env files or API keys
- Always write tests for new features

## Important Notes
- The enrollment deadline logic is in
  apps/enrollment/utils.py
- We use Celery for background tasks
</pre>

<h4>Tips for a Good CLAUDE.md</h4>
<ul>
<li><strong>Keep it under 200 lines</strong> &mdash; it\'s loaded into every conversation, so conciseness matters</li>
<li><strong>Focus on what Claude needs</strong> &mdash; build commands, file layout, conventions, gotchas</li>
<li><strong>Update it as your project evolves</strong> &mdash; treat it like living documentation</li>
<li><strong>Use <code>/init</code></strong> to have Claude generate a starter CLAUDE.md by analyzing your project</li>
</ul>',
    ],
    6 => [
        'name' => 'Project Folders and Memory',
        'summary' => '
<h3>Project Folders and Memory</h3>
<p>Claude Code stores project-specific settings and memory in a special directory:</p>
<ul>
<li><strong>Mac/Linux:</strong> <code>~/.claude/projects/&lt;project-path-hash&gt;/</code></li>
<li><strong>Windows:</strong> <code>%USERPROFILE%\.claude\projects\&lt;project-path-hash&gt;\</code></li>
</ul>

<h4>What Lives in Each Project Folder</h4>
<table border="1" cellpadding="8" cellspacing="0" style="border-collapse:collapse;width:100%;">
<tr style="background:#f0f0f0;"><th>File/Folder</th><th>Purpose</th></tr>
<tr><td><code>CLAUDE.md</code></td><td>Project-specific user instructions (private, not committed to git). Combined with the project root CLAUDE.md.</td></tr>
<tr><td><code>memory/MEMORY.md</code></td><td>Auto-memory: Claude saves patterns, preferences, and key decisions here. Persists between sessions.</td></tr>
<tr><td><code>memory/*.md</code></td><td>Additional topic-specific memory files (e.g., <code>deploy.md</code>, <code>debugging.md</code>)</td></tr>
</table>

<h4>The Key Distinction: Two CLAUDE.md Files</h4>
<ul>
<li><strong>Root CLAUDE.md</strong> (in your project folder, committed to git): shared with the whole team. Contains build instructions, conventions, architecture notes.</li>
<li><strong>Project folder CLAUDE.md</strong> (in <code>~/.claude/projects/</code>): personal and private. Contains your individual preferences, credential references, workflow shortcuts.</li>
</ul>

<h4>How Memory Works</h4>
<p>Claude automatically writes to <code>memory/MEMORY.md</code> across sessions. It remembers things like:</p>
<ul>
<li>"User prefers tabs over spaces"</li>
<li>"Deploy script is at scripts/deploy.sh"</li>
<li>"The API key issue was a missing header"</li>
</ul>
<p>You can also ask Claude to remember something explicitly: <em>"Remember that we always run migrations before deploying."</em></p>

<h4>Working with Multiple Projects</h4>
<p>Each time you run <code>claude</code> from a different directory, it loads the CLAUDE.md from that directory. This means you can have completely different instructions, conventions, and memory per project. Just <code>cd</code> into the project folder and run <code>claude</code>.</p>',
    ],
    7 => [
        'name' => 'Tips for Effective Use',
        'summary' => '
<h3>Tips for Getting the Most Out of Claude Code</h3>
<ol>
<li><strong>Be specific about what you want.</strong><br>"Fix the login bug" is okay. "Fix the login bug where users with special characters in their password can\'t sign in, the issue is in auth.py" is much better.</li>

<li><strong>Let Claude read before it writes.</strong><br>If you ask Claude to modify a file, it will read it first. If it doesn\'t, say "Read the file first before making changes."</li>

<li><strong>Use it for research.</strong><br>"How does the payment flow work in this codebase?" and "Find everywhere we use the deprecated getUserName method" are great use cases.</li>

<li><strong>Ask it to run tests.</strong><br>After making changes, say "Run the tests and fix anything that breaks." Claude will iterate until tests pass.</li>

<li><strong>Ask it to commit.</strong><br>When you\'re happy with changes, say "Commit these changes with a descriptive message." Claude will stage the right files and write a good commit message.</li>

<li><strong>Keep your CLAUDE.md current.</strong><br>The better your CLAUDE.md, the less you need to repeat context in every conversation. Think of it as onboarding documentation for your AI teammate.</li>

<li><strong>Ask Claude to remember things.</strong><br>Say "Remember that we always use pytest, not unittest" or "Remember that the staging server is at staging.example.com." It saves these to memory files that persist across sessions.</li>

<li><strong>Use /compact when conversations get long.</strong><br>Claude has a context window limit. If you\'ve been working for a while, <code>/compact</code> summarizes the conversation so you can keep going without losing important context.</li>
</ol>',
    ],
    8 => [
        'name' => 'Common Workflows',
        'summary' => '
<h3>Common Workflows</h3>
<table border="1" cellpadding="10" cellspacing="0" style="border-collapse:collapse;width:100%;font-size:14px;">
<tr style="background:#f0f0f0;"><th style="width:25%;">Task</th><th>What to say</th></tr>
<tr><td>Understand a new codebase</td><td>"Give me an overview of this project: what it does, the tech stack, and the key files"</td></tr>
<tr><td>Fix a bug</td><td>"Users report that the search page returns no results when the query has spaces. Find and fix the bug."</td></tr>
<tr><td>Add a feature</td><td>"Add a CSV export button to the reports page. It should include all visible columns."</td></tr>
<tr><td>Refactor</td><td>"Refactor the database queries in reports.py to use the ORM instead of raw SQL"</td></tr>
<tr><td>Write tests</td><td>"Write unit tests for the PaymentProcessor class in tests/test_payments.py"</td></tr>
<tr><td>Code review</td><td>"Review the changes I made today and flag any issues" (after staging changes with git)</td></tr>
<tr><td>Deploy</td><td>"Walk me through deploying this to production" (if deploy steps are in CLAUDE.md, it follows them)</td></tr>
<tr><td>Documentation</td><td>"Add docstrings to all public methods in api/views.py"</td></tr>
</table>

<h4>Example Session: Fixing a Bug</h4>
<pre style="background:#f4f4f4;padding:16px;border-radius:6px;font-size:13px;line-height:1.6;">
$ cd ~/projects/my-app
$ claude

You: Users report that the search page shows no results
     when the query contains spaces. Can you find and
     fix this?

Claude: [reads search controller, finds URL encoding issue,
        shows the fix, asks permission to apply it]

You: Yes, apply it.

Claude: [edits the file, then offers to run tests]

You: Run the tests.

Claude: [runs pytest, all pass]

You: Commit this fix.

Claude: [stages the file, writes commit message:
        "Fix search: URL-encode query parameters
         to handle spaces correctly"]
</pre>',
    ],
    9 => [
        'name' => 'Pricing, Resources, and Getting Help',
        'summary' => '
<h3>Pricing</h3>
<p>Claude Code uses your Anthropic API account. You can monitor usage at <strong>console.anthropic.com</strong>. A typical coding session (30 to 60 minutes of active use) costs roughly $1 to $5 depending on how much code Claude reads and generates. The <code>/cost</code> command shows your current session usage.</p>
<p>Alternatively, Claude Code is included with a Claude Pro subscription ($20/month) or Claude Max ($100/month for heavy usage).</p>

<h3>Getting Help</h3>
<ul>
<li><strong>Inside Claude Code:</strong> type <code>/help</code></li>
<li><strong>Documentation:</strong> docs.anthropic.com/en/docs/claude-code</li>
<li><strong>Issues and feedback:</strong> github.com/anthropics/claude-code/issues</li>
<li><strong>Or just ask Claude:</strong> "How do I do X in Claude Code?"</li>
</ul>

<h3>Quick Setup Checklist</h3>
<ol>
<li>Install Node.js (brew install node on Mac, nodejs.org on Windows)</li>
<li>Install Claude Code: <code>npm install -g @anthropic-ai/claude-code</code></li>
<li>Navigate to your project: <code>cd /path/to/project</code></li>
<li>Launch: <code>claude</code></li>
<li>Authenticate in the browser</li>
<li>Create a CLAUDE.md: type <code>/init</code></li>
<li>Start working!</li>
</ol>',
    ],
    10 => [
        'name' => 'Project Ideas: From Fun to Mission Critical',
        'summary' => '
<h3>Starting a Project with Claude Code</h3>
<p>Now that you know the basics, let\'s walk through how to actually start a project from scratch and see what Claude Code can do with real, practical examples. Each example below includes the initial setup steps plus sample prompts you can use.</p>

<hr>

<h3>How to Start Any Project</h3>
<ol>
<li><strong>Create a project folder</strong> and <code>cd</code> into it</li>
<li><strong>Run <code>claude</code></strong> to start a session</li>
<li><strong>Describe what you want to build</strong> in plain language</li>
<li><strong>Let Claude scaffold the project</strong> (files, folders, dependencies)</li>
<li><strong>Run <code>/init</code></strong> to generate a CLAUDE.md so future sessions have full context</li>
<li><strong>Iterate</strong>: ask Claude to add features, fix issues, write tests, deploy</li>
</ol>

<div style="background:#e7f3fe;border-left:4px solid #2196F3;padding:12px;margin:16px 0;border-radius:4px;">
<strong>Pro tip:</strong> Before you start, create a brief CLAUDE.md describing the goal, tech stack, and any constraints. Even two paragraphs gives Claude a massive head start.
</div>

<hr>

<h3>Project 1: Create a New Moodle Course (Work)</h3>
<p>Need to build a new course quickly? Claude Code can generate the entire course structure, including section content, descriptions, and activities.</p>

<h4>Setup</h4>
<pre style="background:#f4f4f4;padding:12px;border-radius:6px;">mkdir moodle-course-creator && cd moodle-course-creator
claude</pre>

<h4>Sample Prompts</h4>
<pre style="background:#f4f4f4;padding:16px;border-radius:6px;font-size:13px;line-height:1.6;">
"Create a PHP script for Moodle that generates a course called
\'Introduction to Data Science\' with 8 sections. Each section
should have a descriptive title, a summary paragraph, and
suggested activities. Use Moodle\'s create_course() and
course_update_section() APIs."

"Add a multiple choice quiz activity to Section 3 using
Moodle\'s question bank API. Generate 10 questions about
Python basics with 4 answer choices each."

"Create a SCORM package uploader that takes a zip file and
adds it as an activity to a specified section."
</pre>

<h4>What Claude Builds</h4>
<ul>
<li>A self-contained PHP script that creates the course with proper Moodle API calls</li>
<li>Section content with HTML formatting</li>
<li>Quiz questions with correct answers flagged</li>
<li>Handles both creating new courses and updating existing ones</li>
</ul>

<hr>

<h3>Project 2: Interactive Course Activities (Work)</h3>
<p>Go beyond static content. Ask Claude to create interactive H5P style activities, drag and drop exercises, or embedded JavaScript widgets that run inside Moodle pages.</p>

<h4>Setup</h4>
<pre style="background:#f4f4f4;padding:12px;border-radius:6px;">mkdir moodle-activities && cd moodle-activities
claude</pre>

<h4>Sample Prompts</h4>
<pre style="background:#f4f4f4;padding:16px;border-radius:6px;font-size:13px;line-height:1.6;">
"Create an interactive timeline activity for a World History
course. Students should be able to drag events to the correct
position on a timeline. Use vanilla JavaScript so it works
inside a Moodle page label."

"Build a flashcard widget that teachers can embed in any
course section. It should read terms and definitions from a
JSON array, show one side at a time, and let students flip
and mark cards as \'learned\' or \'review again\'."

"Create a peer discussion prompt generator that picks a random
debate topic from a list and pairs it with a Socratic question.
Add a button to generate a new prompt."
</pre>

<hr>

<h3>Project 3: Self Serve Transcript Add On (Work)</h3>
<p>Build a Moodle plugin that lets students download or view their own transcript: courses completed, grades, certificates earned, and enrollment history.</p>

<h4>Setup</h4>
<pre style="background:#f4f4f4;padding:12px;border-radius:6px;">mkdir moodle-transcript && cd moodle-transcript
claude</pre>

<h4>Sample CLAUDE.md</h4>
<pre style="background:#f4f4f4;padding:16px;border-radius:6px;font-size:13px;line-height:1.5;">
# Moodle Transcript Plugin

## Goal
Local plugin (local_transcript) that gives students a
"My Transcript" page showing all completed courses,
final grades, and certificate links.

## Requirements
- Works on Moodle 4.5+
- Uses Moodle completion API and gradebook API
- PDF export option (using TCPDF, bundled with Moodle)
- Respects Moodle capabilities (students see own data only)
- Clean, printable HTML view as well as PDF
</pre>

<h4>Sample Prompts</h4>
<pre style="background:#f4f4f4;padding:16px;border-radius:6px;font-size:13px;line-height:1.6;">
"Scaffold a Moodle local plugin called local_transcript.
Include version.php, db/access.php with a view capability,
lang/en strings, and a main page that lists all courses the
current user has completed with their final grade."

"Add a PDF export button that generates a formatted transcript
using Moodle\'s built in TCPDF library. Include the student\'s
name, the institution name from site settings, each course
with completion date and grade, and a generation timestamp."

"Add a verification hash to each transcript so someone
receiving it can verify authenticity by visiting a URL."
</pre>

<hr>

<h3>Project 4: Florida Outreach Marketing Tool (Work)</h3>
<p>Build a campaign tool to share Saylor\'s free degree programs (<strong>degrees.saylor.org</strong>) with Florida residents through social media ads, email campaigns, and community outreach. Claude Code can create the landing pages, audience targeting specs, ad copy, and analytics tracking.</p>

<h4>Setup</h4>
<pre style="background:#f4f4f4;padding:12px;border-radius:6px;">mkdir saylor-florida-campaign && cd saylor-florida-campaign
claude</pre>

<h4>Sample CLAUDE.md</h4>
<pre style="background:#f4f4f4;padding:16px;border-radius:6px;font-size:13px;line-height:1.5;">
# Saylor Florida Outreach Campaign

## Goal
Marketing campaign targeting Florida residents to promote
free, accredited degree programs at degrees.saylor.org.

## Target Audience
- Adults 22 to 55 in Florida
- Career changers, working professionals, parents
- Community college students looking to transfer
- Veterans and military families (FL has large bases)
- People searching for affordable/free college options

## Channels
- Facebook/Instagram ads (geo-targeted to FL)
- Google Search ads (keywords: free degree Florida, etc.)
- Reddit (r/florida, r/college, r/careerguidance)
- LinkedIn (career changers, workforce development)
- Email outreach to FL workforce development boards
- Community partnerships (libraries, workforce centers)

## Key Messages
- Fully accredited, tuition free degree programs
- Study at your own pace, 100% online
- Transfer credits from community college
- No application fees, no hidden costs
</pre>

<h4>Sample Prompts</h4>
<pre style="background:#f4f4f4;padding:16px;border-radius:6px;font-size:13px;line-height:1.6;">
"Create a Florida-specific landing page (HTML/CSS) for
degrees.saylor.org that highlights the value proposition
for Florida residents. Include a hero section, three
benefit cards, testimonial placeholders, a program
catalog section, and a clear CTA. Mobile responsive."

"Generate 10 Facebook ad variations targeting Florida
adults 22 to 45. Each ad should have a headline (under
40 characters), body text (under 125 characters), and a
call to action. Focus on themes: career advancement,
zero tuition, flexibility, and accreditation."

"Create a Google Ads keyword strategy for Florida. Include
50 keywords organized by intent (informational, comparison,
ready to enroll). Add negative keywords and suggested
bid strategy notes."

"Build a campaign analytics dashboard in HTML/JS that
tracks: landing page visits, ad click through rates,
enrollment starts by source, and cost per enrollment.
Use Chart.js for visualizations."

"Draft 5 email templates for outreach to Florida workforce
development boards and community organizations. Each should
explain Saylor\'s free degree programs and propose a
partnership or referral arrangement."

"Create social media content calendar for 30 days of posts
across Facebook, Instagram, LinkedIn, and Reddit. Each entry
should have the platform, post text, suggested image
description, hashtags, and best posting time for FL timezone."
</pre>

<hr>

<h3>Project 5: Personal Recipe Manager (Fun)</h3>
<p>A simple web app to organize your recipes, scale ingredients, and generate shopping lists.</p>

<h4>Setup and Prompt</h4>
<pre style="background:#f4f4f4;padding:16px;border-radius:6px;font-size:13px;line-height:1.6;">
mkdir my-recipes && cd my-recipes && claude

"Build a single page recipe manager using HTML, CSS, and
vanilla JavaScript. Features: add recipes with ingredients
and steps, search by name or ingredient, scale servings
up or down (adjusts all quantities), generate a combined
shopping list from selected recipes, and save everything
to localStorage so it persists. Make it look good on mobile."
</pre>

<hr>

<h3>Project 6: Family Movie Night Picker (Fun)</h3>
<p>End the "what should we watch" debate forever.</p>

<h4>Setup and Prompt</h4>
<pre style="background:#f4f4f4;padding:16px;border-radius:6px;font-size:13px;line-height:1.6;">
mkdir movie-picker && cd movie-picker && claude

"Build a fun \'Movie Night Picker\' web app. Each family
member enters their mood (adventurous, cozy, funny, scary,
mind-bending) and the app finds the intersection. It should
have a spinning wheel animation for the final pick, a
\'veto\' button (limited to one per person), and a history
of past movie nights. Use the free OMDB API for movie data.
Make it colorful, playful, and mobile friendly."
</pre>

<hr>

<h3>Project 7: Workout Generator (Fun + Useful)</h3>
<p>A personalized workout builder that adapts to your equipment, time, and fitness level.</p>

<h4>Setup and Prompt</h4>
<pre style="background:#f4f4f4;padding:16px;border-radius:6px;font-size:13px;line-height:1.6;">
mkdir workout-gen && cd workout-gen && claude

"Create a workout generator web app. Users select: available
equipment (bodyweight, dumbbells, barbell, resistance bands),
time available (15/30/45/60 minutes), target area (full body,
upper, lower, core, cardio), and fitness level (beginner,
intermediate, advanced). Generate a structured workout with
sets, reps, and rest times. Include a built in timer with
audio cues. Save workout history with personal records."
</pre>

<hr>

<h3>What Makes These Projects Work Well with Claude Code</h3>
<ul>
<li><strong>Clear goal:</strong> Each project starts with a specific, describable outcome</li>
<li><strong>Iterative building:</strong> Start simple, then add features one prompt at a time</li>
<li><strong>Real context via CLAUDE.md:</strong> The more you describe your constraints and goals upfront, the better the results</li>
<li><strong>Testable output:</strong> You can see the results immediately (open the HTML file, run the script, check the Moodle course)</li>
</ul>

<div style="background:#fff3e0;border-left:4px solid #ff9800;padding:12px;margin:16px 0;border-radius:4px;">
<strong>Remember:</strong> Claude Code is most powerful when you treat it as a collaborator, not a vending machine. Describe the "why" behind what you want, review what it produces, and iterate. The best projects come from a conversation, not a single prompt.
</div>',
    ],
];

// Section 0 content.
$sections[0] = [
    'name' => 'Course Overview',
    'summary' => '<h3>Getting Started with Claude Code</h3>
<p>Welcome! This course will teach you how to install, configure, and use Claude Code on both Mac and PC. You\'ll learn how to organize your projects with CLAUDE.md files, use project folders and memory to maintain context across sessions, and develop effective workflows for common development tasks.</p>
<p><strong>Course outline:</strong></p>
<ol>
<li>What is Claude Code?</li>
<li>Installation: Mac</li>
<li>Installation: Windows (PC)</li>
<li>Basic Usage</li>
<li>CLAUDE.md: Your Project\'s Instruction Manual</li>
<li>Project Folders and Memory</li>
<li>Tips for Effective Use</li>
<li>Common Workflows</li>
<li>Pricing, Resources, and Getting Help</li>
<li>Project Ideas: From Fun to Mission Critical</li>
</ol>',
];

// Update each section using Moodle's proper API.
foreach ($sections as $secnum => $secdata) {
    $sectioninfo = $DB->get_record('course_sections', ['course' => $courseid, 'section' => $secnum]);
    if (!$sectioninfo) {
        continue;
    }
    course_update_section($courseid, $sectioninfo, [
        'name' => $secdata['name'],
        'summary' => $secdata['summary'],
        'summaryformat' => FORMAT_HTML,
    ]);
}

rebuild_course_cache($courseid, true);

echo $OUTPUT->header();
echo '<div class="alert alert-success">';
echo '<h4>Course ' . ($coursecreated ? 'created' : 'updated') . ' successfully!</h4>';
echo '<p>Course: Getting Started with Claude Code (CLAUDECODE101)</p>';
echo '<p>Sections: 10 topics with full content</p>';
echo '<p><a href="' . (new moodle_url('/course/view.php', ['id' => $courseid]))->out() .
     '" class="btn btn-primary">View the course</a></p>';
echo '</div>';
echo $OUTPUT->footer();

