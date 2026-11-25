# Case management

An alert group is a *case candidate*. A supervisor turns it into a case when it deserves a proper investigation.

## Process
1. **Open**: a supervisor opens a case from a group. One case per group. The opener owns it.
2. **Work**: any supervisor can add notes or assign the case to another supervisor.
3. **Act**: the owner can freeze the account (with a reason) and lift the freeze later.
4. **Close**: the owner closes it with an outcome and a written note.

Outcomes: *no further action* or *escalated to compliance*.

## Rules
| Rule | Enforced by |
|---|---|
| Every alert in the case must be decided before the case closes | Service, plus a database trigger |
| Only the owner closes, freezes or unfreezes | Service; closing is also a CHECK constraint |
| A case with a confirmed-suspicious alert cannot close as no further action | Service, plus a database trigger |
| A closing note is required | Service, plus a CHECK constraint |
| A closed case is final, and its group is sealed (later alerts start a new group) | Trigger; grouping rule |
| A case's history can never be edited or deleted | Append-only trigger |

## Freezing an account
- Needs a written reason and goes through the ledger's own service.
- Writes an audit row (who, why, when), an `account.frozen` event, and the case timeline entry in one transaction.
- Takes the same row locks as a posting, so a freeze and a posting on one account never interleave.
- The database refuses to commit any status change that has no audit row from the same transaction.

## Limitations
- A freeze is one person's decision. A real bank may need a second approver.
- Analysts cannot see cases yet; they see the group on the alert.
- Whether a freeze under heavy parallel posting load holds is not yet proven by a parallel test.