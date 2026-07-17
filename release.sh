#!/usr/bin/env bash
# Build a production zip of flexa-site-migrator into ./build/.
set -euo pipefail

PLUGIN_SLUG="flexa-site-migrator"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BUILD_DIR="${ROOT_DIR}/build"
STAGE_DIR="${BUILD_DIR}/${PLUGIN_SLUG}"

cd "${ROOT_DIR}"

# Read plugin version from the main file.
VERSION="$(grep -E '^[[:space:]]*\*[[:space:]]*Version:' "${PLUGIN_SLUG}.php" | head -1 | sed -E 's/.*Version:[[:space:]]*([^[:space:]]+).*/\1/')"
if [[ -z "${VERSION}" ]]; then
    echo "Could not read Version from ${PLUGIN_SLUG}.php" >&2
    exit 1
fi
echo "Building ${PLUGIN_SLUG} v${VERSION}"

# Fresh dependencies (only if this plugin declares any).
if [[ -f composer.json ]]; then
    composer install --no-dev --prefer-dist --optimize-autoloader
fi
if [[ -f package.json ]]; then
    pnpm install --frozen-lockfile
    pnpm build
fi

# Regenerate the translation template if wp-cli is available.
if command -v wp >/dev/null 2>&1; then
    wp i18n make-pot . "languages/${PLUGIN_SLUG}.pot" \
        --domain="${PLUGIN_SLUG}" \
        --exclude=templates/installer.tpl >/dev/null 2>&1 || true
fi

# Stage.
rm -rf "${BUILD_DIR}"
mkdir -p "${STAGE_DIR}"

EXCLUDES=()
if [[ -f .distignore ]]; then
    while IFS= read -r line; do
        line="${line%%#*}"
        line="${line## }"
        line="${line%% }"
        [[ -z "${line}" ]] && continue
        EXCLUDES+=(--exclude="${line#/}")
    done < .distignore
fi

rsync -a "${EXCLUDES[@]}" --exclude="build" --exclude=".git" "${ROOT_DIR}/" "${STAGE_DIR}/"

cd "${BUILD_DIR}"
ZIP_NAME="${PLUGIN_SLUG}-${VERSION}.zip"
rm -f "${ZIP_NAME}"
zip -rq "${ZIP_NAME}" "${PLUGIN_SLUG}"
echo "Built ${BUILD_DIR}/${ZIP_NAME}"

# Drop the staging tree - only the zip needs to stay in build/.
rm -rf "${STAGE_DIR}"

# Restore dev dependencies so the working tree stays usable.
cd "${ROOT_DIR}"
if [[ -f composer.json ]]; then
    composer install
fi
