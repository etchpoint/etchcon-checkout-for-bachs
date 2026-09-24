#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BUILD_ROOT="${ROOT_DIR}/build"
PLUGIN_SLUG="payment-integrations-for-bachs"
PLUGIN_DIR="${BUILD_ROOT}/${PLUGIN_SLUG}"

rm -rf "${PLUGIN_DIR}"
mkdir -p "${PLUGIN_DIR}"

cp "${ROOT_DIR}/payment-integrations-for-bachs.php" "${PLUGIN_DIR}/"
cp "${ROOT_DIR}/uninstall.php" "${PLUGIN_DIR}/"
cp "${ROOT_DIR}/readme.txt" "${PLUGIN_DIR}/"
cp "${ROOT_DIR}/LICENSE" "${PLUGIN_DIR}/"
# Keep the Composer manifest with its generated production autoloader for review.
cp "${ROOT_DIR}/composer.json" "${PLUGIN_DIR}/"
cp -R "${ROOT_DIR}/assets" "${PLUGIN_DIR}/assets"
cp -R "${ROOT_DIR}/src" "${PLUGIN_DIR}/src"
cp -R "${ROOT_DIR}/vendor" "${PLUGIN_DIR}/vendor"

find "${PLUGIN_DIR}" -type f -name '.DS_Store' -delete

echo "Built ${PLUGIN_DIR}"
