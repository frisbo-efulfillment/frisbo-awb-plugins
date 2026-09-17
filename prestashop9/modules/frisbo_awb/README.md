# frisbo-awb

Frisbo AWB carrier module for PrestaShop 9.0 and 9.1.

The included local development environment defaults to PrestaShop 9.1.5 and
PHP 8.5. Set `PRESTASHOP_VERSION` and the matching official release archive URL
to reproduce another supported PrestaShop 9 release.

Routine CI installs the module on these PrestaShop/PHP edges:

- oldest supported patch 9.0.0 on PHP 8.1 and PHP 8.4;
- newest patches 9.1.4 and 9.1.5 on PHP 8.1 and PHP 8.5.

PHP syntax is also checked independently on the PHP 8.1 and PHP 8.5 edges. To
cull or add a variant, edit the matrices in `.github/workflows/prestashop-9.yml`.
Any supported pairing can be tested locally with
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
