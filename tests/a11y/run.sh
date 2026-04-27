#!/usr/bin/env bash
# Run Pa11y CI accessibility tests against local SOLA pages.
# Requires: local Moodle at http://localhost:8080 with admin/Admin1234! and course id=2.
set -euo pipefail

cd "$(dirname "$0")"

if [ ! -d node_modules ]; then
  echo "Installing pa11y-ci..."
  npm install --no-audit --no-fund
fi

exec npx pa11y-ci --config .pa11yci.json "$@"
