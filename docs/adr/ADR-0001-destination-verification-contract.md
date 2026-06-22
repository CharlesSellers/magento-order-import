# ADR-0001 — Destination Verification Contract (Release 0.1)

- **Status:** Accepted
- **Date:** 2026-06-22
- **Applies to:** `venuno/module-order-import` (Magento 2 destination module)

## Context

Before Venuno integrates with a destination store it must answer three questions, cheaply and safely:

1. **Can we reach it?** Is the store online and the Venuno module installed?
2. **Can we authenticate?** Does our environment-specific credential work?
3. **Is it compatible, and what will it accept?** Which Magento version is running, and which destination
   capabilities exist yet?

Building order import first would couple "can we connect" to a large, risky surface and give us nothing to
verify against until that surface exists. We want a **small, stable contract** that destination stores can
install and that Venuno can verify against immediately — independent of, and stable across, later feature
work.

The failure modes we want to make impossible at this stage: shipping order ingestion before the connection
is provably verifiable; leaking a verification credential into the database or version control; and a
contract whose response shapes drift as features land.

## Decision

Ship **Release 0.1 as a verification-only contract** — read-only, no order ingestion — comprising three
REST endpoints, each authenticated with a Venuno per-environment token:

| Endpoint | Response |
|---|---|
| `GET /V1/venuno/health` | `{"status":"ok"}` |
| `GET /V1/venuno/version` | `{"module_version","magento_version","magento_edition"}` |
| `GET /V1/venuno/capabilities` | `{"order_import":false}` |

Specific decisions:

1. **Capability negotiation is explicit and forward-compatible.** `capabilities` advertises booleans;
   `order_import` is `false` in 0.1 and only flips to `true` in a release that genuinely accepts orders.
   Venuno must consult it before attempting any behaviour. New capabilities are added as new keys; existing
   keys never change meaning (append-only), and a missing key is read as `false`.

2. **Authentication is a Venuno per-environment token, not a Magento token.** The endpoints are
   `anonymous` to Magento's ACL; the token is validated in the service layer against secrets configured in
   `app/etc/env.php` (`venuno/order_import/token`, or a `tokens` list for rotation). This keeps the secret
   out of the database and version control, gives each environment (dev/staging/prod) an independent
   credential, and decouples Venuno's credential from Magento admin/integration accounts. Comparison is
   constant-time; rotation is zero-downtime via the token list.

3. **All three endpoints require the token** — including `health`. The purpose is *verification*, so
   proving authentication is part of the contract; a public liveness probe is a non-goal here.

4. **Responses are typed DTOs (service contracts), not ad-hoc arrays.** Each endpoint returns a declared
   `Api\Data\*Interface`, so the JSON shape is part of the published contract and is introspectable, rather
   than an implementation detail that can drift.

5. **The module version is the contract version.** Defined once (`Model\Version::MODULE_VERSION`) and
   mirrored in `composer.json`; bumped deliberately when the contract changes.

## Consequences

- **+** Venuno can verify reachability, authentication and compatibility on day one, against a contract
  that will not move under it.
- **+** Order import can be designed and shipped later without renegotiating connection/verification.
- **+** The credential model is environment-isolated and rotatable, with no secret in the DB or VCS.
- **+** `capabilities` lets Venuno light up destination behaviour incrementally and safely.
- **−** The token lives in `app/etc/env.php`, an operational step the store operator must perform per
  environment (documented in the README).
- **−** `MODULE_VERSION` and `composer.json` must be kept in sync by hand.
- **−** Treating `health` as authenticated means it is not a general-purpose unauthenticated liveness probe.

## Alternatives considered

- **Magento integration / OAuth tokens** instead of a Venuno token — rejected: Venuno needs its own
  per-environment secret it controls and rotates, decoupled from Magento admin accounts and consistent
  across destination platforms; a Magento integration token is store-managed and platform-specific.
- **Plain array responses** instead of DTOs — rejected: the verification contract must be stable and
  introspectable; typed service contracts make the JSON shape explicit.
- **Shipping order import in 0.1** — rejected: the explicit goal is to establish and verify a stable
  contract first; ingestion is deferred until the connection is provably verifiable.
- **Unauthenticated `health`** — rejected: verification must prove authentication, not just reachability.

## Roadmap (non-binding)

- **0.2+** — order ingestion endpoints; `capabilities.order_import` → `true`; idempotency + validation
  contract; a superseding ADR for the ingestion shape.
