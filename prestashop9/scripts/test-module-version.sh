#!/usr/bin/env bash
set -euo pipefail

if [ "$#" -ne 2 ]; then
    echo "Usage: $0 PRESTASHOP_VERSION PHP_VERSION" >&2
    exit 2
fi

prestashop_version=$1
php_version=$2

case "$prestashop_version" in
    9.1.5) edition_version=9.1.5-5.0 ;;
    9.1.4) edition_version=9.1.4-5.0 ;;
    9.1.3) edition_version=9.1.3-5.0 ;;
    9.1.2) edition_version=9.1.2-5.0 ;;
    9.1.1) edition_version=9.1.1-4.0 ;;
    9.1.0) edition_version=9.1.0-4.0 ;;
    9.0.3) edition_version=9.0.3-3.0 ;;
    9.0.2) edition_version=9.0.2-2.1 ;;
    9.0.1) edition_version=9.0.1-1.0 ;;
    9.0.0) edition_version=9.0.0-1.0 ;;
    *)
        echo "ERROR: unsupported PrestaShop version: $prestashop_version" >&2
        exit 2
        ;;
esac

case "$prestashop_version" in
    9.1.*)
        case "$php_version" in 8.1|8.2|8.3|8.4|8.5) ;; *) false ;; esac
        ;;
    9.0.*)
        case "$php_version" in 8.1|8.2|8.3|8.4) ;; *) false ;; esac
        ;;
esac || {
    echo "ERROR: PHP $php_version is not supported by PrestaShop $prestashop_version." >&2
    exit 2
}

root_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
test_suffix=${prestashop_version//./-}-${php_version//./-}-$$
network_name=frisbo-awb-ps9-test-$test_suffix
db_container=frisbo-awb-ps9-db-$test_suffix
shop_container=frisbo-awb-ps9-shop-$test_suffix
runtime_image=frisbo-awb-ps9-php:${php_version}
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
archive_url="https://assets.prestashop3.com/dst/edition/corporate/${edition_version}/prestashop_edition_basic_version_${edition_version}.zip"

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
    "$root_dir/docker/php85" >/dev/null

docker network create "$network_name" >/dev/null
docker run --detach \
    --name "$db_container" \
    --network "$network_name" \
    --env MARIADB_DATABASE=prestashop \
    --env MARIADB_USER=prestashop \
    --env MARIADB_PASSWORD=prestashop \
    --env MARIADB_ROOT_PASSWORD=root \
    mariadb:10.11 >/dev/null

for attempt in $(seq 1 60); do
    if docker exec "$db_container" healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1; then
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
    --volume "$root_dir/docker/php85/php-dev.ini:/usr/local/etc/php/conf.d/99-prestashop-dev.ini:ro" \
    "$runtime_image" >/dev/null

docker exec "$shop_container" php -d memory_limit=-1 -d error_reporting=8191 install/index_cli.php \
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

docker exec "$shop_container" php bin/console prestashop:module install frisbo_awb --no-interaction
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
     WHERE h.name = \"displayAdminOrderMain\" AND m.name = \"frisbo_awb\""
);
if ($hook !== 1) {
    fwrite(STDERR, "displayAdminOrderMain is not registered.".PHP_EOL);
    exit(1);
}
'

docker exec "$shop_container" php bin/console prestashop:module reset frisbo_awb --no-interaction
docker exec "$shop_container" php bin/console prestashop:module uninstall frisbo_awb --no-interaction

echo "PrestaShop $prestashop_version / PHP $php_version: frisbo_awb install, reset, and uninstall passed"
