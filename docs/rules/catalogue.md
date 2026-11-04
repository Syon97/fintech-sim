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

## STRUCTURING-24H (type: structuring)

- **Business rationale:** Splitting amounts to stay just under a reporting or review
  limit is a classic way to avoid scrutiny. One near-threshold amount is normal;
  several in a day is a pattern.
- **Parameters:** `threshold_sen`, `band_percent` (1-50), `min_count` (2+), `window_minutes`, `directions`, `account_types`
- **Default:** RM25,000 threshold (illustrative, not a statement of any regulatory
  limit), band 10% (RM22,500 to RM24,999.99), at least 3 in 24 hours, severity high
- **Logic:** Counts transactions in the band within the window ending at the event.
  Alerts at the count, then stays quiet for the rest of that window.
- **Expected false positives:** Regular payments that happen to sit near the limit
  (rent, instalments, supplier invoices at a fixed price).
- **Tuning levers:** Narrow the band, raise `min_count`, shorten the window, exempt
  recurring payees (a future version).
- **Alert explanation:** "C-ALICE had 3 transactions between RM 22,500.00 and RM 24,999.99 within 1440 minute(s), just below the RM 25,000.00 threshold."

## RAPID-IN-OUT-2H (type: rapid_in_out)

- **Business rationale:** Funds that arrive and leave almost immediately can indicate
  pass-through (mule) accounts, layering, or an account takeover being drained.
- **Parameters:** `min_amount_sen`, `out_percent` (1-100), `window_minutes`, `account_types`
- **Default:** at least RM10,000 credited, at least 80% moved out within 2 hours, severity high
- **Logic:** Evaluated when money leaves. Only outflows made after the first credit in
  the window count, because money that left before the credit was not "passed through".
- **Expected false positives:** Customers paying a bill right after salary, property
  completions, a customer moving money between their own banks.
- **Tuning levers:** Raise the minimum amount or percentage, lengthen or shorten the
  window, exempt payroll credits (a future version).
- **Alert explanation:** "C-ALICE received RM 200.00 and moved out RM 170.00 (85%) within 60 minute(s)."

## NEW-BENEFICIARY (type: new_beneficiary)

- **Business rationale:** A large payment to someone the customer has never paid is
  a common pattern in authorised-push-payment scams and account takeover.
- **Parameters:** `threshold_sen`, `account_types` (sender)
- **Default:** RM10,000, severity medium
- **Logic:** Applies to transfers only. Alerts when the beneficiary has no earlier
  first-seen record for this sender. The relationship is recorded after evaluation,
  so the first payment is the one that alerts.
- **Expected false positives:** Legitimate first payments such as a deposit on a
  property or car, a new business supplier, or a family member.
- **Tuning levers:** Raise the threshold, add a cooling-off or confirmation step (a
  product control, not a monitoring one), exempt beneficiaries verified by the customer.
- **Known limitation:** If events for one account arrive out of order, the alert may
  fire on the later of two payments. The relationship history still ends up correct,
  and sequential replay is deterministic.
- **Alert explanation:** "C-ALICE sent RM 200.00 to C-BOB, a beneficiary not paid before, at or above the limit of RM 100.00."