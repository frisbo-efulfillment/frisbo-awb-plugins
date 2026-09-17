#!/usr/bin/env bash
set -euo pipefail

if [ "$#" -ne 2 ]; then
    echo "Usage: $0 PRESTASHOP_VERSION PHP_VERSION" >&2
    exit 2
fi

prestashop_version=$1
php_version=$2

case "$prestashop_version" in
    1.7.4.3|1.7.8.10|1.7.8.11) ;;
    *)
        echo "ERROR: unsupported curated PrestaShop version: $prestashop_version" >&2
        exit 2
        ;;
esac
case "$php_version" in
    7.2|7.4) ;;
    *)
        echo "ERROR: unsupported PHP version for the curated matrix: $php_version" >&2
        exit 2
        ;;
esac

root_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
test_suffix=${prestashop_version//./-}-${php_version//./-}-$$
network_name=frisbo-awb-ps17-test-$test_suffix
db_container=frisbo-awb-ps17-db-$test_suffix
shop_container=frisbo-awb-ps17-shop-$test_suffix
runtime_image=frisbo-awb-ps17-php:${php_version}
tmp_dir=$(mktemp -d)

cleanup() {
    docker exec "$shop_container" chmod -R a+rwX /var/www/html >/dev/null 2>&1 || true
    docker rm -f "$shop_container" "$db_container" >/dev/null 2>&1 || true
    docker network rm "$network_name" >/dev/null 2>&1 || true
    rm -rf "$tmp_dir"
}
trap cleanup EXIT

archive_path="$tmp_dir/prestashop.zip"
download_dir="$tmp_dir/download"
shop_dir="$tmp_dir/shop"
archive_url="https://github.com/PrestaShop/PrestaShop/releases/download/${prestashop_version}/prestashop_${prestashop_version}.zip"

curl --fail --location --retry 3 --output "$archive_path" "$archive_url"
mkdir -p "$download_dir" "$shop_dir"
unzip -q "$archive_path" -d "$download_dir"
inner_archive=$(find "$download_dir" -type f -name prestashop.zip -print -quit)
if [ -n "$inner_archive" ]; then
    unzip -q "$inner_archive" -d "$shop_dir"
else
    shopt -s dotglob nullglob
    extracted_entries=("$download_dir"/*)
    if [ "${#extracted_entries[@]}" -eq 1 ] && [ -d "${extracted_entries[0]}" ] && [ ! -f "$download_dir/index.php" ]; then
        cp -a "${extracted_entries[0]}/." "$shop_dir/"
    else
        cp -a "$download_dir/." "$shop_dir/"
    fi
fi

test -f "$shop_dir/index.php"
test -f "$shop_dir/install/index_cli.php"

docker build \
    --build-arg "PHP_VERSION=$php_version" \
    --tag "$runtime_image" \
    "$root_dir/docker/php72" >/dev/null

docker network create "$network_name" >/dev/null
docker run --detach \
    --name "$db_container" \
    --network "$network_name" \
    --env MYSQL_DATABASE=prestashop \
    --env MYSQL_USER=prestashop \
    --env MYSQL_PASSWORD=prestashop \
    --env MYSQL_ROOT_PASSWORD=root \
    mariadb:10.3 >/dev/null

for attempt in $(seq 1 60); do
    if docker exec "$db_container" mysqladmin ping --silent -h 127.0.0.1 -uroot -proot >/dev/null 2>&1; then
        break
    fi
    if [ "$attempt" -eq 60 ]; then
        docker logs "$db_container" >&2
        exit 1
    fi
    sleep 2
done

docker run --detach \
    --name "$shop_container" \
    --network "$network_name" \
    --volume "$shop_dir:/var/www/html" \
    --volume "$root_dir/modules:/workspace/modules:ro" \
    --volume "$root_dir/docker/php72/php-dev.ini:/usr/local/etc/php/conf.d/99-prestashop-dev.ini:ro" \
    "$runtime_image" >/dev/null

docker exec "$shop_container" php -d memory_limit=-1 install/index_cli.php \
    --domain=localhost \
    --db_server="$db_container:3306" \
    --db_name=prestashop \
    --db_user=prestashop \
    --db_password=prestashop \
    --prefix=ps_ \
    --firstname=Dev \
    --lastname=Admin \
    --password='Dev123456!' \
    --email=admin@example.test \
    --language=en \
    --country=ro \
    --newsletter=0 \
    --send_email=0 \
    --ssl=0

console_php=(php)
console_options=(--no-interaction)
if [ "$php_version" = "7.4" ]; then
    # PrestaShop 1.7.4.x dependencies emit PHP 7.4 compile-time warnings that
    # Symfony converts into exceptions before the module command can run.
    # Keep notices and ordinary errors enabled, but suppress warnings and both
    # deprecated categories for these legacy-core console invocations only.
    console_php+=("-d" "error_reporting=8189")
    console_options+=("--env=prod")
fi

docker exec "$shop_container" "${console_php[@]}" bin/console prestashop:module install frisbo_awb "${console_options[@]}"
docker exec --env EXPECTED_PS_VERSION="$prestashop_version" --env EXPECTED_PHP_VERSION="$php_version" "$shop_container" php -r '
require "/var/www/html/config/config.inc.php";
if (_PS_VERSION_ !== getenv("EXPECTED_PS_VERSION")) {
    fwrite(STDERR, "Unexpected PrestaShop version: "._PS_VERSION_.PHP_EOL);
    exit(1);
}
if (strpos(PHP_VERSION, getenv("EXPECTED_PHP_VERSION").".") !== 0) {
    fwrite(STDERR, "Unexpected PHP version: ".PHP_VERSION.PHP_EOL);
    exit(1);
}
if (!Module::isInstalled("frisbo_awb")) {
    fwrite(STDERR, "frisbo_awb is not installed.".PHP_EOL);
    exit(1);
}
$hook = (int) Db::getInstance()->getValue(
    "SELECT COUNT(*) FROM "._DB_PREFIX_."hook h
     INNER JOIN "._DB_PREFIX_."hook_module hm ON hm.id_hook = h.id_hook
     INNER JOIN "._DB_PREFIX_."module m ON m.id_module = hm.id_module
     WHERE h.name = \"displayAdminOrder\" AND m.name = \"frisbo_awb\""
);
if ($hook !== 1) {
    fwrite(STDERR, "displayAdminOrder is not registered.".PHP_EOL);
    exit(1);
}
'

docker exec "$shop_container" "${console_php[@]}" bin/console prestashop:module reset frisbo_awb "${console_options[@]}"
docker exec "$shop_container" "${console_php[@]}" bin/console prestashop:module uninstall frisbo_awb "${console_options[@]}"

echo "PrestaShop $prestashop_version / PHP $php_version: frisbo_awb install, reset, and uninstall passed"
