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
$module = Module::getInstanceByName("frisbo_awb");
$idShop = (int) Context::getContext()->shop->id;
$idLang = (int) Context::getContext()->language->id;
$carrierRepository = new CarrierRepository($idShop);
$carrierRepository->upsertDiscovered(array("id" => "CI_NATIVE_RATE", "name" => "CI Native Rate"));
$carrierSync = new CarrierSyncService($carrierRepository, $idLang, $idShop);
$carrierSync->synchronizeAll();
$configuredCarrier = $carrierRepository->findByBackendId("CI_NATIVE_RATE");
if (!empty($configuredCarrier["id_carrier"]) || !empty($configuredCarrier["id_reference"])) {
    fwrite(STDERR, "Courier discovery automatically created a PrestaShop carrier.".PHP_EOL);
    exit(1);
}
$unlinkedConfigurationHtml = $module->getContent();
if (strpos($unlinkedConfigurationHtml, "Create PrestaShop carrier") === false) {
    fwrite(STDERR, "The explicit carrier creation button is missing.".PHP_EOL);
    exit(1);
}
$carrierSync->createPrestaShopCarrier((int) $configuredCarrier["id_frisbo_awb_carrier"]);
$configuredCarrier = $carrierRepository->findByBackendId("CI_NATIVE_RATE");
$nativeCarrier = new Carrier((int) $configuredCarrier["id_carrier"]);
if (!Validate::isLoadedObject($nativeCarrier)
    || (bool) $nativeCarrier->shipping_external
    || (bool) $nativeCarrier->active
) {
    fwrite(STDERR, "The managed carrier is not hidden or is not using native PrestaShop pricing.".PHP_EOL);
    exit(1);
}
$carrierRepository->updateConfiguration(
    (int) $configuredCarrier["id_frisbo_awb_carrier"],
    "CI Native Rate",
    false
);
foreach ($carrierRepository->all(true) as $enabledCourier) {
    if ($enabledCourier["backend_carrier_id"] === "CI_NATIVE_RATE") {
        fwrite(STDERR, "A disabled courier remains available in the AWB modal list.".PHP_EOL);
        exit(1);
    }
}
$carrierRepository->updateConfiguration(
    (int) $configuredCarrier["id_frisbo_awb_carrier"],
    "CI Native Rate",
    true
);
$deletedCarrierId = (int) $nativeCarrier->id;
Db::getInstance()->update("carrier", array("deleted" => 1), "id_carrier = ".$deletedCarrierId);
$deletedConfigurationHtml = $module->getContent();
if (strpos($deletedConfigurationHtml, "Recreate hidden carrier") === false) {
    fwrite(STDERR, "A deleted carrier does not expose the recreate action.".PHP_EOL);
    exit(1);
}
$nativeCarrier = $carrierSync->createPrestaShopCarrier(
    (int) $configuredCarrier["id_frisbo_awb_carrier"]
);
$configuredCarrier = $carrierRepository->findByBackendId("CI_NATIVE_RATE");
if ((int) $nativeCarrier->id === $deletedCarrierId
    || (bool) $nativeCarrier->active
    || (bool) $nativeCarrier->deleted
    || (int) $configuredCarrier["id_carrier"] !== (int) $nativeCarrier->id
) {
    fwrite(STDERR, "The deleted carrier mapping was not repaired with a hidden carrier.".PHP_EOL);
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
$trackingUrlColumn = (int) Db::getInstance()->getValue(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = \""._DB_PREFIX_."frisbo_awb_shipment\"
       AND COLUMN_NAME = \"tracking_url\""
);
if ($trackingUrlColumn !== 1) {
    fwrite(STDERR, "The shipment tracking URL column is missing.".PHP_EOL);
    exit(1);
}
$replacementOrder = new Order();
$replacementOrder->id = 2147480000;
$shipmentService = $module->getShipmentService($idShop);
$generationMethod = new ReflectionMethod("ShipmentService", "getGenerationExternalId");
$generationMethod->setAccessible(true);
if ($generationMethod->invoke($shipmentService, $replacementOrder, array(
    "status" => "cancelled",
    "external_reference" => "prestashop:2147480000",
)) !== "2147480000:2"
    || $generationMethod->invoke($shipmentService, $replacementOrder, array(
        "status" => "cancelled",
        "external_reference" => "prestashop:2147480000:2",
    )) !== "2147480000:3"
) {
    fwrite(STDERR, "Cancelled shipments do not advance to a replacement external reference.".PHP_EOL);
    exit(1);
}
$shipmentRepository = new ShipmentRepository($idShop);
if ($shipmentRepository->findByOrderId(2147479999) !== null) {
    fwrite(STDERR, "A missing shipment row is not normalized to null.".PHP_EOL);
    exit(1);
}
Db::getInstance()->execute("START TRANSACTION");
try {
    $shipmentRepository->begin(2147480000, "prestashop:2147480000", "prestashop:2147480000");
    $shipmentRepository->markGenerated(2147480000, array(
        "uid" => "ci-cancelled-shipment",
        "external_reference" => "prestashop:2147480000",
    ));
    $shipmentRepository->markCancelled(2147480000);
    $replacementStarted = $shipmentRepository->begin(
        2147480000,
        "prestashop:2147480000:2",
        "prestashop:2147480000:2"
    );
    $replacementRow = $shipmentRepository->findByOrderId(2147480000);
    if (!$replacementStarted
        || $replacementRow["status"] !== "creating"
        || $replacementRow["external_reference"] !== "prestashop:2147480000:2"
        || !empty($replacementRow["shipment_uid"])
    ) {
        throw new Exception("A cancelled shipment cannot transition to a clean replacement attempt.");
    }
} finally {
    Db::getInstance()->execute("ROLLBACK");
}
Db::getInstance()->update("carrier", array("shipping_external" => 1), "id_carrier = ".(int) $nativeCarrier->id);
require_once "/var/www/html/modules/frisbo_awb/upgrade/upgrade-3.1.0.php";
if (!upgrade_module_3_1_0($module)) {
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

docker exec "$shop_container" php bin/console prestashop:module reset frisbo_awb --no-interaction
docker exec "$shop_container" php bin/console prestashop:module uninstall frisbo_awb --no-interaction

echo "PrestaShop $prestashop_version / PHP $php_version: frisbo_awb install, reset, and uninstall passed"
