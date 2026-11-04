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
            ['STRUCTURING-24H', 'structuring', 'Structuring below the reporting threshold',
             'Splitting amounts to stay just under a reporting or review limit is a classic way to avoid scrutiny, so repeated near-threshold transactions deserve a look.',
             ['threshold_sen' => 2_500_000, 'band_percent' => 10, 'min_count' => 3, 'window_minutes' => 1440, 'directions' => ['D', 'C'], 'account_types' => ['liability']], 'high'],
            ['RAPID-IN-OUT-2H', 'rapid_in_out', 'Rapid in-and-out movement',
             'Funds that arrive and leave almost immediately can indicate pass-through (mule) accounts, layering, or an account takeover being drained.',
             ['min_amount_sen' => 1_000_000, 'out_percent' => 80, 'window_minutes' => 120, 'account_types' => ['liability']], 'high'],
            ['NEW-BENEFICIARY', 'new_beneficiary', 'High-value transfer to a new beneficiary',
             'A large payment to someone the customer has never paid before is a common pattern in authorised-push-payment scams and account takeover.',
             ['threshold_sen' => 1_000_000, 'account_types' => ['liability']], 'medium'],
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