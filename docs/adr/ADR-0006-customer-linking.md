# ADR-0006 — Registered-customer linking and account-reference propagation (0.4)

Status: Accepted (0.4.0)

## Context

Through 0.3 every materialised order was created as a guest. That leaves `sales_order.customer_id`
empty, so downstream API/SFTP consumers cannot obtain the customer's account reference and emit
`UNKNOWN` even when the source Magento customer has `short_account_ref`.

Venuno 0.4 carries customer identity independently of billing and shipping contacts:

```json
"customer": {
  "email": "buyer@example.com",
  "firstname": "Ada",
  "lastname": "Lovelace",
  "source_customer_id": "5567",
  "source_customer_group_id": "7",
  "source_customer_is_guest": false,
  "registration_required": true,
  "account_reference": "BC061-5",
  "account_reference_attribute": "short_account_ref"
}
```

Source ids and groups are provenance only. They are not safe destination ids.

## Decision

- `registration_required: true` always requires a registered destination customer, including when the
  source order was marked as a guest. There is no guest fallback.
- Resolve independently by email within the order store's website and, when supplied, by the configured
  account-reference attribute. If both identify customers, they must identify the same customer.
- Use the destination customer's id and group on the native order. Never copy source ids/groups.
- The effective account reference is the source value or the resolved destination attribute. If both
  exist they must agree (case-insensitively). A registration-required import with neither fails closed.
- Persist the effective reference and account name on `sales_order`; expose both through the standard
  order API as `extension_attributes.venuno_account_reference` and
  `extension_attributes.venuno_account_name`.
- Missing, ambiguous or conflicting identity is a terminal 422 data failure. The order transaction
  rolls back and remains replayable after the data is corrected.
- A source guest may remain a guest only when `registration_required` is explicitly false. A legacy
  payload with no customer block keeps the 0.3 guest behaviour for rolling compatibility.

## Why the module does not create unmatched customers

Jangro customer accounts determine customer group, contract pricing and restricted-category access.
Creating an unmatched account with a guessed/default group would make the order appear successful while
silently assigning the wrong commercial permissions. Every Jangro customer is expected to be registered,
so an unmatched account is treated as a fixable data/migration problem instead.

## Consequences

- Imported Jangro orders have a real `customer_id`, the correct destination group and an API-visible
  account reference for the XML exporter.
- The XML process no longer needs to invent an `UNKNOWN` fallback. It should read the Venuno extension
  attribute and reject/quarantine an order if it is unexpectedly absent.
- Existing 0.3 producers keep their guest behavior until they send the explicit 0.4 customer block.
