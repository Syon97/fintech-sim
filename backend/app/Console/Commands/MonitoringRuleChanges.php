<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MonitoringRuleChanges extends Command
{
    protected $signature = 'monitoring:rule-changes {code?} {--limit=20}';
    protected $description = 'Show rule change requests: who proposed, who decided, and what went live';

    public function handle(): int
    {
        $q = DB::table('monitoring_rule_change_requests')->orderByDesc('id')->limit((int) $this->option('limit'));
        if ($this->argument('code')) {
            $q->where('rule_code', $this->argument('code'));
        }

        $this->table(
            ['id', 'rule', 'change', 'status', 'maker', 'checker', 'version', 'proposed', 'decided'],
            $q->get()->map(fn ($r) => [
                $r->id, $r->rule_code, $r->change_type, $r->status, $r->maker,
                $r->checker ?? '-', $r->version_id ?? '-', $r->created_at, $r->decided_at ?? '-',
            ])->all(),
        );

        return self::SUCCESS;
    }
}