# Alert lifecycle, risk scoring and grouping

All numbers are illustrative placeholders for the simulation. In a real institution
they come from the risk policy and are calibrated against historical outcomes.

## Lifecycle

open -> investigating -> closed        (investigating -> open is allowed: release)

- Closed is final. Reopening would hide history, so a new alert or case is raised.
- Closing requires a disposition (false_positive, confirmed_suspicious) and a written note.
- Only the assignee can release or close. (Roles and supervisor override arrive with the case UI.)
- Every transition is recorded in an append-only audit trail (who, when, from, to, note).
- The alert's facts (explanation, evidence, score, rule version) can never change.

## Risk score (0-100)

| Factor | Points | Rationale |
|---|---|---|
| Severity (low/medium/high/critical) | 20/40/60/80 | The rule owner's view of the harm if true |
| Each earlier alert on the account in 24h | +5, max +15 | Repeated behaviour is more suspicious than a one-off |
| Earlier alert from a different rule in 24h | +15 | Independent signals corroborate each other |

The score and its breakdown are stored on the alert, so an analyst or auditor can see
exactly why it is 65 and not 60.

## Grouping (case candidates)

Alerts on the same account join one group while each new alert falls within 24 hours
of the group's activity. A longer gap starts a new group. A group shows the alert count,
the maximum score and how many alerts are unresolved.

## Known limitations

- Scores and groups depend on evaluation order. Concurrent publishers can deliver one
  account's events slightly out of order. Sequential replay is deterministic.
- Grouping is per account. Linking related accounts (shared beneficiary, device) is out of scope.
- The weights are not calibrated. A real model would be tuned on labelled outcomes.