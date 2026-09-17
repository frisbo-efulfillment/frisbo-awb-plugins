#!/usr/bin/env bash
set -euo pipefail

module_source=/workspace/modules
module_target=/var/www/html/modules

mkdir -p "$module_source" "$module_target"

for source_path in "$module_source"/*; do
    [ -d "$source_path" ] || continue

    module_name=${source_path##*/}
    target_path="$module_target/$module_name"

    if [ -L "$target_path" ]; then
        if [ "$(readlink "$target_path")" != "$source_path" ]; then
            echo "ERROR: $target_path is a symlink not managed by this environment." >&2
            exit 1
        fi
        continue
    fi

    if [ -e "$target_path" ]; then
        echo "ERROR: refusing to replace existing PrestaShop module directory: $target_path" >&2
        exit 1
    fi

    ln -s "$source_path" "$target_path"
done

for writable_path in \
    app/config \
    app/logs \
    cache \
    config \
    download \
    img \
    log \
    mails \
    modules \
    themes \
    translations \
    upload \
    var; do
    if [ -e "/var/www/html/$writable_path" ]; then
        chmod -R a+rwX "/var/www/html/$writable_path"
    fi
done

exec docker-php-entrypoint "$@"
