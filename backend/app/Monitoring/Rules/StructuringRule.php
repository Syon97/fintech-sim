<?php

namespace App\Monitoring\Rules;

use App\Ledger\Events\LedgerEvent;
use App\Monitoring\Finding;
use App\Monitoring\RuleVersion;
use App\Monitoring\Support\Money;
use App\Monitoring\Support\Params;
use App\Monitoring\Support\Suppression;
use Illuminate\Support\Facades\DB;

/**
 * Parameters: threshold_sen, band_percent (1-50), min_count (2+), window_minutes, directions, account_types.
 * "Just below" = from threshold minus band_percent up to, but excluding, the threshold.
 * Alerts when an account has at least min_count in-band transactions in the window ending at
 * this event, then stays quiet for the rest of that window.
 */
final class StructuringRule implements Rule
{
    public function type(): string { return 'structuring'; }

    public function validate(array $parameters): void
    {
        Params::positiveInt($parameters, 'threshold_sen');
        Params::intBetween($parameters, 'band_percent', 1, 50);
        Params::intBetween($parameters, 'min_count', 2, 1000);
        Params::positiveInt($parameters, 'window_minutes');
        Params::validateFilters($parameters);
    }

    public function evaluate(LedgerEvent $event, RuleVersion $rule): array
    {
        $p = $rule->parameters;
        $threshold = Params::positiveInt($p, 'threshold_sen');
        $band = Params::intBetween($p, 'band_percent', 1, 50);
        $min = Params::intBetween($p, 'min_count', 2, 1000);
        $window = Params::positiveInt($p, 'window_minutes');
        $lower = intdiv($threshold * (100 - $band), 100);
        $dirs = Params::directions($p);
        $types = Params::accountTypes($p);

        $accounts = [];
        foreach ($event->payload['entries'] as $entry) {
            if (Params::matches($entry, $p) && $entry['amount'] >= $lower && $entry['amount'] < $threshold) {
                $accounts[$entry['account_id']] = $entry['account_number'];
            }
        }
        ksort($accounts);

        $dirIn = implode(',', array_fill(0, count($dirs), '?'));
        $typeIn = implode(',', array_fill(0, count($types), '?'));
        $findings = [];

        foreach ($accounts as $accountId => $number) {
            $rows = DB::select(
                "SELECT DISTINCT event_id, transaction_id
                   FROM monitoring_activity
                  WHERE account_id = ?
                    AND direction IN ($dirIn)
                    AND account_type IN ($typeIn)
                    AND amount >= ? AND amount < ?
                    AND occurred_at > ?::timestamptz - make_interval(mins => ?::int)
                    AND (occurred_at, event_id) <= (?::timestamptz, ?::bigint)
                  ORDER BY event_id",
                [$accountId, ...$dirs, ...$types, $lower, $threshold, $event->occurredAt, $window, $event->occurredAt, $event->id],
            );

            $count = count($rows);
            if ($count < $min || Suppression::alertedRecently($rule, $accountId, $event, $window)) {
                continue;
            }

            $findings[] = new Finding(
                accountId: $accountId,
                accountNumber: $number,
                dedupeKey: "event:{$event->id}:account:$accountId",
                explanation: sprintf(
                    '%s had %d transactions between %s and %s within %d minute(s), just below the %s threshold.',
                    $number, $count, Money::myr($lower), Money::myr($threshold - 1), $window, Money::myr($threshold),
                ),
                details: [
                    'rule' => $rule->code,
                    'rule_version' => $rule->version,
                    'observed_count' => $count,
                    'min_count' => $min,
                    'threshold_sen' => $threshold,
                    'band_lower_sen' => $lower,
                    'window_minutes' => $window,
                    'evidence_transaction_ids' => array_slice(array_column($rows, 'transaction_id'), -50),
                ],
            );
        }

        return $findings;
    }
}