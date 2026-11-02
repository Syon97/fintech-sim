<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MonitoringGroups extends Command
{
    protected $signature = 'monitoring:groups {--limit=10}';
    protected $description = 'Show the highest-risk alert groups (case candidates)';

    public function handle(): int
    {
        $rows = DB::select(
            "SELECT g.id, g.account_number, g.alert_count, g.max_score,
                    count(*) FILTER (WHERE a.status <> 'closed') AS unresolved,
                    g.first_alert_at, g.last_alert_at
               FROM monitoring_alert_groups g
               LEFT JOIN monitoring_alerts a ON a.group_id = g.id
              GROUP BY g.id
              ORDER BY g.max_score DESC, g.last_alert_at DESC
              LIMIT ?",
            [(int) $this->option('limit')],
        );

        $this->table(
            ['group', 'account', 'alerts', 'unresolved', 'max score', 'first', 'last'],
            array_map(fn ($r) => [$r->id, $r->account_number, $r->alert_count, $r->unresolved, $r->max_score, $r->first_alert_at, $r->last_alert_at], $rows),
        );

        return self::SUCCESS;
    }
}