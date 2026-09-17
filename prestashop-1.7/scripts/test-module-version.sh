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
require_once "/var/www/html/modules/frisbo_awb/upgrade/upgrade-1.1.0.php";
if (!upgrade_module_1_1_0($module)) {
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
