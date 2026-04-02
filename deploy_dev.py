#!/usr/bin/env python3
"""Deploy SOLA plugin to dev.sylr.org via Moodle's Install Plugin UI."""

import http.cookiejar
import json
import pathlib
import re
import sys
import urllib.error
import urllib.parse
import urllib.request

BASE_URL = "https://dev.sylr.org"
USERNAME = "tom.caswell@saylor.org"
PASSWORD = "Hi11crest!!"

ZIP_PATH = pathlib.Path.home() / "Library" / "CloudStorage" / "Dropbox" / "!Saylor" / "ai-projects" / "ai_course_assistant.zip"


def build_multipart(fields, files):
    """Build a multipart/form-data body."""
    import uuid
    boundary = uuid.uuid4().hex
    lines = []
    for key, value in fields.items():
        lines.append(f'--{boundary}'.encode())
        lines.append(f'Content-Disposition: form-data; name="{key}"'.encode())
        lines.append(b'')
        lines.append(str(value).encode())
    for key, (filename, data, content_type) in files.items():
        lines.append(f'--{boundary}'.encode())
        lines.append(f'Content-Disposition: form-data; name="{key}"; filename="{filename}"'.encode())
        lines.append(f'Content-Type: {content_type}'.encode())
        lines.append(b'')
        lines.append(data)
    lines.append(f'--{boundary}--'.encode())
    lines.append(b'')
    body = b'\r\n'.join(lines)
    content_type = f'multipart/form-data; boundary={boundary}'
    return body, content_type


def main():
    cj = http.cookiejar.CookieJar()
    opener = urllib.request.build_opener(
        urllib.request.HTTPCookieProcessor(cj),
        urllib.request.HTTPRedirectHandler()
    )
    opener.addheaders = [('User-Agent', 'Mozilla/5.0 SOLA-Deploy/1.0')]

    def get(url):
        print(f"  GET {url}")
        resp = opener.open(url)
        html = resp.read().decode('utf-8', errors='replace')
        return html, resp.geturl()

    def post(url, data, content_type=None):
        if isinstance(data, dict):
            body = urllib.parse.urlencode(data).encode()
            ct = 'application/x-www-form-urlencoded'
        else:
            body = data
            ct = content_type
        req = urllib.request.Request(url, data=body, headers={'Content-Type': ct})
        print(f"  POST {url}")
        resp = opener.open(req)
        html = resp.read().decode('utf-8', errors='replace')
        return html, resp.geturl()

    def find_sesskey(html):
        m = re.search(r'"sesskey"\s*:\s*"([^"]+)"', html)
        if not m:
            m = re.search(r'name="sesskey"\s+value="([^"]+)"', html)
        if not m:
            m = re.search(r'sesskey=([a-zA-Z0-9]+)', html)
        return m.group(1) if m else None

    # Step 1: Login
    print("Step 1: Logging in...")
    html, _ = get(f"{BASE_URL}/login/index.php")
    logintoken = re.search(r'name="logintoken"\s+value="([^"]+)"', html)
    login_data = {
        'username': USERNAME,
        'password': PASSWORD,
        'anchor': '',
    }
    if logintoken:
        login_data['logintoken'] = logintoken.group(1)
    html, final_url = post(f"{BASE_URL}/login/index.php", login_data)
    sesskey = find_sesskey(html)
    if not sesskey:
        print("ERROR: Login failed.")
        sys.exit(1)
    print(f"  Logged in. sesskey={sesskey}")

    # Step 2: GET install plugin page
    print("Step 2: Loading install plugin page...")
    html, _ = get(f"{BASE_URL}/admin/tool/installaddon/index.php")

    # Find itemid
    itemid_match = re.search(r'"itemid"\s*:\s*(\d+)', html)
    if not itemid_match:
        print("ERROR: Could not find itemid")
        sys.exit(1)
    itemid = itemid_match.group(1)
    print(f"  itemid: {itemid}")

    # Find client_id
    clientid_match = re.search(r'"client_id"\s*:\s*"([^"]+)"', html)
    client_id = clientid_match.group(1) if clientid_match else "unknown"

    # Find upload repo_id
    upload_repo_id = None
    all_repos = re.findall(r'"(\d+)"\s*:\s*\{[^}]*?"type"\s*:\s*"(\w+)"', html)
    for rid, rtype in all_repos:
        if rtype == 'upload':
            upload_repo_id = rid
            break
    if not upload_repo_id:
        upload_repo_id = '5'
    print(f"  upload repo_id: {upload_repo_id}")

    # Extract all form fields from the install form
    form_match = re.search(
        r'<form[^>]*id="[^"]*installfromzip[^"]*"[^>]*>(.*?)</form>',
        html, re.DOTALL
    )
    if not form_match:
        # Try broader form match
        form_match = re.search(
            r'_qf__tool_installaddon_installfromzip_form.*?</form>',
            html, re.DOTALL
        )

    # Step 3: Upload zip
    print("Step 3: Uploading zip file...")
    zip_data = ZIP_PATH.read_bytes()
    print(f"  Zip size: {len(zip_data)} bytes")

    upload_fields = {
        'repo_id': upload_repo_id,
        'p': '',
        'page': '',
        'env': 'filemanager',
        'sesskey': sesskey,
        'client_id': client_id,
        'maxbytes': '-1',
        'areamaxbytes': '-1',
        'ctx_id': '1',
        'itemid': itemid,
        'savepath': '/',
        'title': '',
        'author': '',
        'license': '',
        'overwrite': '1',
    }
    upload_files = {
        'repo_upload_file': ('ai_course_assistant.zip', zip_data, 'application/zip'),
    }

    body, ct = build_multipart(upload_fields, upload_files)
    upload_url = f"{BASE_URL}/repository/repository_ajax.php?action=upload"
    resp_html, _ = post(upload_url, body, ct)
    upload_result = json.loads(resp_html)

    if upload_result.get('error'):
        print(f"  Error: {upload_result['error']}")
        for try_id in range(1, 10):
            upload_fields['repo_id'] = str(try_id)
            body, ct = build_multipart(upload_fields, upload_files)
            try:
                resp_html, _ = post(upload_url, body, ct)
                upload_result = json.loads(resp_html)
                if not upload_result.get('error'):
                    print(f"  Success with repo_id={try_id}")
                    break
            except Exception:
                continue

    if upload_result.get('error'):
        print(f"ERROR: Upload failed: {upload_result}")
        sys.exit(1)

    print(f"  Upload OK: {upload_result.get('file')}")

    # Step 4: Submit install form with ALL required Moodle form fields
    print("Step 4: Submitting install form...")

    # Moodle QuickForm requires _qf__ field and sesskey
    # The 'zipfile' field in the form is the draft area itemid
    install_data = {
        'sesskey': sesskey,
        '_qf__tool_installaddon_installfromzip_form': '1',
        'mform_showmore_id_general': '0',
        'mform_isexpanded_id_general': '1',
        'zipfile': itemid,
        'rootdir': '',
        'submitbutton': 'Install plugin from the ZIP file',
    }
    html, final_url = post(f"{BASE_URL}/admin/tool/installaddon/index.php", install_data)
    print(f"  Response URL: {final_url}")

    title_match = re.search(r'<title>(.*?)</title>', html, re.DOTALL)
    page_title = re.sub(r'<[^>]+>', '', title_match.group(1)).strip() if title_match else 'unknown'
    print(f"  Page title: {page_title}")

    # Step 5: Parse validation/confirmation page
    print("Step 5: Confirming plugin validation...")

    # Look for installzipstorage/installzipcomponent
    storage_match = re.search(r'name="installzipstorage"\s+value="([^"]+)"', html)
    if not storage_match:
        storage_match = re.search(r'name="installzipstorage"[^>]*value="([^"]+)"', html)
    if not storage_match:
        storage_match = re.search(r'value="([^"]+)"[^>]*name="installzipstorage"', html)

    component_match = re.search(r'name="installzipcomponent"\s+value="([^"]+)"', html)
    if not component_match:
        component_match = re.search(r'name="installzipcomponent"[^>]*value="([^"]+)"', html)
    if not component_match:
        component_match = re.search(r'value="([^"]+)"[^>]*name="installzipcomponent"', html)

    if not storage_match or not component_match:
        # Check if this is already the upgrade page
        if 'confirmupgrade' in html:
            print("  Skipped validation, already on upgrade page")
            storage = None
            component = None
        else:
            # Dump debug info
            all_inputs = re.findall(r'<input[^>]+>', html)
            relevant = [inp for inp in all_inputs if 'install' in inp.lower() or 'zip' in inp.lower() or 'submit' in inp.lower()]
            print(f"  Relevant inputs: {relevant[:10]}")

            # Check for validation errors
            errors = re.findall(r'class="[^"]*(?:alert|error|warning)[^"]*"[^>]*>(.*?)</(?:div|span)>', html, re.DOTALL)
            for e in errors[:3]:
                clean = re.sub(r'<[^>]+>', '', e).strip()
                if clean:
                    print(f"  Error/Alert: {clean[:200]}")

            # Maybe the form just re-rendered - check for specific error about the zip
            if 'validation' in html.lower() or 'check' in html.lower():
                # Find ALL forms with their actions
                forms = re.findall(r'<form[^>]*action="([^"]*)"[^>]*>(.*?)</form>', html, re.DOTALL)
                for action, fbody in forms:
                    if 'install' in fbody.lower() or 'confirm' in fbody.lower():
                        finputs = re.findall(r'<input[^>]+>', fbody)
                        print(f"  Form action={action}, inputs={finputs[:10]}")

            # Last resort: dump a chunk of page
            # Find the main content area
            main = re.search(r'id="region-main"[^>]*>(.*?)</section>', html, re.DOTALL)
            if main:
                clean_main = re.sub(r'<script[^>]*>.*?</script>', '', main.group(1), flags=re.DOTALL)
                clean_main = re.sub(r'<[^>]+>', ' ', clean_main)
                clean_main = re.sub(r'\s+', ' ', clean_main).strip()
                print(f"  Main content: {clean_main[:1000]}")

            sys.exit(1)
    else:
        storage = storage_match.group(1)
        component = component_match.group(1)
        print(f"  installzipstorage: {storage}")
        print(f"  installzipcomponent: {component}")

        confirm_data = {
            'sesskey': sesskey,
            'installzipcomponent': component,
            'installzipstorage': storage,
            'installzipconfirm': '1',
        }
        html, final_url = post(f"{BASE_URL}/admin/tool/installaddon/index.php", confirm_data)
        print(f"  Confirmation -> {final_url}")

    # Step 6: Handle upgrade pages
    print("Step 6: Handling upgrade...")
    for step in range(5):
        tm = re.search(r'<title>(.*?)</title>', html, re.DOTALL)
        pt = re.sub(r'<[^>]+>', '', tm.group(1)).strip() if tm else ''
        print(f"  [{step}] {pt[:60]} | {final_url}")

        if 'confirmupgrade' in html:
            html, final_url = post(f"{BASE_URL}/admin/index.php", {
                'sesskey': sesskey,
                'confirmupgrade': '1',
                'confirmrelease': '1',
                'confirmplugincheck': '1',
            })
            continue

        if 'savechanges' in html.lower() or 'upgradesettings' in final_url:
            html, final_url = post(f"{BASE_URL}/admin/upgradesettings.php", {
                'sesskey': sesskey,
                'savechanges': 'Save changes',
            })
            continue

        break

    # Step 7: Ensure no pending upgrades remain (safety net).
    print("Step 7: Checking for pending upgrades...")
    try:
        new_sesskey = find_sesskey(html) or sesskey
        html, final_url = get(f"{BASE_URL}/admin/index.php")
        for extra_step in range(5):
            if 'confirmupgrade' in html or 'confirmplugincheck' in html:
                print(f"  Pending upgrade found, confirming...")
                html, final_url = post(f"{BASE_URL}/admin/index.php", {
                    'sesskey': find_sesskey(html) or new_sesskey,
                    'confirmupgrade': '1',
                    'confirmrelease': '1',
                    'confirmplugincheck': '1',
                })
                continue
            if 'savechanges' in html.lower() or 'upgradesettings' in final_url:
                print(f"  Saving new settings...")
                html, final_url = post(f"{BASE_URL}/admin/upgradesettings.php", {
                    'sesskey': find_sesskey(html) or new_sesskey,
                    'savechanges': 'Save changes',
                })
                continue
            break
        print("  No pending upgrades.")
    except Exception as e:
        print(f"  Upgrade check warning: {e}")

    # Step 8: Purge caches
    print("Step 8: Purging caches...")
    try:
        sk = find_sesskey(html) or sesskey
        html, _ = get(f"{BASE_URL}/admin/purgecaches.php?confirm=1&sesskey={sk}")
        print("  Caches purged!")
    except Exception as e:
        print(f"  Cache purge warning: {e}")

    # Step 9: Verify deployed version.
    print("Step 9: Verifying deployment...")
    try:
        html, _ = get(f"{BASE_URL}/admin/plugins.php")
        # Look for the plugin row and its version — Moodle renders version as 10-digit YYYYMMDDNN.
        ver_match = re.search(r'local_ai_course_assistant.*?(\d{10})', html, re.DOTALL)
        if ver_match:
            print(f"  Live version: {ver_match.group(1)}")
        else:
            print("  Could not parse version (check Site Admin > Plugins).")
    except Exception as e:
        print(f"  Version check warning: {e}")

    print("\n=== Deployment complete! ===")


if __name__ == '__main__':
    main()
