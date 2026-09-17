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
$module = Module::getInstanceByName("frisbo_awb");
$idShop = (int) Context::getContext()->shop->id;
$idLang = (int) Context::getContext()->language->id;
$carrierRepository = new CarrierRepository($idShop);
$carrierRepository->upsertDiscovered(array("id" => "CI_NATIVE_RATE", "name" => "CI Native Rate"));
$carrierSync = new CarrierSyncService($carrierRepository, $idLang, $idShop);
$carrierSync->synchronizeAll();
$configuredCarrier = $carrierRepository->findByBackendId("CI_NATIVE_RATE");
$nativeCarrier = new Carrier((int) $configuredCarrier["id_carrier"]);
if (!Validate::isLoadedObject($nativeCarrier) || (bool) $nativeCarrier->shipping_external) {
    fwrite(STDERR, "The managed carrier is not using native PrestaShop pricing.".PHP_EOL);
    exit(1);
}
$cart = new Cart();
$cart->id_shop = $idShop;
$module->id_carrier = (int) $nativeCarrier->id;
if ((float) $module->getOrderShippingCost($cart, 12.34) !== 12.34) {
    fwrite(STDERR, "The module did not preserve PrestaShop computed shipping.".PHP_EOL);
    exit(1);
}
$configurationHtml = $module->getContent();
if (strpos($configurationHtml, "Configure carrier") === false || strpos($configurationHtml, "shipping_price_") !== false) {
    fwrite(STDERR, "The native carrier configuration link is missing or a module price input remains.".PHP_EOL);
    exit(1);
}
$carrierPriceColumn = (int) Db::getInstance()->getValue(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = \""._DB_PREFIX_."frisbo_awb_carrier\"
       AND COLUMN_NAME = \"shipping_price\""
);
if ($carrierPriceColumn !== 0) {
    fwrite(STDERR, "The courier configuration table still owns a shipping price.".PHP_EOL);
    exit(1);
}
Db::getInstance()->update("carrier", array("shipping_external" => 1), "id_carrier = ".(int) $nativeCarrier->id);
require_once "/var/www/html/modules/frisbo_awb/upgrade/upgrade-2.1.0.php";
if (!upgrade_module_2_1_0($module)) {
    fwrite(STDERR, "The native-pricing upgrade failed.".PHP_EOL);
    exit(1);
}
$upgradedShippingExternal = (int) Db::getInstance()->getValue(
    "SELECT shipping_external FROM "._DB_PREFIX_."carrier WHERE id_carrier = ".(int) $nativeCarrier->id
);
if ($upgradedShippingExternal !== 0) {
    fwrite(STDERR, "The upgrade did not migrate the carrier to native pricing.".PHP_EOL);
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
