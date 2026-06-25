# ADR-0004 — `replay_key` column width (0.2.1)

- **Status:** Accepted
- **Date:** 2026-06-25
- **Applies to:** `venuno/module-order-import` (Magento 2 destination module)
- **Builds on:** [ADR-0002](./ADR-0002-import-domain-contract.md), [ADR-0003](./ADR-0003-order-import-intake.md)

## Context

ADR-0003 shipped the idempotent intake with `venuno_order_import.replay_key` as the `UNIQUE`
idempotency anchor, declared `varchar(64)`. The intake had no automated test against a live Magento
(stated in ADR-0003), and verification against a real store revealed the column is **too small for the
contract's own key**.

The Venuno source emits `replay_key` as `"magento:" + sha256(...)` — an 8-char prefix plus a 64-char
hex digest = **72 characters** (see ADR-0002 and the module README example `"replay_key":"magento:…"`).
Inserting it into a `varchar(64)` column fails at the database with *"Data too long for column
'replay_key'"*, surfaced to the client as HTTP 500. So a valid, contract-conformant import is rejected
purely by the column width.

## Decision

Widen `venuno_order_import.replay_key` to **`varchar(191)`** and release it as **0.2.1**.

- `191` comfortably exceeds the 72 chars the current key needs, leaves headroom for a longer prefix or a
  different digest, and is the classic utf8mb4 index-safe length (191 × 4 = 764 bytes, under the 767-byte
  legacy InnoDB index limit) — `replay_key` is a `UNIQUE` (indexed) column.
- This is a **patch** (0.2.1): the wire contract (request/response fields, `capabilities.contract_version`)
  is **unchanged** — only the storage width changes. `module_version` follows the release per ADR-0001.

`bin/magento setup:upgrade` applies the change declaratively: on a store without the table it is created
at `varchar(191)`; on a store that already created it at `varchar(64)` it is widened in place (a safe,
lossless widening — no data migration).

## Consequences

- **+** A valid import (`replay_key` = `magento:<sha256>`) is accepted instead of failing with HTTP 500.
- **+** Declarative schema stays authoritative: the width no longer drifts, and a later `setup:upgrade`
  will not try to shrink it (which a manual `ALTER` workaround would suffer).
- **+** No wire-contract change, so 0.2.0 clients keep working; 0.2.1 is a drop-in upgrade.
- **−** Stores on 0.2.0 must run `composer update venuno/module-order-import && bin/magento setup:upgrade`
  to pick up the wider column (or apply the equivalent `ALTER` once).

## Alternatives considered

- **Leave the column at 64 and shorten the key client-side** — rejected: the `replay_key` is the
  cross-system idempotency anchor (ADR-0002); mangling it at the destination to fit a buggy column would
  fracture replay/idempotency lineage and hide a real schema defect.
- **Document a manual `ALTER` only** — rejected as the permanent fix: Magento's declarative schema is
  authoritative, so a future `setup:upgrade` could revert an out-of-band `ALTER`. The width belongs in
  `db_schema.xml`. (The `ALTER` remains a valid immediate hotfix for a store that cannot upgrade yet.)
