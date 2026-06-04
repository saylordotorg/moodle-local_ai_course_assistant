#!/usr/bin/env bash
# Run SOLA accessibility tests against local Moodle pages (WCAG2AA).
#
# Prereqs:
#   1. Local Moodle at http://localhost:8080 with admin/Admin1234! and course id=2.
#   2. Start the dev server with MULTIPLE workers, or puppeteer deadlocks the
#      single-threaded php -S (it opens several concurrent connections per page):
#        PHP_CLI_SERVER_WORKERS=6 /opt/homebrew/opt/php@8.3/bin/php -S 0.0.0.0:8080 -t ~/Sites/moodle
#   3. After any version.php bump, run the upgrade so Moodle stops 302-redirecting
#      every page to the upgrade screen:
#        /opt/homebrew/opt/php@8.3/bin/php ~/Sites/moodle/admin/cli/upgrade.php --non-interactive
#
# The runner is the puppeteer + axe-core engine (axe-run.js): it logs in once via
# the real login form and reuses the session. The former pa11y-ci fallback was
# removed in v5.8.1 — its action-DSL login never drove Moodle's login form
# locally (verified again against pa11y-ci 4.1.1), so it only ever reported
# false timeouts and exercised nothing in CI.
set -euo pipefail

cd "$(dirname "$0")"

if [ ! -d node_modules ]; then
  echo "Installing dependencies..."
  npm install --no-audit --no-fund
fi

exec node axe-run.js "$@"
