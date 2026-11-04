<?php

namespace App\Monitoring\Support;

use App\Ledger\Events\LedgerEvent;
use App\Monitoring\RuleVersion;
use Illuminate\Support\Facades\DB;

final class Suppression
{
    /** Has this rule version already alerted on this account earlier inside the window? */
    public static function alertedRecently(RuleVersion $rule, int $accountId, LedgerEvent $event, int $windowMinutes): bool
    {
        return DB::selectOne(
            'SELECT 1 AS hit
               FROM monitoring_alerts
              WHERE rule_version_id = ? AND account_id = ?
                AND event_occurred_at > ?::timestamptz - make_interval(mins => ?::int)
                AND (event_occurred_at, event_id) < (?::timestamptz, ?::bigint)
              LIMIT 1',
            [$rule->id, $accountId, $event->occurredAt, $windowMinutes, $event->occurredAt, $event->id],
        ) !== null;
    }
}