<?php

namespace App\Monitoring\Rules;

use App\Ledger\Events\LedgerEvent;
use App\Monitoring\CounterpartyStore;
use App\Monitoring\Finding;
use App\Monitoring\RuleVersion;
use App\Monitoring\Support\Money;
use App\Monitoring\Support\Params;
use Illuminate\Support\Facades\DB;

/**
 * Parameters: threshold_sen, account_types (sender).
 * Alerts when a transfer at or above the threshold goes to a beneficiary this sender has never
 * paid before. "Never before" means no relationship with a first-seen position earlier than this event.
 */
final class NewBeneficiaryRule implements Rule
{
    public function type(): string { return 'new_beneficiary'; }

    public function validate(array $parameters): void
    {
        Params::positiveInt($parameters, 'threshold_sen');
        Params::validateAccountTypes($parameters);
    }

    public function evaluate(LedgerEvent $event, RuleVersion $rule): array
    {
        $pair = CounterpartyStore::pair($event);
        if ($pair === null) {
            return [];
        }

        $p = $rule->parameters;
        $threshold = Params::positiveInt($p, 'threshold_sen');
        $from = $pair['from'];
        $to = $pair['to'];

        if (! in_array($from['account_type'], Params::accountTypes($p), true) || $from['amount'] < $threshold) {
            return [];
        }

        $known = DB::selectOne(
            'SELECT 1 AS hit
               FROM monitoring_counterparties
              WHERE account_id = ? AND counterparty_account_id = ?
                AND (first_seen_at, first_seen_event_id) < (?::timestamptz, ?::bigint)
              LIMIT 1',
            [$from['account_id'], $to['account_id'], $event->occurredAt, $event->id],
        );
        if ($known !== null) {
            return [];
        }

        return [new Finding(
            accountId: $from['account_id'],
            accountNumber: $from['account_number'],
            dedupeKey: "event:{$event->id}:account:{$from['account_id']}",
            explanation: sprintf(
                '%s sent %s to %s, a beneficiary not paid before, at or above the limit of %s.',
                $from['account_number'], Money::myr($from['amount']), $to['account_number'], Money::myr($threshold),
            ),
            details: [
                'rule' => $rule->code,
                'rule_version' => $rule->version,
                'amount_sen' => $from['amount'],
                'threshold_sen' => $threshold,
                'beneficiary_account_id' => $to['account_id'],
                'beneficiary_account_number' => $to['account_number'],
                'evidence_transaction_ids' => [$event->aggregateId],
            ],
        )];
    }
}