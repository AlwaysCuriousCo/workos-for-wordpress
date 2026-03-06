#!/usr/bin/env bash
#
# Build a distributable plugin zip with dependencies included.
#
# Usage:
#   ./bin/build-zip.sh
#
# Output:
#   dist/workos-for-wordpress.zip

set -euo pipefail

PLUGIN_SLUG="workos-for-wordpress"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PLUGIN_DIR="$(dirname "$SCRIPT_DIR")"
VERSION=$(grep -oP "Version:\s*\K[^\s]+" "$PLUGIN_DIR/$PLUGIN_SLUG.php")
BUILD_DIR=$(mktemp -d)
DEST="$BUILD_DIR/$PLUGIN_SLUG"
DIST_DIR="$PLUGIN_DIR/dist"

echo "Building $PLUGIN_SLUG v$VERSION..."

# Copy plugin files (exclude dev/build artifacts).
mkdir -p "$DEST"
rsync -a \
  --exclude='.git' \
  --exclude='.github' \
  --exclude='node_modules' \
  --exclude='dist' \
  --exclude='bin' \
  --exclude='tests' \
  --exclude='.env' \
  --exclude='.DS_Store' \
  --exclude='*.log' \
  --exclude='composer.lock' \
  --exclude='phpunit.xml*' \
  --exclude='.phpcs.xml*' \
  --exclude='.editorconfig' \
  --exclude='.gitignore' \
  --exclude='.claude' \
  --exclude='CLAUDE.md' \
  "$PLUGIN_DIR/" "$DEST/"

# Install production dependencies only (no dev).
cd "$DEST"
composer install --no-dev --optimize-autoloader --no-interaction --quiet
rm -f composer.lock

echo "Installed production dependencies."

# Create dist directory and zip.
mkdir -p "$DIST_DIR"
ZIP_FILE="$DIST_DIR/$PLUGIN_SLUG.zip"
cd "$BUILD_DIR"
zip -rq "$ZIP_FILE" "$PLUGIN_SLUG"

# Clean up.
rm -rf "$BUILD_DIR"

echo "Done: $ZIP_FILE"
echo "Size: $(du -h "$ZIP_FILE" | cut -f1)"
