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
The legacy CI runtime uses Debian's archived main repository for both PHP
edges; obsolete security and update entries are removed before package setup.
The PHP 7.4 edge additionally builds mbstring against Debian's Oniguruma
development package, as required by that PHP generation.
For PrestaShop 1.7.4.x on PHP 7.4, the test harness suppresses legacy-core
warning/deprecation categories only while running Symfony console commands;
those commands also boot the legacy core in its non-debug console environment.
This does not patch core or relax the module's PHP syntax checks.

## API mapping

The HTTP client follows the Frisbo AWB OpenAPI document served at
`https://cf-main.frisbo.workers.dev/docs/swagger.yaml`:

- tenant base URL: `https://tenant-{compact_client_id}.frisbo.workers.dev`, where
  hyphens are removed from the configured Client ID for the hostname only;
- authentication: bearer API token;
- courier discovery: `GET /api/couriers`;
- shipment creation: `POST /api/shipments`;
- shipment cancellation: `POST /api/shipments/{shipment_uid}/cancel`;
- external lookup: `GET /api/shipments?external_reference=...`;
- label content: the first documented shipment document `download_url`.

The internal identity `(platform, external ID)` is serialized as
`platform:externalId` in the documented `external_reference` field. On the
Back Office order page, AWB opens a courier-selection modal. Selecting Auto
omits `courier_id`, allowing Frisbo to select the courier; selecting a named
Frisbo courier sends its validated backend identity as `courier_id`. Shipment
creation omits `uid`; `external_reference = prestashop:<order ID>` is used for
the first AWB's idempotency and label lookup. A replacement generated after
cancellation uses `prestashop:<order ID>:2`, then `:3`, and so on. A failed or
uncertain attempt retains its reference so a retry cannot create a duplicate.
Once generated, the modal offers label download without creating a second
shipment. It also offers a red **Cancel AWB** action backed by
`POST /api/shipments/{shipment_uid}/cancel`; the top-right × only dismisses
the modal. Cancellation first reconciles the remote shipment, making it safe
when Frisbo already reports it as cancelled. The modal then permits a new AWB.

After shipment creation or label reprint, the module refreshes the Frisbo
shipment by external reference. It saves `tracking_number` on PrestaShop's
native order carrier (and native shipment table when that feature exists), and
saves both `tracking_number` and the per-order `tracking_url` on the module's
shipment record. The AWB modal renders the number as a tracking link. Native
carrier tracking URLs are global templates, so they are not overwritten with
an order-specific Frisbo URL. The returned Frisbo `courier_id` is also saved as
the order's native carrier association, so the Shipping section shows the
actual courier while retaining the original shipping cost. If that courier is
not linked yet, the module creates a hidden native carrier for this purpose.

The AWB action is available on every order, regardless of the checkout
carrier. Recipient, totals, currency, payment module, and COD value are read
from the current PrestaShop order when Generate is clicked; no checkout
snapshot is created or required. Before the first AWB, a linked checkout
carrier is preselected first as **Chosen by customer**, followed by **Auto:
lowest cost** and the other enabled Frisbo couriers.

Products and totals remain part of the internal normalized shipment model.
Only fields accepted by the current Frisbo `CreateShipment` schema are sent.

## Configuration

### Employee permissions

In **Advanced Parameters → Team → Permissions**, select the employee's
profile and grant **View** on Orders, then **View** and **Edit** on the
**Frisbo AWB** entry under Orders in the menu permissions table. The AWB
controller requires Edit for generation, download, cancellation, and status.
The module table's View, Configure, and Uninstall permissions are separate.

Version 1.2.1 makes the AWB permission entry reachable in PrestaShop 1.7's
permissions tree without adding a navigation link. Upgrade existing
installations through Module Manager after uploading the new files; existing
profile grants are preserved. Do not uninstall or reset to apply this update,
as those operations remove the module's configuration and shipment records.

Configure Client ID, Token, friendly carrier names, and enabled state from the
module Configure page. Enabled couriers appear as named choices in the AWB
modal; disabled couriers remain known for historical and automatic shipment
results. Native carriers created by the module start inactive, so they do not
appear at checkout. They may be activated and configured from PrestaShop's
carrier page when checkout use is desired. A blank Token submission preserves
the stored value. The stored token is never rendered back to the browser.

Refreshing couriers only discovers or updates Frisbo courier identities; it
does not create native carriers. An unlinked row exposes an explicit **Create
PrestaShop carrier** action. After creation, that action becomes **Configure
carrier**. Shipment generation may also create the same hidden carrier on
demand when Frisbo returns an unlinked courier. If a linked native carrier is
deleted, the settings row offers **Recreate hidden carrier**; shipment
synchronization also repairs that mapping automatically.
