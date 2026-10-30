# Event: transaction.posted (schema v1)

Emitted once for every committed ledger transaction, including reversals.
Written to `outbox_events` in the same database transaction as the posting.

| Field | Type | Notes |
|---|---|---|
| transaction_id | uuid | Same as the envelope's aggregate_id |
| type | string | deposit, withdrawal, transfer, fee, reversal |
| effective_date | date | Business date (YYYY-MM-DD) |
| description | string or null | |
| reverses_transaction_id | uuid or null | Set only when type = reversal |
| entries[] | array | One element per ledger entry |
| entries[].account_id | integer | |
| entries[].account_number | string | Consumers never need to read ledger tables |
| entries[].account_type | string | asset, liability, equity, income, expense |
| entries[].direction | "D" or "C" | |
| entries[].amount | integer | Minor units (sen), always positive |
| entries[].currency | string | ISO 4217 |
| entries[].balance_after | integer | Account balance after this entry, minor units |

## Envelope (outbox columns)
`event_id` (public uuid), `event_type`, `schema_version`, `aggregate_type`, `aggregate_id`, `occurred_at`.
`occurred_at` is the time the event row was written, after the account locks were taken.

## Delivery guarantees
- At-least-once delivery; each consumer processes each event effectively once (inbox primary key).
- Ordering is guaranteed per account, not globally. Consumers must use `occurred_at`, not arrival order.
- Events failing 5 times are quarantined (visible in `outbox_events`, no longer retried).

## Compatibility
- Adding fields keeps the version at 1. Consumers must ignore unknown fields.
- Removing or changing a field's meaning requires schema_version 2, published alongside v1 during migration.