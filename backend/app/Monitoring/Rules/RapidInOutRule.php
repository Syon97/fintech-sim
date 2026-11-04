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
 * Parameters: min_amount_sen, out_percent (1-100), window_minutes, account_types.
 * Evaluated when an outflow (debit) happens. Alerts if, inside the window ending at this event,
 * credits total at least min_amount_sen and the debits made AFTER the first credit total at
 * least out_percent of those credits. Then stays quiet for the rest of the window.
 */
final class RapidInOutRule implements Rule
{
    public function type(): string { return 'rapid_in_out'; }

    public function validate(array $parameters): void
    {
        Params::positiveInt($parameters, 'min_amount_sen');
        Params::intBetween($parameters, 'out_percent', 1, 100);
        Params::positiveInt($parameters, 'window_minutes');
        Params::validateAccountTypes($parameters);
    }

    public function evaluate(LedgerEvent $event, RuleVersion $rule): array
    {
        $p = $rule->parameters;
        $minAmount = Params::positiveInt($p, 'min_amount_sen');
        $outPct = Params::intBetween($p, 'out_percent', 1, 100);
        $window = Params::positiveInt($p, 'window_minutes');
        $types = Params::accountTypes($p);

        $accounts = [];
        foreach ($event->payload['entries'] as $entry) {
            if ($entry['direction'] === 'D' && in_array($entry['account_type'], $types, true)) {
                $accounts[$entry['account_id']] = $entry['account_number'];
            }
        }
        ksort($accounts);

        $typeIn = implode(',', array_fill(0, count($types), '?'));
        $findings = [];

        foreach ($accounts as $accountId => $number) {
            $sums = DB::selectOne(
                "WITH w AS (
                     SELECT event_id, occurred_at, direction, amount
                       FROM monitoring_activity
                      WHERE account_id = ?
                        AND account_type IN ($typeIn)
                        AND occurred_at > ?::timestamptz - make_interval(mins => ?::int)
                        AND (occurred_at, event_id) <= (?::timestamptz, ?::bigint)
                 ), fc AS (
                     SELECT occurred_at, event_id FROM w WHERE direction = 'C' ORDER BY occurred_at, event_id LIMIT 1
                 )
                 SELECT COALESCE(SUM(w.amount) FILTER (WHERE w.direction = 'C'), 0) AS credits,
                        COALESCE(SUM(w.amount) FILTER (WHERE w.direction = 'D' AND fc.event_id IS NOT NULL
                                 AND (w.occurred_at, w.event_id) > (fc.occurred_at, fc.event_id)), 0) AS debits
                   FROM w LEFT JOIN fc ON true",
                [$accountId, ...$types, $event->occurredAt, $window, $event->occurredAt, $event->id],
            );

            $credits = (int) $sums->credits;
            $debits = (int) $sums->debits;

            if ($credits < $minAmount || $debits * 100 < $credits * $outPct
                || Suppression::alertedRecently($rule, $accountId, $event, $window)) {
                continue;
            }

            $evidence = DB::select(
                "SELECT DISTINCT event_id, transaction_id
                   FROM monitoring_activity
                  WHERE account_id = ? AND account_type IN ($typeIn)
                    AND occurred_at > ?::timestamptz - make_interval(mins => ?::int)
                    AND (occurred_at, event_id) <= (?::timestamptz, ?::bigint)
                  ORDER BY event_id",
                [$accountId, ...$types, $event->occurredAt, $window, $event->occurredAt, $event->id],
            );

            $findings[] = new Finding(
                accountId: $accountId,
                accountNumber: $number,
                dedupeKey: "event:{$event->id}:account:$accountId",
                explanation: sprintf(
                    '%s received %s and moved out %s (%d%%) within %d minute(s).',
                    $number, Money::myr($credits), Money::myr($debits), intdiv($debits * 100, $credits), $window,
                ),
                details: [
                    'rule' => $rule->code,
                    'rule_version' => $rule->version,
                    'credited_sen' => $credits,
                    'moved_out_sen' => $debits,
                    'out_percent' => $outPct,
                    'min_amount_sen' => $minAmount,
                    'window_minutes' => $window,
                    'evidence_transaction_ids' => array_slice(array_column($evidence, 'transaction_id'), -50),
                ],
            );
        }

        return $findings;
    }
}