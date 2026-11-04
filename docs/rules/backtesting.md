# Backtesting a proposed rule

Before a checker approves a rule change, they can see what it would have done on history.

## How it works
- The proposed parameters run through the production rule code, in event order, over the
  events monitoring has already processed.
- It all happens inside a transaction that is always rolled back. Nothing is written to
  live alerts, scores, groups, rules or counterparties.
- The result is stored against the change request and is immutable.

## What the result shows
Events scanned, alerts it would raise, distinct accounts, the busiest accounts, example
explanations, and overlap with the currently active version.

## Limitations
- Counts show volume, not correctness. Without labelled outcomes there is no precision figure.
- Only history monitoring processed is covered.
- Approval does not require a backtest yet. That is a policy decision.
- One long transaction: very large histories need batching by range (--from-id, --to-id).