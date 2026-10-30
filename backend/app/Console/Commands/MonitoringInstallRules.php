<?php

namespace App\Console\Commands;

use App\Monitoring\RuleAdmin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MonitoringInstallRules extends Command
{
    protected $signature = 'monitoring:install-rules';
    protected $description = 'Install the default monitoring rules (skips rules that already exist)';

    public function handle(RuleAdmin $admin): int
    {
        // Thresholds are illustrative placeholders for the simulation.
        
        $defaults = [
            ['LARGE-AMOUNT', 'large_amount', 'Large single transaction',
             'Unusually large movements on customer accounts warrant review, because they may indicate fraud, account takeover or money laundering.',
             ['threshold_sen' => 2_500_000, 'directions' => ['D', 'C'], 'account_types' => ['liability']], 'high'],
            ['VELOCITY-10M', 'velocity', 'High transaction velocity',
             'Many transactions in a short window can indicate automated fraud, a compromised account or structuring.',
             ['max_count' => 10, 'window_minutes' => 10, 'directions' => ['D', 'C'], 'account_types' => ['liability']], 'medium'],
        ];

        foreach ($defaults as [$code, $type, $name, $rationale, $params, $severity]) {
            if (DB::table('monitoring_rules')->where('code', $code)->exists()) {
                $this->line("  [skip] $code already exists");
                continue;
            }
            $admin->define($code, $type, $name, $rationale, $params, $severity);
            $this->info("  [ok]   installed $code");
        }

        return self::SUCCESS;
    }
}