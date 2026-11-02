<?php

namespace App\Monitoring;

use Illuminate\Support\Facades\DB;

/**
 * Groups an account's alerts into case candidates. Callers hold the per-account
 * advisory lock, so find-or-create cannot race.
 */
final class AlertGrouper
{
    private const GAP_HOURS = 24;

    public function assign(int $accountId, string $accountNumber, string $occurredAt, int $score): int
    {
        $group = DB::selectOne(
            'SELECT id FROM monitoring_alert_groups
              WHERE account_id = ?
                AND last_alert_at >= ?::timestamptz - make_interval(hours => ?::int)
                AND first_alert_at <= ?::timestamptz + make_interval(hours => ?::int)
              ORDER BY last_alert_at DESC
              LIMIT 1',
            [$accountId, $occurredAt, self::GAP_HOURS, $occurredAt, self::GAP_HOURS],
        );

        if ($group === null) {
            return (int) DB::table('monitoring_alert_groups')->insertGetId([
                'account_id' => $accountId,
                'account_number' => $accountNumber,
                'first_alert_at' => $occurredAt,
                'last_alert_at' => $occurredAt,
                'alert_count' => 1,
                'max_score' => $score,
            ]);
        }

        DB::update(
            'UPDATE monitoring_alert_groups
                SET alert_count = alert_count + 1,
                    max_score = GREATEST(max_score, ?),
                    first_alert_at = LEAST(first_alert_at, ?::timestamptz),
                    last_alert_at = GREATEST(last_alert_at, ?::timestamptz)
              WHERE id = ?',
            [$score, $occurredAt, $occurredAt, $group->id],
        );

        return (int) $group->id;
    }
}