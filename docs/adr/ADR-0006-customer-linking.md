# ADR-0006 — Destination customer linking (0.4)

Status: Accepted (0.4.0)

## Context

Through 0.3 every materialised order was created as a **guest** (`customer_is_guest = true`,
`NOT_LOGGED_IN`). For B2B replication the destination order should belong to the **destination**
Magento customer account where one exists, so it shows in the customer's order history and carries the
right customer group.

The source system knows its own customer, but a source id is meaningless in the destination store. The
order payload now carries a sibling `customer` block:

```json
"customer": { "email": "buyer@example.com", "group_id": 7, "is_guest": false, "source_customer_id": 5567 }
```

`source_customer_id` and `group_id` are **provenance only** — they describe the *source* and MUST NOT be
reused as destination ids (a destination id/group is only meaningful after resolving the account in B).

## Decision

- **Explicit genuine guest** (`is_guest: true`) → keep the 0.3 guest behaviour (guest / `NOT_LOGGED_IN`).
- **Non-guest** (`is_guest: false`) → resolve the destination customer by **email within the order
  store's website** (`CustomerRepositoryInterface::get(email, websiteId)`; an account is unique per
  `(email, website)`), then set the native order's local `customer_id`, local `customer_group_id`
  (the **destination** account's group) and `customer_is_guest = false`.
- **Unmatched non-guest** → fail terminally through the existing failure path
  (`MaterialisationException` reason `customer_not_found`, non-retryable → HTTP 422). The whole
  materialisation rolls back — **never** a silent guest order.
- **Never** reuse `source_customer_id` as a destination id; **never** trust the source `group_id` for
  assignment. They are recorded on the draft for audit only.

### Backward compatibility (chosen fallback)

A payload with **no `customer` block** is a pre-0.4 order and is treated as an **explicit guest** — the
exact 0.3 behaviour. This keeps in-flight 0.3 producers working during the rollout. A `customer` block
that *is* present must declare a boolean `is_guest`, and a non-guest must carry an `email`; both are
terminal contract errors otherwise (never a silent guest fallback).

## Design / testability

The pure, Magento-free core stays unit-testable behind local seams (mirroring the 0.3 pattern):

- `OrderDraftBuilder` parses the block into the draft's intent (`customerIsGuest`, `customerAccountEmail`,
  and provenance `sourceCustomerId`/`sourceGroupId`).
- `CustomerLinkPlanner` (pure) decides guest vs linked over the `DestinationCustomerResolverInterface`
  seam and raises the terminal `customer_not_found` for an unmatched non-guest.
- `MagentoDestinationCustomerResolver` (Magento) implements the `(email, website)` lookup;
  `NativeOrderGateway` applies the plan. Both are covered by the integration suite.

## Consequences

- Registered-customer orders now materialise against the correct destination account and group; guests
  are unchanged; an unmatched non-guest **fails terminally** (`customer_not_found`, HTTP 422) and the
  import row remains in `failed` status for operator action, rather than creating a mis-attributed
  guest order.
- No schema change; the customer block rides inside the existing opaque `order` payload. (A Sage
  AccountReference attribute is explicitly out of scope for 0.4.)
