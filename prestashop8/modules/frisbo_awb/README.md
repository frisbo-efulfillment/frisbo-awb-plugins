# frisbo-awb

Frisbo AWB carrier module compatible with PrestaShop 8.0.1 through 8.2.8 and
PHP 7.2.5 through PHP 8.1.

The included local development environment defaults to PrestaShop 8.2.8 and
PHP 8.1. Set `PRESTASHOP_VERSION` and the matching official release archive URL
to reproduce another supported PrestaShop 8 release.

Routine CI installs the module on these PrestaShop/PHP edges:

- oldest supported patch 8.0.1 on PHP 7.2 and PHP 8.1;
- newest patches 8.2.7 and 8.2.8 on PHP 7.2 and PHP 8.1.

Module PHP syntax is checked independently on PHP 7.2, 7.3, 7.4, 8.0, and
8.1. PrestaShop 8 itself requires PHP 7.2.5 or newer. To cull or add a
variant, edit `matrix.include` in `.github/workflows/prestashop-8.yml`. Any
supported pairing can be run with
`./scripts/test-module-version.sh PRESTASHOP_VERSION PHP_VERSION`.

## API mapping

The HTTP client follows the Frisbo AWB OpenAPI document served at
`https://cf-main.frisbo.workers.dev/docs/swagger.yaml`:

- tenant base URL: `https://tenant-{compact_client_id}.frisbo.workers.dev`, where
  hyphens are removed from the configured Client ID for the hostname only;
- authentication: bearer API token;
- courier discovery: `GET /api/couriers`;
- shipment creation: `POST /api/shipments`;
- external lookup: `GET /api/shipments?external_reference=...`;
- label content: the first documented shipment document `download_url`.

The internal identity `(platform, external ID)` is serialized as
`platform:externalId` in the documented `external_reference` field. The saved
customer-facing courier name and locally configured shipping price are placed
in the documented `shipment_notes` field. The Frisbo courier identity is sent
as `courier_id`. Shipment creation omits `uid`; the stable
`external_reference = prestashop:<order ID>` is used for idempotency and label
lookup.

Products and totals remain part of the internal normalized shipment model.
Only fields accepted by the current Frisbo `CreateShipment` schema are sent.

## Configuration

Configure Client ID, Token, friendly carrier names, fixed prices, and enabled
state from the module Configure page. A blank Token submission preserves the
stored value. The stored token is never rendered back to the browser.
