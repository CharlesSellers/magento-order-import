# Venuno Order Import — Magento 2 module (`venuno/module-order-import`)

**Release 0.1 — destination verification contract.**

This module is the **destination-side contract** a Magento 2 store installs so that [Venuno](https://venuno.io)
can **verify it can connect, authenticate and is compatible** — *before* any orders flow. It deliberately
does **not** import orders yet. Its sole job in 0.1 is to let a destination store prove the contract.

It exposes three read-only REST endpoints, each authenticated with a Venuno **per-environment token**:

| Method & path | Returns |
|---|---|
| `GET /V1/venuno/health` | `{"status":"ok"}` |
| `GET /V1/venuno/version` | `{"module_version":"0.1.0","magento_version":"2.4.7","magento_edition":"Community"}` |
| `GET /V1/venuno/capabilities` | `{"order_import":false}` |

See [`docs/adr/ADR-0001-destination-verification-contract.md`](docs/adr/ADR-0001-destination-verification-contract.md)
for the contract rationale and stability commitments.

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
composer require venuno/module-order-import:^0.1

# Enable and install:
bin/magento module:enable Venuno_OrderImport
bin/magento setup:upgrade
bin/magento cache:flush

# Production mode only — compile DI:
bin/magento setup:di:compile
```

## Configuration — the per-environment token

The token is a Venuno shared secret, kept **per environment** in `app/etc/env.php` (never in the database
or version control). Each environment (dev / staging / production) carries its own value:

```php
// app/etc/env.php
'venuno' => [
    'order_import' => [
        // A single token:
        'token' => 'REPLACE_WITH_A_LONG_RANDOM_SECRET',

        // …or a list, to rotate without downtime (any listed token is accepted):
        // 'tokens' => ['new-secret', 'previous-secret'],
    ],
],
```

Generate a strong token, e.g. `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'`, and configure the same
value in Venuno for that environment. No cache flush or upgrade is needed after changing it.

## Usage

The Magento REST base path is `/rest/V1/...` (default store) or `/rest/<store_code>/V1/...`. Present the
token as `Authorization: Bearer <token>` (canonical) or `X-Venuno-Token: <token>`.

```bash
TOKEN=REPLACE_WITH_A_LONG_RANDOM_SECRET
BASE=https://store.example.com/rest/V1

curl -s "$BASE/venuno/health"       -H "Authorization: Bearer $TOKEN"
# {"status":"ok"}

curl -s "$BASE/venuno/version"      -H "Authorization: Bearer $TOKEN"
# {"module_version":"0.1.0","magento_version":"2.4.7","magento_edition":"Community"}

curl -s "$BASE/venuno/capabilities" -H "Authorization: Bearer $TOKEN"
# {"order_import":false}
```

A missing or invalid token returns **HTTP 401**:

```bash
curl -s -o /dev/null -w '%{http_code}\n' "$BASE/venuno/health"   # 401
```

### How Venuno verifies a connection

1. `GET /V1/venuno/health` with the environment token → expect `200 {"status":"ok"}` (reachable + module
   installed + token valid).
2. `GET /V1/venuno/version` → assert the Magento version is supported and record the module version.
3. `GET /V1/venuno/capabilities` → discover what the store will accept (in 0.1, `order_import:false`).

## Authentication model

These routes are declared `anonymous` to Magento's own ACL because authentication is the **Venuno token**,
validated in the service layer ([`Model/TokenAuthenticator.php`](Model/TokenAuthenticator.php)) — not a
Magento admin or integration token. Every endpoint enforces the token and rejects unauthenticated calls
with 401. Token comparison is constant-time; a list of tokens is supported for zero-downtime rotation.

## Versioning & contract stability

- `module_version` is the **contract** version. It is defined once in
  [`Model/Version.php`](Model/Version.php) (`MODULE_VERSION`) and mirrored in `composer.json` — bump both
  together.
- Response keys are append-only: existing keys never change meaning, so a Venuno client can read responses
  forward-compatibly (ignore unknown keys; treat a missing capability as `false`).

## What this release is **not**

No order ingestion. No write endpoints. `capabilities.order_import` is `false` and flips to `true` only in
a future release that genuinely accepts inbound orders. See the ADR for the roadmap.

## Repository layout

```
.
├── composer.json
├── registration.php
├── README.md
├── LICENSE
├── etc/
│   ├── module.xml          # module declaration (sequenced after Magento_Webapi)
│   ├── webapi.xml          # the three REST routes (anonymous ACL; token enforced in services)
│   └── di.xml              # service + DTO preferences
├── Api/
│   ├── HealthInterface.php
│   ├── VersionInterface.php
│   ├── CapabilitiesInterface.php
│   └── Data/               # typed result DTO interfaces (stable JSON shapes)
│       ├── HealthResultInterface.php
│       ├── VersionResultInterface.php
│       └── CapabilitiesResultInterface.php
├── Model/
│   ├── Health.php          # service implementations (webapi has no MVC controllers)
│   ├── Version.php
│   ├── Capabilities.php
│   ├── TokenAuthenticator.php
│   └── Data/               # DTO implementations
│       ├── HealthResult.php
│       ├── VersionResult.php
│       └── CapabilitiesResult.php
└── docs/adr/
    └── ADR-0001-destination-verification-contract.md
```
