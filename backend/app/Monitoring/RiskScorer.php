<?php

namespace App\Monitoring;

use App\Ledger\Events\LedgerEvent;
use Illuminate\Support\Facades\DB;

/** Illustrative scoring model. Real weights come from the institution's risk policy. */
final class RiskScorer
{
    private const BASE = ['low' => 20, 'medium' => 40, 'high' => 60, 'critical' => 80];
    private const LOOKBACK_HOURS = 24;
    private const REPEAT_POINTS = 5;
    private const REPEAT_CAP = 15;
    private const OTHER_RULE_POINTS = 15;

    /** @return array{score:int, breakdown:array} */
    public function score(RuleVersion $version, LedgerEvent $event, Finding $finding): array
    {
        // Earlier alerts on this account, positioned strictly before this event.
        // Event time (never the clock) keeps replays deterministic.
        $row = DB::selectOne(
            'SELECT count(*) AS prior, count(*) FILTER (WHERE r.code <> ?) AS other_rule
               FROM monitoring_alerts a
               JOIN monitoring_rule_versions v ON v.id = a.rule_version_id
               JOIN monitoring_rules r ON r.id = v.rule_id
              WHERE a.account_id = ?
                AND a.event_occurred_at > ?::timestamptz - make_interval(hours => ?::int)
                AND (a.event_occurred_at, a.event_id) < (?::timestamptz, ?::bigint)',
            [$version->code, $finding->accountId, $event->occurredAt, self::LOOKBACK_HOURS, $event->occurredAt, $event->id],
        );

        $components = [['factor' => 'severity', 'points' => self::BASE[$version->severity]]];

        $repeat = min(self::REPEAT_CAP, (int) $row->prior * self::REPEAT_POINTS);
        if ($repeat > 0) {
            $components[] = ['factor' => 'repeat_alerts', 'points' => $repeat];
        }
        if ((int) $row->other_rule > 0) {
            $components[] = ['factor' => 'other_rule_alerts', 'points' => self::OTHER_RULE_POINTS];
        }

        $score = min(100, (int) array_sum(array_column($components, 'points')));

        return [
            'score' => $score,
            'breakdown' => [
                'components' => $components,
                'lookback_hours' => self::LOOKBACK_HOURS,
                'prior_alerts' => (int) $row->prior,
                'total' => $score,
            ],
        ];
    }
}