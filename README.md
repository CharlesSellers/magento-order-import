# Venuno Order Import — Magento 2 module (`venuno/module-order-import`)

**Release 0.3 — idempotent order import + native order materialisation.**

This module is the **destination-side contract** a Magento 2 store installs so that [Venuno](https://venuno.io)
can verify the connection, idempotently accept replicated orders, and — when materialisation is enabled —
turn each accepted order into a **native Magento sales order**.

It exposes four REST endpoints, each authenticated with a Venuno **per-environment token**:

| Method & path | Returns |
|---|---|
| `GET /V1/venuno/health` | `{"status":"ok"}` |
| `GET /V1/venuno/version` | `{"module_version":"0.3.0","magento_version":"2.4.7","magento_edition":"Community"}` |
| `GET /V1/venuno/capabilities` | `{"order_import":true,"order_materialisation":true,"contract_version":"0.3",…}` |
| `POST /V1/venuno/orders/import` | `{"accepted":true,"duplicate":false,"replay_key":"magento:…","import_status":"imported","magento_order_id":1234,"message":"Order created."}` |

See the ADRs for rationale and stability commitments:
[ADR-0001](docs/adr/ADR-0001-destination-verification-contract.md) (verification contract),
[ADR-0002](docs/adr/ADR-0002-import-domain-contract.md) (import-domain identity + replay contract),
[ADR-0003](docs/adr/ADR-0003-order-import-intake.md) (idempotent intake / staging),
[ADR-0004](docs/adr/ADR-0004-replay-key-column-width.md) (replay_key column width, 0.2.1),
[ADR-0005](docs/adr/ADR-0005-order-materialisation.md) (native order materialisation, 0.3).

## Requirements

- Magento 2.4.x (Open Source / Commerce) — `magento/framework ^103.0`
- PHP 8.1+

## Installation

The repository is **public** and installed via a Composer **VCS** repository (it is not published on
Packagist, so it must be registered as a repository before `require`). No authentication is required.

```bash
# In the Magento project root, register the public repository:
composer config repositories.venuno-order-import vcs https://github.com/CharlesSellers/magento-order-import.git

# Require it (pin the contract version you have verified against):
composer require venuno/module-order-import:^0.3

# Enable and install:
bin/magento module:enable Venuno_OrderImport
bin/magento setup:upgrade
bin/magento cache:flush

# Production mode only — compile DI:
bin/magento setup:di:compile
```

## Configuration — `app/etc/env.php`

Both settings live in `app/etc/env.php`, **per environment** (never in the database or version control):

```php
'venuno' => [
    'order_import' => [
        // The Venuno shared secret (a long random value). A list rotates without downtime.
        'token'  => 'REPLACE_WITH_A_LONG_RANDOM_SECRET',
        // 'tokens' => ['new-secret', 'previous-secret'],

        // Create native Magento orders from accepted imports. Default: false (accept + stage only).
        // Enable on staging first; prove; then enable in production. Reflected by
        // capabilities.order_materialisation.
        'materialise' => true,
    ],
],
```

Generate a strong token, e.g. `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'`. Changing the token or
flag needs no cache flush or upgrade.

## Usage

The Magento REST base path is `/rest/V1/...` (default store) or `/rest/<store_code>/V1/...`. Present the
token as `Authorization: Bearer <token>` (canonical) or `X-Venuno-Token: <token>`. A missing or invalid
token returns **HTTP 401**.

```bash
TOKEN=REPLACE_WITH_A_LONG_RANDOM_SECRET
BASE=https://store.example.com/rest/V1

curl -s "$BASE/venuno/health"       -H "Authorization: Bearer $TOKEN"   # {"status":"ok"}
curl -s "$BASE/venuno/version"      -H "Authorization: Bearer $TOKEN"   # {"module_version":"0.3.0",…}
curl -s "$BASE/venuno/capabilities" -H "Authorization: Bearer $TOKEN"   # {"order_import":true,"order_materialisation":…}
```

## Order import

`POST /V1/venuno/orders/import` accepts a replicated order, validates the import-domain contract, and
**idempotently** records it. It is the destination idempotency authority:

- **Identity is store-aware and never `increment_id` alone.** The source store hosts many storefronts,
  each with its own `increment_id` sequence, so `increment_id` is not globally unique. The contract keys
  on the globally-unique `source_order_entity_id` and carries the full composite identity.
- **First-write-wins on `replay_key`** (a `UNIQUE` column): a repeat POST of the same order is a no-op
  returning the existing record (`duplicate:true`). Repeated source pulls or retries can never create a
  duplicate. `payload_hash` distinguishes a benign re-pull from a genuine content change.
- Required fields: `replay_key`, `source_platform`, `source_base_url`, `source_order_entity_id`.
  Missing fields return **HTTP 422**.

> **Wire format (verified):** the body is wrapped in a top-level `request` object, and **`order` is a
> JSON *string*** (a JSON-encoded order payload), not a nested object — Magento's typed service contract
> rejects a non-string `order` with HTTP 400.

```bash
curl -s -X POST "$BASE/venuno/orders/import" -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" -d '{
    "request": {
      "replay_key": "magento:<sha256>", "payload_hash": "<sha256>",
      "source_platform": "magento", "source_base_url": "https://store.example.com",
      "source_store_id": "4", "source_store_code": "ace_en",
      "source_website_id": "4", "source_order_entity_id": "212733",
      "source_order_increment_id": "100000123", "source_order_display_number": "100000123",
      "original_created_at": "2026-06-23 08:11:36",
      "order": "{\"header\":{\"increment_id\":\"100000123\"},\"billing_address\":{\"email\":\"a@b.com\",\"firstname\":\"A\",\"lastname\":\"B\"},\"line_items\":[{\"sku\":\"ABC\",\"qty_ordered\":1,\"price\":10,\"row_total\":10}],\"totals\":{\"grand_total\":10}}"
    }
  }'
```

## Order materialisation (0.3)

When `venuno/order_import/materialise` is enabled, a successful import is turned into a **native Magento
sales order in the same call** and the response carries the real `magento_order_id`
(`import_status:"imported"`, `message:"Order created."`). When disabled (default), the import is accepted
and **staged** (`import_status:"pending"`, `magento_order_id:0`) — the 0.2 behaviour.

Guarantees (see [ADR-0005](docs/adr/ADR-0005-order-materialisation.md)):

- **Idempotent** — an order is created at most once per `replay_key`; a repeat returns the existing
  `magento_order_id` (`duplicate:true`). Concurrent calls are serialised by an optimistic
  `pending|failed → materialising` claim.
- **Transactional** — the native order and the ledger update commit atomically; a failure rolls both
  back, so there is never a half-built order or an orphan.
- **Replayable** — a `failed` (or still-`pending`) row re-materialises on the next POST and, because a
  failed attempt rolled back, creates exactly one order.
- **Faithful** — the order is built directly from the source payload, so the **source totals are
  preserved** (not recomputed from this store's pricing/tax/shipping) and **inventory is not touched**.
- **Traceable** — the source order number is stored as the Magento order's `ext_order_id` and in a status-
  history comment; the `venuno_order_import` row maps `replay_key ↔ magento_order_id ↔ source identity`.
- **Partial-failure safe** — a terminal data error (bad payload, unknown SKU) returns **HTTP 422**; a
  transient failure returns **HTTP 5xx**; both are recorded on the row (`failed` + `error_message`,
  `attempts`) for diagnosis and replay.

Payment is recorded as an **offline** method (default `checkmo`) — no funds are captured; the order is a
faithful record, not a new sale.

## Versioning & contract stability

- `module_version` is the **contract** version, defined in [`Model/Version.php`](Model/Version.php) and
  mirrored in `composer.json` — bump both together.
- Response keys are append-only: a Venuno client reads responses forward-compatibly (ignore unknown keys;
  treat a missing capability as `false`).

## Tests

- **Unit** (Magento-free; runs with only `phpunit/phpunit`): the pure materialisation core — payload→draft
  mapping + validation, and the idempotency / transaction / partial-failure state machine.
  ```bash
  composer install && composer test:unit
  ```
- **Integration** (real Magento; `dev/tests/integration`): end-to-end materialisation, idempotent replay,
  and unknown-SKU rollback — the live validation gate before enabling `materialise` in production. See
  [`Test/Integration/README.md`](Test/Integration/README.md).

## Repository layout

```
.
├── composer.json
├── phpunit.xml.dist        # Magento-free Unit suite
├── registration.php
├── etc/
│   ├── module.xml          # module declaration (sequenced after Magento_Webapi)
│   ├── webapi.xml          # the REST routes (anonymous ACL; token enforced in services)
│   ├── di.xml              # service + DTO + materialisation preferences
│   └── db_schema.xml       # venuno_order_import idempotency + staging + materialisation ledger
├── Api/                    # webapi service + DTO interfaces (stable JSON shapes)
├── Model/
│   ├── Health.php · Version.php · Capabilities.php
│   ├── OrderImport.php             # idempotent intake + (when enabled) materialisation
│   ├── OrderImportRepository.php   # venuno_order_import persistence + materialisation transitions
│   ├── MaterialisationConfig.php   # the venuno/order_import/materialise flag
│   ├── TokenAuthenticator.php
│   ├── Data/                       # DTO implementations
│   └── Materialisation/
│       ├── OrderDraft.php · OrderDraftBuilder.php          # pure: payload → draft + validation
│       ├── OrderMaterialiser.php                            # pure: idempotency/txn/failure state machine
│       ├── MaterialisationResult.php · MaterialisationException.php
│       ├── NativeOrderGatewayInterface.php · NativeOrderGateway.php   # native order construction
│       ├── ImportRowStoreInterface.php                      # (implemented by OrderImportRepository)
│       └── TransactionRunnerInterface.php · MagentoTransactionRunner.php
├── Test/
│   ├── Unit/Materialisation/       # Magento-free unit tests
│   └── Integration/Materialisation/# real-Magento end-to-end tests
└── docs/adr/                       # ADR-0001 … ADR-0005
```
