# Rule catalogue

Thresholds below are illustrative placeholders for the simulation. In a real
institution they come from the compliance policy and are calibrated against data.

## LARGE-AMOUNT (type: large_amount)

- **Business rationale:** Unusually large movements on customer accounts may
  indicate fraud, account takeover or money laundering, and are commonly subject
  to review or reporting obligations.
- **Parameters:** `threshold_sen`, `directions` (D, C), `account_types` (default: liability, i.e. customer accounts)
- **Default:** RM25,000, both directions, severity high
- **Logic:** Stateless. Alerts on every entry whose amount is at or above the threshold.
- **Expected false positives:** Legitimate property or vehicle payments, business
  customers, salary lump sums, and a customer moving their own savings.
- **Tuning levers:** Raise the threshold per customer segment; restrict to one
  direction; add a customer-profile exemption (a future version).
- **Alert explanation:** "RM 25,000.00 credit (deposit) on C-ALICE meets or exceeds the limit of RM 25,000.00."

## VELOCITY-10M (type: velocity)

- **Business rationale:** Many transactions in a short window can indicate
  automated fraud, a compromised account, or structuring to stay under limits.
- **Parameters:** `max_count`, `window_minutes`, `directions`, `account_types`
- **Default:** more than 10 transactions in 10 minutes, severity medium
- **Logic:** Counts transactions on the account in the window ending at the event
  (event time, not wall-clock time). Alerts when the count exceeds the maximum,
  then stays quiet for the rest of that window.
- **Expected false positives:** Payroll or batch payouts, merchant accounts, and
  scheduled sweeps.
- **Known limitation:** Counts assume events are evaluated in order. Concurrent
  publishers can deliver out of order and cause an undercount. Sequential replay
  is deterministic.
- **Alert explanation:** "C-ALICE had 11 transactions within 10 minute(s), above the limit of 10."