<?php

namespace App\Monitoring\Rules;

use App\Ledger\Events\LedgerEvent;
use App\Monitoring\Finding;
use App\Monitoring\RuleVersion;
use App\Monitoring\Support\Params;
use Illuminate\Support\Facades\DB;

/**
 * Parameters: max_count (int), window_minutes (int), directions, account_types.
 * Alerts when an account has MORE than max_count transactions inside the window
 * ending at this event, then stays quiet for the rest of that window.
 */
final class VelocityRule implements Rule
{
    public function type(): string { return 'velocity'; }

    public function validate(array $parameters): void
    {
        Params::positiveInt($parameters, 'max_count');
        Params::positiveInt($parameters, 'window_minutes');
        Params::validateFilters($parameters);
    }

    public function evaluate(LedgerEvent $event, RuleVersion $rule): array
    {
        $p = $rule->parameters;
        $max = Params::positiveInt($p, 'max_count');
        $window = Params::positiveInt($p, 'window_minutes');
        $dirs = Params::directions($p);
        $types = Params::accountTypes($p);

        $accounts = [];
        foreach ($event->payload['entries'] as $entry) {
            if (Params::matches($entry, $p)) {
                $accounts[$entry['account_id']] = $entry['account_number'];
            }
        }
        ksort($accounts);

        $dirIn = implode(',', array_fill(0, count($dirs), '?'));
        $typeIn = implode(',', array_fill(0, count($types), '?'));
        $findings = [];

        foreach ($accounts as $accountId => $number) {
            // Window ending AT this event, position = (occurred_at, event id). Using the
            // event's own time, not the clock, keeps replays deterministic.
            $rows = DB::select(
                "SELECT DISTINCT event_id, transaction_id
                   FROM monitoring_activity
                  WHERE account_id = ?
                    AND direction IN ($dirIn)
                    AND account_type IN ($typeIn)
                    AND occurred_at > ?::timestamptz - make_interval(mins => ?::int)
                    AND (occurred_at, event_id) <= (?::timestamptz, ?::bigint)
                  ORDER BY event_id",
                [$accountId, ...$dirs, ...$types, $event->occurredAt, $window, $event->occurredAt, $event->id],
            );

            $count = count($rows);
            if ($count <= $max) {
                continue;
            }

            // Already alerted for this account inside the window? Then stay quiet.
            $recent = DB::selectOne(
                'SELECT 1 AS hit
                   FROM monitoring_alerts
                  WHERE rule_version_id = ? AND account_id = ?
                    AND event_occurred_at > ?::timestamptz - make_interval(mins => ?::int)
                    AND (event_occurred_at, event_id) < (?::timestamptz, ?::bigint)
                  LIMIT 1',
                [$rule->id, $accountId, $event->occurredAt, $window, $event->occurredAt, $event->id],
            );
            if ($recent !== null) {
                continue;
            }

            $findings[] = new Finding(
                accountId: $accountId,
                accountNumber: $number,
                dedupeKey: "event:{$event->id}:account:$accountId",
                explanation: sprintf(
                    '%s had %d transactions within %d minute(s), above the limit of %d.',
                    $number, $count, $window, $max,
                ),
                details: [
                    'rule' => $rule->code,
                    'rule_version' => $rule->version,
                    'observed_count' => $count,
                    'max_count' => $max,
                    'window_minutes' => $window,
                    'evidence_transaction_ids' => array_slice(array_column($rows, 'transaction_id'), -50),
                ],
            );
        }

        return $findings;
    }
}