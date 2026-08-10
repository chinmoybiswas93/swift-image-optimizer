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

# 1. Build production JS/CSS from resources/ into ./assets
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

rsync -a \
  --exclude='.git/' --exclude='.gitignore' \
  --exclude='.claude/' --exclude='.agents/' \
  --exclude='.DS_Store' \
  --exclude='node_modules/' \
  --exclude='package.json' --exclude='package-lock.json' \
  --exclude='vite.config.js' \
  --exclude='composer.json' --exclude='composer.lock' \
  --exclude='tests/' --exclude='test-results/' \
  --exclude='test-screenshots/' --exclude='playwright-report/' \
  --exclude='playwright.config.js' \
  --exclude='context/' \
  --exclude='graphify-out/' --exclude='.graphifyignore' \
  --exclude='dist/' --exclude='bin/' \
  --exclude='resources/' \
  --exclude='phpcs.xml.dist' \
  "$PLUGIN_ROOT/" "$DIST_DIR/"

# 4. Zip it (single top-level directory matching the slug)
echo "==> Creating $ZIP_FILE"
( cd "$DIST_BASE" && zip -rq "$(basename "$ZIP_FILE")" "$PLUGIN_SLUG" )

# 5. Remove the staged directory; keep only the zip
echo "==> Cleaning up staged directory"
rm -rf "$DIST_DIR"

echo
echo "Done."
echo "Artifact: $ZIP_FILE"
