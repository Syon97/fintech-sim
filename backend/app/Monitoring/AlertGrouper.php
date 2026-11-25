<?php

namespace App\Monitoring;

use Illuminate\Support\Facades\DB;

/**
 * Groups an account's alerts into case candidates. Callers hold the per-account
 * advisory lock, so find-or-create cannot race. A group whose case is closed is sealed:
 * later alerts start a new group.
 */
final class AlertGrouper
{
    private const GAP_HOURS = 24;

    public function assign(int $accountId, string $accountNumber, string $occurredAt, int $score): int
    {
        $group = DB::selectOne(
            "SELECT g.id FROM monitoring_alert_groups g
              WHERE g.account_id = ?
                AND g.last_alert_at >= ?::timestamptz - make_interval(hours => ?::int)
                AND g.first_alert_at <= ?::timestamptz + make_interval(hours => ?::int)
                AND NOT EXISTS (SELECT 1 FROM monitoring_cases c WHERE c.group_id = g.id AND c.status = 'closed')
              ORDER BY g.last_alert_at DESC
              LIMIT 1",
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