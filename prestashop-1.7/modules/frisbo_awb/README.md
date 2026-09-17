# frisbo-awb

Legacy carrier module compatible with PrestaShop 1.7.4.3 through 1.7.8.11 and
PHP 7.2 through PHP 7.4.

The included local development environment intentionally remains pinned to
PrestaShop 1.7.4.3 and PHP 7.2 for legacy compatibility testing.

Routine CI installs the module on the oldest supported patch (1.7.4.3) and
the two newest patches (1.7.8.10 and 1.7.8.11), each on PHP 7.2 and PHP 7.4.
PHP syntax is also checked independently on both runtimes. To cull or add a
variant, edit `matrix.include` in `.github/workflows/prestashop-1743.yml`.
Any curated pairing can be reproduced with
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
