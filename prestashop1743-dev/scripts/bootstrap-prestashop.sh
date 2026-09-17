#!/usr/bin/env bash
set -euo pipefail

root_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
shop_dir="$root_dir/shop"
cache_dir="$root_dir/.cache"
archive_path="$cache_dir/prestashop_1.7.4.3.zip"

shell_archive_url=${PRESTASHOP_ARCHIVE_URL-}
shell_archive_url_set=${PRESTASHOP_ARCHIVE_URL+x}

if [ -f "$root_dir/.env" ]; then
    set -a
    # shellcheck disable=SC1091
    source "$root_dir/.env"
    set +a
fi

if [ "$shell_archive_url_set" = x ]; then
    PRESTASHOP_ARCHIVE_URL=$shell_archive_url
fi

if [ -z "${PRESTASHOP_ARCHIVE_URL:-}" ]; then
    echo "ERROR: PRESTASHOP_ARCHIVE_URL must be set in .env or the shell." >&2
    echo "Expected the official PrestaShop 1.7.4.3 release ZIP; no fallback will be used." >&2
    exit 2
fi

if [ -d "$shop_dir" ] && find "$shop_dir" -mindepth 1 -maxdepth 1 -print -quit | grep -q .; then
    echo "ERROR: refusing to overwrite populated shop/: $shop_dir" >&2
    exit 1
fi

mkdir -p "$cache_dir" "$shop_dir"

if [ ! -s "$archive_path" ]; then
    echo "Downloading the configured PrestaShop 1.7.4.3 release archive..."
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

if ! grep -qE "define\(['\"]_PS_INSTALL_VERSION_['\"],[[:space:]]*['\"]1\.7\.4\.3['\"]\);" \
    "$shop_dir/install/install_version.php"; then
    echo "ERROR: the configured archive is not exactly PrestaShop 1.7.4.3." >&2
    exit 1
fi

defines_file="$shop_dir/config/defines.inc.php"
if grep -qE "define\(['\"]_PS_MODE_DEV_['\"],[[:space:]]*(true|false)\);" "$defines_file"; then
    sed -ri "s/define\(['\"]_PS_MODE_DEV_['\"],[[:space:]]*(true|false)\);/define('_PS_MODE_DEV_', true);/" "$defines_file"
else
    printf "\ndefine('_PS_MODE_DEV_', true);\n" >> "$defines_file"
fi

echo "PrestaShop 1.7.4.3 source prepared in $shop_dir"
