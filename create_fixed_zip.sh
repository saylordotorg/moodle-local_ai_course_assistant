#!/bin/bash
# Create .zip for Moodle upload

echo "Creating ai_course_assistant.zip..."

SCRIPT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$SCRIPT_DIR"

# Refuse to build if key material is sitting in the tree.
#
# .gitignore keeps these out of git, but git is not what gets published -- this
# zip is, and it had no secret exclusions at all. The concrete route in: the
# policy-bundle authoring CLI writes its Ed25519 PRIVATE key to getcwd() when
# --out is omitted (admin/cli/policy_bundle_tool.php), so one keygen run from
# the repo root would have put the bundle signing key on the Moodle plugin
# directory. Aborting is deliberate -- silently excluding the file would hide
# the fact that a private key is loose on disk.
SECRETS=$(find ai_course_assistant \
  \( -name '.env' -o -name '.env.*' -o -name '*.pem' -o -name '*.p12' \
     -o -name '*.pfx' -o -name '*.key' -o -name 'id_rsa*' -o -name 'id_ed25519*' \
     -o -name 'secrets*.json' -o -name 'credentials*.json' \
     -o -name 'service-account*.json' \) \
  -not -path '*/.git/*' -print 2>/dev/null)
if [ -n "$SECRETS" ]; then
  echo "ABORTED: credential-shaped files present in the tree:" >&2
  echo "$SECRETS" | sed 's/^/  /' >&2
  echo "" >&2
  echo "Move them outside the repo (e.g. ~/.sola/) and re-run." >&2
  exit 1
fi

rm -f ai_course_assistant.zip
zip -r ai_course_assistant.zip ai_course_assistant/ \
  -x "*.git*" \
  -x "*/.env" \
  -x "*/.env.*" \
  -x "*/*.pem" \
  -x "*/*.p12" \
  -x "*/*.pfx" \
  -x "*/*.key" \
  -x "*/id_rsa*" \
  -x "*/id_ed25519*" \
  -x "*/secrets*.json" \
  -x "*/credentials*.json" \
  -x "*/service-account*.json" \
  -x "*/.claude/*" \
  -x "*/CLAUDE.md" \
  -x "*/deploy_dev.py" \
  -x "*/.DS_Store" \
  -x "*/Thumbs.db" \
  -x "*/._*" \
  -x "*/__MACOSX/*" \
  -x "__MACOSX/*" \
  -x "*/create_fixed_zip.sh" \
  -x "*/*.zip" \
  -x "*.zip" \
  -x "*/TROUBLESHOOTING.md" \
  -x "*/ENHANCEMENT_ESTIMATE.md" \
  -x "*/SAYLOR_ACADEMY_PROPOSAL.md" \
  -x "*/amd/build/*.map" \
  -x "*/cdn/node_modules/*" \
  -x "*/cdn/dist/*" \
  -x "*/cdn/package-lock.json" \
  -x "*/cdn/package.json" \
  -x "*/cdn/rollup.config.mjs" \
  -x "*/cdn/bundle-allowlist.txt" \
  -x "*/cdn/entry-bundle.js" \
  -x "*/cdn/shims/*" \
  -x "*/cdn/test/*" \
  -x "*/services/*" \
  -x "*/tests/a11y/node_modules/*" \
  -x "*/tests/a11y/package-lock.json" \
  -x "*/tests/golden/*" \
  -x "*/.wiki/*" \
  -x "*/.drafts/*" \
  -x "*/__pycache__/*" \
  -x "*.pyc" \
  -x "*/scripts/*"


# Refuse to publish a file that git is not tracking.
#
# The exclude list above is hand-maintained and drifts from .gitignore: it
# already excluded cdn/package-lock.json while shipping the identical
# tests/a11y/package-lock.json, which .gitignore deliberately excludes. An
# earlier build swept in a stray v7_workorder.zip the same way. git is not what
# gets published -- this zip is -- so the zip is checked against the index
# rather than trusted. Aborting rather than silently excluding, for the same
# reason as the secrets check: a file in the tree that git does not track is
# something the operator should see, not something the build should quietly
# drop.
STRAYS=$(comm -23 \
  <(unzip -Z1 ai_course_assistant.zip | grep -v '/$' | sed 's|^ai_course_assistant/||' | sort) \
  <(git -C ai_course_assistant ls-files | sort))
if [ -n "$STRAYS" ]; then
  echo "ABORTED: the zip contains files git does not track:" >&2
  echo "$STRAYS" | sed 's/^/  /' >&2
  echo "" >&2
  echo "Either commit them or add an -x exclusion in create_fixed_zip.sh." >&2
  rm -f ai_course_assistant.zip
  exit 1
fi

echo "✅ Created: ${SCRIPT_DIR}/ai_course_assistant.zip"
echo ""
echo "Upload this to Moodle via:"
echo "Site admin → Plugins → Install plugins"
