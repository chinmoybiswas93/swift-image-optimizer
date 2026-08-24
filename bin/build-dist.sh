#!/usr/bin/env bash
# Build a distributable (org-ready) zip of the Swift Image Optimizer plugin.
# Usage: ./bin/build-dist.sh

set -euo pipefail

PLUGIN_SLUG="swift-image-optimizer"
PLUGIN_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

VERSION="$(grep -E '^[[:space:]]*\*[[:space:]]*Version:' "$PLUGIN_ROOT/$PLUGIN_SLUG.php" | head -n1 | sed -E 's/.*Version:[[:space:]]*//; s/[[:space:]]*$//')"
if [[ -z "$VERSION" ]]; then
  echo "Could not detect plugin version from $PLUGIN_SLUG.php" >&2
  exit 1
fi

DIST_BASE="$PLUGIN_ROOT/dist"
DIST_DIR="$DIST_BASE/$PLUGIN_SLUG"
ZIP_FILE="$DIST_BASE/${PLUGIN_SLUG}-${VERSION}.zip"

echo "==> Building $PLUGIN_SLUG v$VERSION"

# 1. Build production JS/CSS from resources/ into ./build
echo "==> Running npm build"
( cd "$PLUGIN_ROOT" && npm install --include=dev --no-audit --no-fund && npm run build )

# 2. Generate the production autoloader.
#    The plugin has no Composer dependencies - this only regenerates
#    vendor/autoload.php, which ships because WordPress.org installs run no
#    build step on the destination server.
echo "==> Generating autoloader"
( cd "$PLUGIN_ROOT" && composer install --no-dev --optimize-autoloader --no-interaction )

# 3. Stage runtime subset
echo "==> Staging $DIST_DIR"
rm -rf "$DIST_BASE"
mkdir -p "$DIST_DIR"

# composer.json ships alongside vendor/. Plugin Check flags a vendor/
# directory with no composer.json next to it, and the pair is what tells a
# reviewer the committed autoloader is generated rather than hand-written.
# composer.lock stays out - there are no dependencies to lock.
rsync -a \
  --exclude='.git/' --exclude='.gitignore' \
  --exclude='.claude/' --exclude='.agents/' \
  --exclude='.DS_Store' \
  --exclude='node_modules/' \
  --exclude='package.json' --exclude='package-lock.json' \
  --exclude='webpack.config.js' \
  --exclude='composer.lock' \
  --exclude='tests/' --exclude='test-results/' \
  --exclude='test-screenshots/' --exclude='playwright-report/' \
  --exclude='playwright.config.js' --exclude='.playwright-cli/' \
  --exclude='*.yml' --exclude='*.yaml' \
  --exclude='agent.md' \
  --exclude='context/' \
  --exclude='graphify-out/' --exclude='.graphifyignore' \
  --exclude='dist/' --exclude='bin/' \
  --exclude='resources/' \
  --exclude='phpcs.xml.dist' \
  "$PLUGIN_ROOT/" "$DIST_DIR/"

# The exclude list above has gone stale before, and a stray root file is only
# noticed at WordPress.org review. Fail the build instead: anything new at the
# top level has to be named here deliberately, as shipped or excluded.
echo "==> Checking staged top level"
EXPECTED="api app boot build composer.json config database framework readme.txt swift-image-optimizer.php uninstall.php vendor"
ACTUAL="$(cd "$DIST_DIR" && ls -A | sort | tr '\n' ' ' | sed 's/ $//')"
if [[ "$ACTUAL" != "$(echo "$EXPECTED" | tr ' ' '\n' | sort | tr '\n' ' ' | sed 's/ $//')" ]]; then
  echo "Unexpected top-level contents in the staged plugin." >&2
  echo "  expected: $EXPECTED" >&2
  echo "  actual:   $ACTUAL" >&2
  echo "Add the new entry to the rsync excludes, or to EXPECTED if it should ship." >&2
  exit 1
fi

# 4. Zip it (single top-level directory matching the slug)
echo "==> Creating $ZIP_FILE"
( cd "$DIST_BASE" && zip -rq "$(basename "$ZIP_FILE")" "$PLUGIN_SLUG" )

# 5. Remove the staged directory; keep only the zip
echo "==> Cleaning up staged directory"
rm -rf "$DIST_DIR"

echo
echo "Done."
echo "Artifact: $ZIP_FILE"
echo
echo "Do NOT install this zip over this directory. This plugin folder is the git"
echo "working tree; WordPress deletes the existing folder before unpacking, which"
echo "destroys .git, context/, bin/, tests/, resources/ and the zip in dist/ with it."
echo "Test the zip on a throwaway site, or from a checkout outside wp-content."
