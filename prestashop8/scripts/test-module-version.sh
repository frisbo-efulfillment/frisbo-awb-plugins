#!/usr/bin/env bash
set -euo pipefail

if [ "$#" -ne 2 ]; then
    echo "Usage: $0 PRESTASHOP_VERSION PHP_VERSION" >&2
    exit 2
fi

prestashop_version=$1
php_version=$2
case "$prestashop_version" in
    8.2.8|8.2.7|8.2.6|8.2.5|8.2.4|8.2.3|8.2.2|8.2.1|8.2.0|\
    8.1.7|8.1.6|8.1.5|8.1.4|8.1.3|8.1.2|8.1.1|8.1.0|\
    8.0.4|8.0.2|8.0.1) ;;
    *)
        echo "ERROR: unsupported PrestaShop version: $prestashop_version" >&2
        exit 2
        ;;
esac
case "$php_version" in
    7.2|8.1) ;;
    *)
        echo "ERROR: unsupported PHP version for the curated matrix: $php_version" >&2
        exit 2
        ;;
esac

root_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
test_suffix=${prestashop_version//./-}-${php_version//./-}-$$
network_name=frisbo-awb-test-$test_suffix
db_container=frisbo-awb-db-$test_suffix
shop_container=frisbo-awb-shop-$test_suffix

cleanup() {
    docker rm -f "$shop_container" "$db_container" >/dev/null 2>&1 || true
    docker network rm "$network_name" >/dev/null 2>&1 || true
}
trap cleanup EXIT

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
    --env PS_INSTALL_AUTO=1 \
    --env PS_DOMAIN=localhost \
    --env PS_DEV_MODE=1 \
    --env DB_SERVER="$db_container" \
    --env DB_NAME=prestashop \
    --env DB_USER=prestashop \
    --env DB_PASSWD=prestashop \
    --env ADMIN_MAIL=admin@example.test \
    --env ADMIN_PASSWD='Dev123456!' \
    --volume "$root_dir/modules:/workspace/modules:ro" \
    "prestashop/prestashop:${prestashop_version}-${php_version}-apache" >/dev/null

for attempt in $(seq 1 120); do
    if docker exec "$shop_container" test -f /var/www/html/app/config/parameters.php && \
        docker exec "$shop_container" php bin/console about --no-interaction >/dev/null 2>&1; then
        break
    fi
    if ! docker inspect --format '{{.State.Running}}' "$shop_container" | grep -q true; then
        docker logs "$shop_container" >&2
        exit 1
    fi
    if [ "$attempt" -eq 120 ]; then
        docker logs "$shop_container" >&2
        exit 1
    fi
    sleep 3
done

docker exec "$shop_container" ln -s /workspace/modules/frisbo_awb /var/www/html/modules/frisbo_awb
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

echo "PrestaShop $prestashop_version / PHP $php_version: frisbo_awb installation passed"
