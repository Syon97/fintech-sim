# Alert API

All routes are under /api and need a logged-in session. Actions always run as the logged-in user.

| Route | Permission | Purpose |
|---|---|---|
| GET /alerts | alerts.view | The queue, highest score first. Filters: status (unresolved by default), assigned (me, unassigned), min_score, account, group, page, per_page |
| GET /alerts/{id} | alerts.view | One alert: explanation, rule rationale, score as sentences, evidence, group, timeline, and `can` |
| GET /groups | alerts.view | Case candidates with unresolved counts. state=all includes finished ones |
| POST /alerts/{id}/start | alerts.work | Start working an open alert |
| POST /alerts/{id}/release | alerts.work | Put it back (assignee only). Optional note |
| POST /alerts/{id}/close | alerts.work | Close with disposition and a note (assignee only). Final |
| POST /alerts/{id}/reassign | alerts.reassign | Hand an in-progress alert to someone else |
| GET /assignees | alerts.reassign | Active people who can take an alert |

Each action returns the alert as it now stands.

## Errors
Every failure has a `reason` (stable, for code) and a `message` (for people).
404 ALERT_NOT_FOUND. 409 ILLEGAL_TRANSITION (it moved on). 403 NOT_ASSIGNEE.
422 INVALID_INPUT or INVALID_ASSIGNEE, or field-level validation errors.

## `can`
start, release, close and reassign tell the screen what this user may do right now.
They are hints. The server enforces everything again.