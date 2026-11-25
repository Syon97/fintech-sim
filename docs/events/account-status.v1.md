# Events: account.frozen and account.unfrozen (schema v1)

Written to `outbox_events` in the same database transaction as the status change.
Envelope: `aggregate_type` = account, `aggregate_id` = the account id (as text).

| Field | Type | Notes |
|---|---|---|
| account_id | integer | |
| account_number | string | |
| from_status | string | active or frozen |
| to_status | string | frozen (account.frozen) or active (account.unfrozen) |
| actor | string | Username of the person who made the change |
| reason | string | Written reason, required |
| change_id | integer | Row id in the ledger's append-only status audit table |

Unlike transaction.posted, there is no uniqueness rule: an account can be frozen and unfrozen repeatedly.
Consumers must ignore events they do not understand. Monitoring does.