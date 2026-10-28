<?php

namespace App\Console\Commands;

use App\Ledger\AccountService;
use App\Ledger\LedgerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class LedgerStress extends Command
{
    protected $signature = 'ledger:stress {--workers=8} {--ops=200} {--customers=4}';
    protected $description = 'Hammer the ledger from parallel processes, then prove nothing was lost or double-spent';

    public function handle(AccountService $accounts, LedgerService $ledger): int
    {
        $workers = (int) $this->option('workers');
        $ops = (int) $this->option('ops');
        $customers = (int) $this->option('customers');
        $run = 'stress' . now()->format('His') . Str::lower(Str::random(4));
        $targetCount = max(1, intdiv($ops, 10));

        // ---- Setup: few accounts on purpose, so workers collide constantly ----
        $cash = $accounts->open("$run-CASH", 'Stress cash', 'asset', 'MYR', true);
        $cust = [];
        for ($i = 1; $i <= $customers; $i++) {
            $id = $accounts->open("$run-CUST$i", "Stress customer $i", 'liability');
            $ledger->post('deposit', [
                ['account_id' => $cash, 'direction' => 'D', 'amount' => 1_000_000],
                ['account_id' => $id, 'direction' => 'C', 'amount' => 1_000_000],
            ], "$run-seed-$i");
            $cust[] = $id;
        }
        for ($t = 1; $t <= $targetCount; $t++) { // deposits that every worker will race to reverse
            $ledger->post('deposit', [
                ['account_id' => $cash, 'direction' => 'D', 'amount' => 100],
                ['account_id' => $cust[($t - 1) % count($cust)], 'direction' => 'C', 'amount' => 100],
            ], "$run-target-$t");
        }

        $this->info("Run $run: $workers workers x $ops ops, $customers customers, $targetCount reversal targets");

        // ---- Fire the workers at the same time ----
        $started = microtime(true);
        $procs = [];
        for ($w = 1; $w <= $workers; $w++) {
            $p = new Process([PHP_BINARY, base_path('artisan'), 'ledger:stress-worker', $run, (string) $w, "--ops=$ops"], base_path());
            $p->setTimeout(600);
            $p->start();
            $procs[$w] = $p;
        }
        foreach ($procs as $p) {
            $p->wait();
        }
        $elapsed = round(microtime(true) - $started, 1);

        // ---- Collect worker reports ----
        $sum = array_fill_keys(['posted', 'replays_ok', 'insufficient', 'reversed', 'already_reversed', 'reverse_insufficient'], 0);
        $unexpected = [];
        $crashed = 0;
        foreach ($procs as $w => $p) {
            if (! $p->isSuccessful() || ! preg_match('/^RESULT (.+)$/m', $p->getOutput(), $m)) {
                $crashed++;
                $this->error("Worker $w failed:\n" . $p->getErrorOutput() . $p->getOutput());
                continue;
            }
            $r = json_decode($m[1], true);
            foreach ($sum as $k => $_) {
                $sum[$k] += $r[$k];
            }
            $unexpected = array_merge($unexpected, $r['unexpected']);
        }

        $this->table(['metric', 'count'], collect($sum)->map(fn ($v, $k) => [$k, $v])->values()->all());
        $this->line("Elapsed: {$elapsed}s");

        // ---- Verify against the database ----
        $cashBal = (int) DB::table('account_balances')->where('account_id', $cash)->value('balance');
        $custBal = (int) DB::table('account_balances')->whereIn('account_id', $cust)->sum('balance');
        $negative = DB::table('account_balances')->whereIn('account_id', $cust)->where('balance', '<', 0)->count();
        $committed = DB::table('idempotency_keys')->where('scope', 'ledger.post')
            ->where('key', 'like', "$run-w%")->whereNotNull('transaction_id')->count();
        $orphans = DB::table('idempotency_keys')->where('key', 'like', "$run-%")->whereNull('transaction_id')->count();
        $targetIds = DB::table('idempotency_keys')->where('key', 'like', "$run-target-%")->pluck('transaction_id')->all();
        $reversalRows = DB::table('transactions')->whereIn('reverses_transaction_id', $targetIds)->count();

        $this->newLine();
        $reconcileOk = $this->call('ledger:reconcile') === 0;

        $checks = [
            ['All workers finished cleanly', $crashed === 0, "crashed=$crashed"],
            ['No unexpected errors (deadlocks, raw DB errors)', $unexpected === [], count($unexpected) . ' errors ' . json_encode(array_slice(array_count_values($unexpected), 0, 3))],
            ['Reconciliation passes', $reconcileOk, ''],
            ['Money conserved: cash == sum(customers)', $cashBal === $custBal, "cash=$cashBal customers=$custBal"],
            ['No customer went negative', $negative === 0, "negative=$negative"],
            ['Every reported posting exists exactly once', $committed === $sum['posted'], "db=$committed reported={$sum['posted']}"],
            ['No orphaned idempotency claims', $orphans === 0, "orphans=$orphans"],
            ['Each target reversed at most once, and counts agree', $reversalRows <= $targetCount && $reversalRows === $sum['reversed'], "db=$reversalRows reported={$sum['reversed']} targets=$targetCount"],
        ];

        $this->newLine();
        $failed = 0;
        foreach ($checks as [$label, $ok, $detail]) {
            $ok ? $this->line("  [ok]   $label") : $this->line("  [FAIL] $label   ($detail)");
            $failed += $ok ? 0 : 1;
        }

        $failed ? $this->error('STRESS TEST FAILED') : $this->info('STRESS TEST PASSED');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}