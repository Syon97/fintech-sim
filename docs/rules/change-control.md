# Rule change control (maker-checker)

Defining or retiring a monitoring rule changes what the institution detects, so no one
can do it alone.

## Process

1. **Maker proposes**: a new rule, a new version of a rule, or retiring a rule, with a written reason.
   Parameters are validated at proposal time. Nothing is live yet.
2. **Checker decides**: a different person approves or rejects. Rejection requires a note.
3. **Approval activates**: the rule version is created and goes live in the same database
   transaction that records the approval. From then on it evaluates new events only.
4. **Withdrawal**: the maker can withdraw a pending request.

## Controls

| Control | Enforced by |
|---|---|
| Maker and checker must differ | Service (SELF_APPROVAL) plus `CHECK (checker <> maker)` |
| One pending change per rule | Service check plus partial unique index |
| Approved means live, and live means approved | One database transaction |
| Concurrent approvals: exactly one wins | Row lock; losers get ALREADY_DECIDED |
| Proposal cannot be altered after submission | Trigger |
| Decided requests are final; history is never deleted | Trigger |
| A rule's type cannot change once created | Service, checked at proposal and at approval |

## Traceability

Alert -> rule version -> approved change request -> who proposed it, who approved it,
the reason, the decision note, and the time.

## Known limitations

- Identities are plain strings until authentication and roles arrive with the case UI,
  so four-eyes is only as strong as the identity behind it.
- A checker cannot yet see a rule's impact before approving. Backtesting (next slice) adds that.
- The low-level `RuleAdmin::define()` remains for seeding and tests. A test restricts
  which application code may call it.
- Emergency changes, expiry of stale pending requests and approval levels by severity are out of scope.