#!/usr/bin/env bash
set -euo pipefail

root_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
shop_dir="$root_dir/shop"
cache_dir="$root_dir/.cache"

shell_archive_url=${PRESTASHOP_ARCHIVE_URL-}
shell_archive_url_set=${PRESTASHOP_ARCHIVE_URL+x}
shell_version=${PRESTASHOP_VERSION-}
shell_version_set=${PRESTASHOP_VERSION+x}

if [ -f "$root_dir/.env" ]; then
    set -a
    # shellcheck disable=SC1091
    source "$root_dir/.env"
    set +a
fi

if [ "$shell_archive_url_set" = x ]; then PRESTASHOP_ARCHIVE_URL=$shell_archive_url; fi
if [ "$shell_version_set" = x ]; then PRESTASHOP_VERSION=$shell_version; fi

PRESTASHOP_VERSION=${PRESTASHOP_VERSION:-9.1.5}
case "$PRESTASHOP_VERSION" in
    9.1.5|9.1.4|9.1.3|9.1.2|9.1.1|9.1.0|\
    9.0.3|9.0.2|9.0.1|9.0.0) ;;
    *)
        echo "ERROR: unsupported PRESTASHOP_VERSION: $PRESTASHOP_VERSION" >&2
        exit 2
        ;;
esac

if [ -z "${PRESTASHOP_ARCHIVE_URL:-}" ]; then
    echo "ERROR: PRESTASHOP_ARCHIVE_URL must be set in .env or the shell." >&2
    echo "Expected the official PrestaShop $PRESTASHOP_VERSION release ZIP; no fallback will be used." >&2
    exit 2
fi

if [ -d "$shop_dir" ] && find "$shop_dir" -mindepth 1 -maxdepth 1 ! -name .gitkeep -print -quit | grep -q .; then
    echo "ERROR: refusing to overwrite populated shop/: $shop_dir" >&2
    exit 1
fi

mkdir -p "$cache_dir" "$shop_dir"
archive_path="$cache_dir/prestashop_${PRESTASHOP_VERSION}.zip"

if [ ! -s "$archive_path" ]; then
    echo "Downloading the configured PrestaShop $PRESTASHOP_VERSION release archive..."
    curl --fail --location --retry 3 --output "$archive_path.part" "$PRESTASHOP_ARCHIVE_URL"
    mv "$archive_path.part" "$archive_path"
else
    echo "Using cached archive: $archive_path"
fi

tmp_dir=$(mktemp -d)
cleanup() {
    rm -rf "$tmp_dir"
}
trap cleanup EXIT

unzip -q "$archive_path" -d "$tmp_dir/download"

inner_archive=$(find "$tmp_dir/download" -type f -name prestashop.zip -print -quit)
if [ -n "$inner_archive" ]; then
    echo "Detected nested prestashop.zip release payload."
    unzip -q "$inner_archive" -d "$shop_dir"
else
    echo "Using application files directly from the release archive."
    shopt -s dotglob nullglob
    extracted_entries=("$tmp_dir/download"/*)
    if [ "${#extracted_entries[@]}" -eq 1 ] && [ -d "${extracted_entries[0]}" ] && \
        [ ! -f "$tmp_dir/download/index.php" ]; then
        cp -a "${extracted_entries[0]}/." "$shop_dir/"
    else
        cp -a "$tmp_dir/download/." "$shop_dir/"
    fi
fi

if [ ! -f "$shop_dir/index.php" ]; then
    echo "ERROR: release extraction failed: shop/index.php is missing." >&2
    exit 1
fi
if [ ! -f "$shop_dir/install/index_cli.php" ]; then
    echo "ERROR: release extraction failed: shop/install/index_cli.php is missing." >&2
    exit 1
fi

version_pattern=${PRESTASHOP_VERSION//./\\.}
if ! grep -qE "define\(['\"]_PS_INSTALL_VERSION_['\"],[[:space:]]*['\"]${version_pattern}['\"]\);" \
    "$shop_dir/install/install_version.php"; then
    echo "ERROR: the configured archive is not exactly PrestaShop $PRESTASHOP_VERSION." >&2
    exit 1
fi

defines_file="$shop_dir/config/defines.inc.php"
if grep -qE "define\(['\"]_PS_MODE_DEV_['\"],[[:space:]]*(true|false)\);" "$defines_file"; then
    sed -ri "s/define\(['\"]_PS_MODE_DEV_['\"],[[:space:]]*(true|false)\);/define('_PS_MODE_DEV_', true);/" "$defines_file"
else
    printf "\ndefine('_PS_MODE_DEV_', true);\n" >> "$defines_file"
fi

echo "PrestaShop $PRESTASHOP_VERSION source prepared in $shop_dir"
