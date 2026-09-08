#!/usr/bin/env python3
"""HTTP smoke for the pages touched by v7.3.5. Status codes are not enough --
themed error pages return 200 -- so this greps rendered content for PHP error
surfaces, unresolved lang keys and unresolved branding tokens."""
import re, sys, urllib.parse
import http.cookiejar, urllib.request, urllib.error

BASE = 'http://localhost:8080'
USER, PW = 'admin', 'Admin1234!'
jar = http.cookiejar.CookieJar()
op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
op.addheaders = [('User-Agent', 'sola-smoke')]

def get(url):
    try:
        with op.open(url, timeout=60) as r:
            return r.getcode(), r.read().decode('utf-8', 'replace')
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode('utf-8', 'replace')

# login
code, html = get(BASE + '/login/index.php')
m = re.search(r'name="logintoken" value="([^"]+)"', html)
data = {'username': USER, 'password': PW}
if m:
    data['logintoken'] = m.group(1)
req = urllib.request.Request(BASE + '/login/index.php',
                             urllib.parse.urlencode(data).encode())
with op.open(req, timeout=60) as r:
    body = r.read().decode('utf-8', 'replace')
if 'Invalid login' in body or 'loginerrormessage' in body:
    print('LOGIN FAILED'); sys.exit(2)
print('login ok')

# The php-diagnostic / moodle-debugging detectors only see anything when the
# target site renders notices. Assert it rather than trusting it: a smoke that
# silently cannot detect its own failure class is worse than no smoke.
code, dbg = get(BASE + '/admin/settings.php?section=debugging')
if 'DEVELOPER' not in dbg and 'debugdisplay' not in dbg:
    print('WARNING: could not confirm developer debugging on the target site;')
    print('         the php-diagnostic detectors may be inert.')

P = '/local/ai_course_assistant/'
PAGES = [
    ('objectives_admin (picker)', P + 'objectives_admin.php'),
    ('objectives_admin (course 2)', P + 'objectives_admin.php?courseid=2'),
    ('demo_admin',                 P + 'demo_admin.php'),
    ('transcript_report',          P + 'transcript_report.php?courseid=2'),
    ('transcript_report filtered', P + 'transcript_report.php?courseid=2&mode=notmet&objectiveid=0'),
    ('outcomes_report',            P + 'outcomes_report.php?courseid=2'),
    ('starter_settings',           P + 'starter_settings.php'),
    ('soapbox_present',            P + 'soapbox_present.php?id=1'),
    ('analytics dashboard',        P + 'analytics.php'),
    ('analytics (course 2)',       P + 'analytics.php?courseid=2'),
    ('token_analytics',            P + 'token_analytics.php'),
    ('privacy notice',             P + 'privacy.php'),
    ('prompt_debug_view',          P + 'prompt_debug_view.php'),
    ('prompt_metrics',             P + 'prompt_metrics.php'),
    ('integrity_admin',            P + 'integrity_admin.php'),
    ('settings page',              '/admin/settings.php?section=local_ai_course_assistant_general'),
    ('rubric_admin',               P + 'rubric_admin.php'),
    ('survey_admin',               P + 'survey_admin.php'),
    ('usertesting_admin',          P + 'usertesting_admin.php'),
    ('courses_admin',              P + 'courses_admin.php'),
    ('sandbox',                    P + 'sandbox.php?courseid=2'),
    ('soapbox',                    P + 'soapbox.php?courseid=2'),
    ('prompt_playground',          P + 'prompt_playground.php'),
    # Added in v7.4.0. course_settings.php is first for a reason: it rendered
    # its own source code from v7.3.0 to v7.3.5 and an outside user found it
    # before this smoke did, because the page was not in this list.
    ('course_settings',            P + 'course_settings.php?courseid=2'),
    ('model_registry',             P + 'model_registry.php'),
    ('rag_admin',                  P + 'rag_admin.php'),
    ('audit_log',                  P + 'audit_log.php'),
    ('emergency_admin',            P + 'emergency_admin.php'),
    ('admin_user_data',            P + 'admin_user_data.php'),
    ('backend_selftest',           P + 'backend_selftest.php'),
    ('deployment_profile',         P + 'deployment_profile.php'),
    ('vendor_dpa',                 P + 'vendor_dpa.php'),
    # NOT smokeable by GET (verified, not assumed): rate_card_refresh.php is an
    # action endpoint -- require_sesskey() at line 35, then it performs the
    # refresh -- so a bare GET correctly refuses. Same for the POST-only and
    # binary-upload endpoints (sse.php, transcribe.php, tts.php,
    # soapbox_transcribe.php, spend_export.php, *_webhook.php).
    ('starter_settings (course)',  P + 'starter_settings.php?courseid=2'),
    ('flashcards',                 P + 'flashcards.php?courseid=2'),
    ('instructor_dashboard',       P + 'instructor_dashboard.php?courseid=2'),
]
BAD = [
    ('php-fatal',        re.compile(r'Fatal error|Parse error|Uncaught \w*(Error|Exception)')),
    ('moodle-exception', re.compile(r'Exception - |Coding error detected|Debug info:|Error reading from database')),
    ('missing-string',   re.compile(r'\[\[[a-z0-9_:]+\]\]')),
    ('unresolved-brand', re.compile(r'\[\[(tutorshort|tutorname|uniname|unishort)\]\]')),
    ('mustache-leak',    re.compile(r'\{\{[#/^]?[a-z_]')),
    ('raw-placeholder',  re.compile(r'\{\$a')),
    # v7.4.0: a PHP delimiter in the response body means a block fell out of
    # PHP mode and the page is serving its own source. This is the pattern
    # that would have caught the course_settings.php defect (issue #218).
    ('php-source-leak',  re.compile(r'<\?php|<\?=|\?>')),
    # And the consequence, not just the cause. Local Moodle runs at
    # DEVELOPER debug, so notices render; assert that below.
    ('php-diagnostic',   re.compile(r'(?:Warning|Notice|Deprecated)\s*(?:</b>)?\s*:'
                                    r'|Undefined variable|Undefined array key'
                                    r'|Attempt to read property')),
    ('moodle-debugging', re.compile(r'data-rel="debugging"')),
]
fails = 0
for name, path in PAGES:
    try:
        code, html = get(BASE + path)
    except Exception as e:
        print(f'FAIL {name}: request error {e}'); fails += 1; continue
    scan = html
    # Moodle core inlines its own JS string cache, which legitimately contains
    # {$a} and mustache-looking text; and two of our own attributes are
    # deliberate client-side templates. Strip before scanning for OUR defects.
    scan = re.sub(r'M\.str\s*=.*?;\s*\n', '', scan, flags=re.S)
    scan = re.sub(r'"(?:renameto|referencesexist|previewtotal)"\s*:\s*"[^"]*"', '', scan)
    scan = re.sub(r'data-redash-name-tpl="[^"]*"', '', scan)
    scan = re.sub(r'placeholder="e\.g\. https://forms\.google\.com[^"]*"', '', scan)
    # usertesting_admin documents its own {{placeholders}} to admins in <code> tags.
    scan = re.sub(r'<code>\{\{[a-z_]+\}\}</code>', '', scan)
    # Prompt/report placeholder names are documented to admins as literal text.
    scan = re.sub(r'\{\{(coursename|userrole|institution|userid|courseid|messages|session_minutes|firstname)\}\}', '', scan)
    scan = re.sub(r'<script[^>]*>.*?</script>', '', scan, flags=re.S)
    # An inline SVG may carry an XML declaration, the only legitimate '?>'
    # in a response body.
    scan = re.sub(r'<\?xml[^>]*\?>', '', scan)
    probs = [tag for tag, rx in BAD if rx.search(scan)]
    # a page that never rendered the plugin at all is also a failure
    if code != 200:
        probs.append(f'http-{code}')
    if probs:
        print(f'FAIL {name} [{path}] -> {probs}')
        if code != 200:
            t = re.sub(r'<[^>]+>', ' ', html)
            t = re.sub(r'\s+', ' ', t)
            print('    body:', t[:600])
        for tag, rx in BAD:
            m = rx.search(scan)
            if m:
                s = max(0, m.start() - 90)
                print(f'    {tag}: ...{scan[s:m.end()+90].strip()[:220]}...')
        fails += 1
    else:
        print(f'ok   {name} ({len(html)//1024}KB)')
print(f'\n{len(PAGES) - fails}/{len(PAGES)} pages clean')
sys.exit(1 if fails else 0)
