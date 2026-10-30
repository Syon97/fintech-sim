# Fintech Simulation: Ledger and Transaction Monitoring

A double-entry ledger with a transaction monitoring engine, built with Laravel
and PostgreSQL. **This is a simulation using synthetic data only. It is not
production software and handles no real money.**

The project is built to demonstrate banking domain logic, not CRUD: correctness
under concurrency, auditability, and business-configurable controls.

## Status

| Phase | Scope | Status |
|---|---|---|
| 1 | Ledger core: accounts, posting, idempotency, reversals, reconciliation, concurrency stress test | Done |
| 2 | Event outbox between ledger and monitoring | Done |
| 3 | Monitoring engine: configurable rules, alerts, backtesting | Planned |
| 4 | Case management and analyst UI (React + TypeScript) | Planned |
| 5 | Reporting, synthetic data generator, demo | Planned |

## Architecture

A modular monolith. The ledger is the system of record. The monitoring engine
will be a consumer of ledger events and must never read or write ledger tables
directly.

```
[Client/API] -> [Ledger Service] -> (transaction.posted event via outbox)
                                          |
                                          v
                            [Monitoring Engine: rules -> alerts]
                                          |
                                          v
                               [Case Queue + Analyst UI]
```

```
fintech-sim/
  backend/    Laravel app (app/Ledger now, app/Monitoring later)
  frontend/   React + TypeScript analyst UI (Phase 4)
  docs/       Stress results now; BRD, FSD, ADRs later
```

## Ledger correctness

| Guarantee | Enforced by |
|---|---|
| Money is stored as integer sen, never floats | `bigint` columns |
| Every transaction balances (debits = credits) | Deferred constraint trigger at COMMIT, plus service validation |
| History is never rewritten | Append-only triggers on `entries` and `transactions`; corrections are reversing transactions |
| A transaction is reversed at most once | Unique constraint, plus an explicit check under the account locks |
| Retries never double-post | Idempotency keys; the same key with a different payload is rejected |
| No lost updates between concurrent postings | Row locks (`SELECT ... FOR UPDATE`) on balances |
| No deadlocks between concurrent transfers | Accounts always locked in ascending id order |
| Entry currency matches account currency | Composite foreign key `(account_id, currency)` |
| Cached balances match entry history | `ledger:reconcile` command; `balance_after` stored on every entry |
| Failed postings leave no trace | Single database transaction, including the idempotency claim |
| An event exists if and only if its transaction committed | Outbox row written in the posting's database transaction |
| Each event is published once, even with concurrent publishers | `FOR UPDATE SKIP LOCKED`; consumer inbox with a primary key on `(consumer, event_id)` |
| Event history cannot be rewritten | Trigger permits only delivery bookkeeping updates |

The rules are enforced twice on purpose: in the service for clear errors, and in
the database so that a bug in application code still cannot store a wrong ledger.

### Design decisions

- **Normal side.** A customer deposit account is a liability of the bank, so it is
  credit-normal. A deposit debits bank cash and credits the customer account.
  The normal side is derived from the account type and never supplied by callers.
- **Reversals.** A reversal is a new transaction of type `reversal` with every
  direction flipped and `reverses_transaction_id` set. A transaction can be
  reversed once, and a reversal cannot itself be reversed.
- **Reversals obey overdraft rules.** If the money has already been spent, the
  reversal fails with `INSUFFICIENT_FUNDS`. Real banks handle this through a
  separate recovery process. That is a policy choice, recorded here as such.
- **Stable error codes.** `LedgerException` carries a machine-readable `reason`
  (for example `INSUFFICIENT_FUNDS`, `ALREADY_REVERSED`) so callers and the
  monitoring engine branch on codes, never on message text.

## Concurrency proof

`php artisan ledger:stress` launches parallel PHP processes against the same few
accounts: transfers, simulated client retries, and every worker racing to
reverse the same transactions. It then verifies:

- Reconciliation passes (entries, cached balances and balance history agree)
- Money is conserved (bank cash equals the sum of customer balances)
- No customer balance is negative
- Every reported posting exists exactly once
- No orphaned idempotency claims
- Each reversal target is reversed at most once, and counts agree
- No deadlocks or raw database errors

Latest run: 16 workers x 500 ops against 3 customer accounts (heavy contention),
18.4s on a development laptop.

| Metric | Count |
|---|---|
| Postings committed (transfers + reversals) | 8,050 |
| Client retries replayed correctly (same key) | 1,604 |
| Reversal attempts (16 workers racing for 50 targets) | 800 |
| ...succeeded (exactly one per target) | 50 |
| ...rejected as already reversed | 750 |
| Deadlocks / unexpected database errors | 0 |

All 8 verification checks passed. Raw output is in `docs/stress-results/`.

Timings are from a single development machine with the app, PostgreSQL and the
workers sharing one box, so they are not a performance claim.

Phase 2 added 4 publisher processes running *while* the workers post. Latest run
(16 workers x 500 ops, 4 publishers): 14 of 14 checks passed, including every
transaction having exactly one event, each event published exactly once across
publishers, and each event delivered to the consumer exactly once. Raw output is
in `docs/stress-results/`. The event contract is in `docs/events/transaction.posted.v1.md`.

Fault injection: removing `FOR UPDATE SKIP LOCKED` made 4 publishers report 20,115
publishes for 8,103 events. The consumer inbox still guaranteed exactly-once
delivery, so correctness held while the duplicate work was caught by the stress check.

Fault injection: removing the account-ordering rule in the monitoring consumer produced 
advisory-lock deadlocks. This exposed a publisher bug where a deadlock crashed the publisher, 
which is now fixed.

### Testing approach

- Invariants were written as tests before the posting logic.
- Schema-level tests prove the database itself rejects unbalanced entries,
  mutations, non-positive amounts and currency mismatches.
- A real-commit test (without a wrapping transaction) proves the deferred
  balance trigger fires at COMMIT.
- Guards were deliberately removed to confirm the matching test fails.
- A defect in the stress workload itself (account pairs were never shuffled, so
  money only flowed one way) was caught by inspecting final balances and fixed.
  The ledger checks alone did not reveal it, which is why workload realism is
  checked too.

## Running it

Requirements: PHP 8.2+ with `pdo_pgsql`, Composer, PostgreSQL. Developed on
Windows with Laragon.

1. Create a role and two databases (the second is for tests, which must run on
   real PostgreSQL):

   ```sql
   CREATE ROLE ledger_app WITH LOGIN PASSWORD 'choose_a_strong_password';
   CREATE DATABASE ledger OWNER ledger_app;
   CREATE DATABASE ledger_testing OWNER ledger_app;
   ```

2. Install and configure:

   ```bash
   cd backend
   composer install
   copy .env.example .env
   php artisan key:generate
   ```

   Set these in `.env`:

   ```env
   DB_CONNECTION=pgsql
   DB_HOST=127.0.0.1
   DB_PORT=5432
   DB_DATABASE=ledger
   DB_USERNAME=ledger_app
   DB_PASSWORD=choose_a_strong_password
   ```

3. Migrate and test:

   ```bash
   php artisan migrate
   php artisan test --filter=Ledger
   ```

4. Run the proof:

   ```bash
   php artisan ledger:stress
   php artisan ledger:stress --workers=16 --ops=500 --customers=3
   php artisan ledger:reconcile
   ```

The stress test writes to the development database (`ledger`). Because the
ledger is append-only, its rows stay. `php artisan migrate:fresh` clears them.

## Known limitations

- Single currency only. FX is out of scope.
- The stress workload does not exercise the overdraft path under contention
  (balances are large relative to transfers). Overdraft is covered by unit tests.
- If a client retries across midnight without supplying an effective date, the
  request hash changes. The HTTP layer will require the date explicitly.
- No HTTP API yet. The concurrency guarantees live in the service and database,
  so an API will inherit them.
- Synthetic data only. No real payment rails, KYC data or customer information.
- Velocity counts depend on events being evaluated in order. Concurrent publishers 
  can deliver two events for the same account out of order, which can undercount a window. 
  Replay is sequential, so it is deterministic and can correct this. A proper fix, 
  per-account sequencing, is a good follow-up.

## Roadmap

1. **Outbox (Phase 2).** `transaction.posted` events written in the same database
   transaction as the posting, a publisher using `FOR UPDATE SKIP LOCKED`, a
   versioned event schema, and replay.
2. **Monitoring engine (Phase 3).** Configurable rules (velocity, large amount,
   structuring, rapid in-and-out, new beneficiary), rule versioning with
   maker-checker, alert explanations, and backtesting against history.
3. **Case management (Phase 4).** Alert queue, case lifecycle, analyst notes,
   optional account freeze through the ledger's public API, and role-based access.
4. **Reporting and packaging (Phase 5).** Dashboard, synthetic data generator with
   seeded fraud patterns, and a demo script.