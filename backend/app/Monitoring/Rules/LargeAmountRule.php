<?php

namespace App\Monitoring\Rules;

use App\Ledger\Events\LedgerEvent;
use App\Monitoring\Finding;
use App\Monitoring\RuleVersion;
use App\Monitoring\Support\Money;
use App\Monitoring\Support\Params;

/**
 * Parameters: threshold_sen (int), directions (["D","C"]), account_types (["liability"]).
 * Stateless: looks only at the event in front of it.
 */
final class LargeAmountRule implements Rule
{
    public function type(): string { return 'large_amount'; }

    public function validate(array $parameters): void
    {
        Params::positiveInt($parameters, 'threshold_sen');
        Params::validateFilters($parameters);
    }

    public function evaluate(LedgerEvent $event, RuleVersion $rule): array
    {
        $p = $rule->parameters;
        $threshold = Params::positiveInt($p, 'threshold_sen');
        $findings = [];

        foreach ($event->payload['entries'] as $i => $entry) {
            if (! Params::matches($entry, $p) || $entry['amount'] < $threshold) {
                continue;
            }

            $findings[] = new Finding(
                accountId: $entry['account_id'],
                accountNumber: $entry['account_number'],
                dedupeKey: "event:{$event->id}:entry:$i",
                explanation: sprintf(
                    '%s %s (%s) on %s meets or exceeds the limit of %s.',
                    Money::myr($entry['amount']),
                    $entry['direction'] === 'C' ? 'credit' : 'debit',
                    $event->payload['type'],
                    $entry['account_number'],
                    Money::myr($threshold),
                ),
                details: [
                    'rule' => $rule->code,
                    'rule_version' => $rule->version,
                    'amount_sen' => $entry['amount'],
                    'threshold_sen' => $threshold,
                    'direction' => $entry['direction'],
                    'transaction_type' => $event->payload['type'],
                    'evidence_transaction_ids' => [$event->aggregateId],
                ],
            );
        }

        return $findings;
    }
}