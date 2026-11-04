<?php

namespace App\Console\Commands;

use App\Monitoring\Backtester;
use Illuminate\Console\Command;

class MonitoringBacktest extends Command
{
    protected $signature = 'monitoring:backtest {request : change request id} {--from-id=} {--to-id=} {--actor=analyst}';
    protected $description = 'Replay history through a proposed rule and record how many alerts it would have raised';

    public function handle(Backtester $bt): int
    {
        $r = $bt->runForRequest(
            (int) $this->argument('request'),
            (string) $this->option('actor'),
            $this->option('from-id') !== null ? (int) $this->option('from-id') : null,
            $this->option('to-id') !== null ? (int) $this->option('to-id') : null,
        );

        $this->table(['metric', 'value'], [
            ['events scanned', $r['events_scanned']],
            ['event range', ($r['from_event_id'] ?? '-') . ' to ' . ($r['to_event_id'] ?? '-')],
            ['alerts it would raise', $r['alerts']],
            ['distinct accounts', $r['distinct_accounts']],
            ['live version alerts in range', $r['live_alerts']],
            ['overlap with live', $r['overlap_with_live']],
        ]);
        foreach ($r['top_accounts'] as $a) {
            $this->line("  top: {$a['account']} ({$a['alerts']})");
        }
        foreach ($r['sample_explanations'] as $e) {
            $this->line("  e.g. $e");
        }
        $this->info('Recorded. Nothing was changed.');

        return self::SUCCESS;
    }
}