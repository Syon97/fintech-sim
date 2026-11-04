# Roles and access

| Role | Can | Cannot |
|---|---|---|
| analyst | View and work alerts (start, release, close) | Reassign, manage cases, change rules |
| supervisor | Everything an analyst can, plus reassign alerts, manage cases, view rules | Change rules |
| rule_admin | View rules, propose, decide and backtest rule changes | Work alerts or manage cases |

Whoever changes the detection rules never works the alerts they would affect. Four-eyes
still applies between two different rule_admin users.

## Identity
- The username is the identity. It is lowercase, 3 to 64 characters, and never changes.
- Users are deactivated, never deleted, so history always resolves to a person.
- Deactivation takes effect on the very next request.

## Login
- Cookie session (Sanctum SPA mode). No credential is readable by page scripts.
- The same answer for a wrong password, an unknown user and a deactivated account.
- 5 attempts a minute per username and address; every login, failed login and logout is recorded in an append-only audit table.

## Limitations
No multi-factor authentication, no password reset, no password policy beyond length, one role per user.