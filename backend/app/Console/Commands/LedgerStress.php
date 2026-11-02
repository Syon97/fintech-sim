<?php

namespace App\Console\Commands;

use App\Ledger\AccountService;
use App\Ledger\LedgerService;
use App\Monitoring\RuleAdmin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class LedgerStress extends Command
{
    protected $signature = 'ledger:stress {--workers=8} {--ops=200} {--customers=4} {--publishers=3}';
    protected $description = 'Hammer the ledger from parallel processes, then prove nothing was lost, double-spent or double-published';

    public function handle(AccountService $accounts, LedgerService $ledger): int
    {
        $workers = (int) $this->option('workers');
        $ops = (int) $this->option('ops');
        $customers = (int) $this->option('customers');
        $publishers = (int) $this->option('publishers');
        $run = 'stress' . now()->format('His') . Str::lower(Str::random(4));
        $targetCount = max(1, intdiv($ops, 10));

        // Events left over from earlier activity. Publishers will drain these too.
        $backlog = DB::table('outbox_events')->whereNull('published_at')->count();

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

        $this->info("Run $run: $workers workers x $ops ops, $customers customers, $targetCount reversal targets, $publishers publishers");

        // ---- Monitoring rules for this run (retired again at the end) ----
        $admin = app(RuleAdmin::class);
        $largeVersion = $admin->define("$run-LARGE", 'large_amount', 'Stress: large amount', 'Stress test rule',
            ['threshold_sen' => 4_500, 'directions' => ['D', 'C'], 'account_types' => ['liability']], 'high');
        $veloVersion = $admin->define("$run-VELO", 'velocity', 'Stress: velocity', 'Stress test rule',
            ['max_count' => 5, 'window_minutes' => 1, 'directions' => ['D', 'C'], 'account_types' => ['liability']], 'medium');

        // ---- Publishers run WHILE the workers post ----
        $stopFile = storage_path("framework/$run.stop");
        @unlink($stopFile);
        $pubProcs = [];
        for ($p = 1; $p <= $publishers; $p++) {
            $proc = new Process([PHP_BINARY, base_path('artisan'), 'ledger:outbox-publish', "--stop-file=$stopFile", '--batch=25'], base_path());
            $proc->setTimeout(600);
            $proc->start();
            $pubProcs[$p] = $proc;
        }

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

        // Workers are done: tell publishers to drain what is left and exit.
        file_put_contents($stopFile, '1');
        foreach ($pubProcs as $p) {
            $p->wait();
        }
        @unlink($stopFile);
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

        // ---- Collect publisher reports ----
        $publishedBy = 0;
        $pubCrashed = 0;
        foreach ($pubProcs as $p => $proc) {
            if (! $proc->isSuccessful() || ! preg_match('/^PUBLISHED (\d+)\s*$/m', $proc->getOutput(), $m)) {
                $pubCrashed++;
                $this->error("Publisher $p failed:\n" . $proc->getErrorOutput() . $proc->getOutput());
                continue;
            }
            $publishedBy += (int) $m[1];
        }
        $sum['events_published'] = $publishedBy;

        $this->table(['metric', 'count'], collect($sum)->map(fn ($v, $k) => [$k, $v])->values()->all());
        $this->line("Elapsed: {$elapsed}s");

        // ---- Verify the ledger ----
        $cashBal = (int) DB::table('account_balances')->where('account_id', $cash)->value('balance');
        $custBal = (int) DB::table('account_balances')->whereIn('account_id', $cust)->sum('balance');
        $negative = DB::table('account_balances')->whereIn('account_id', $cust)->where('balance', '<', 0)->count();
        $committed = DB::table('idempotency_keys')->where('scope', 'ledger.post')
            ->where('key', 'like', "$run-w%")->whereNotNull('transaction_id')->count();
        $orphans = DB::table('idempotency_keys')->where('key', 'like', "$run-%")->whereNull('transaction_id')->count();
        $targetIds = DB::table('idempotency_keys')->where('key', 'like', "$run-target-%")->pluck('transaction_id')->all();
        $reversalRows = DB::table('transactions')->whereIn('reverses_transaction_id', $targetIds)->count();

        // ---- Verify the outbox, scoped to this run's transactions ----
        $like = "$run-%";
        $runTx = 'SELECT DISTINCT e.transaction_id AS id FROM entries e JOIN accounts a ON a.id = e.account_id WHERE a.account_number LIKE ?';
        $posted = "o.aggregate_id = r.id::text AND o.event_type = 'transaction.posted'";
        $count = fn (string $sql) => (int) DB::selectOne($sql, [$like])->c;

        $txCount = $count("SELECT count(*) AS c FROM ($runTx) r");
        $events = $count("SELECT count(*) AS c FROM ($runTx) r JOIN outbox_events o ON $posted");
        $missing = $count("SELECT count(*) AS c FROM ($runTx) r WHERE NOT EXISTS (SELECT 1 FROM outbox_events o WHERE $posted)");
        $unpublished = $count("SELECT count(*) AS c FROM ($runTx) r JOIN outbox_events o ON $posted WHERE o.published_at IS NULL");
        $retried = $count("SELECT count(*) AS c FROM ($runTx) r JOIN outbox_events o ON $posted WHERE o.attempts > 0");
        $delivered = $count("SELECT count(*) AS c FROM ($runTx) r JOIN outbox_events o ON $posted JOIN consumer_inbox ci ON ci.event_id = o.id AND ci.consumer = 'probe'");
        $mismatch = $count("SELECT count(*) AS c FROM ($runTx) r JOIN outbox_events o ON $posted
                             WHERE jsonb_array_length(o.payload->'entries') <> (SELECT count(*) FROM entries x WHERE x.transaction_id = r.id)");
        
        // ---- Verify monitoring: expected values come from the LEDGER, an independent source ----
        $monDelivered = $count("SELECT count(*) AS c FROM ($runTx) r JOIN outbox_events o ON $posted JOIN consumer_inbox ci ON ci.event_id = o.id AND ci.consumer = 'monitoring'");
        $runEntries = fn () => DB::table('entries as e')->join('accounts as a', 'a.id', '=', 'e.account_id')->where('a.account_number', 'like', $like);
        $entryRows = (int) $runEntries()->count();
        $expectedLarge = (int) $runEntries()->where('a.type', 'liability')->where('e.amount', '>=', 4_500)->count();
        $activityRows = fn () => (int) DB::table('monitoring_activity')->where('account_number', 'like', $like)->count();
        $alertCount = fn (int $versionId) => (int) DB::table('monitoring_alerts')->where('rule_version_id', $versionId)->count();

        $largeBefore = $alertCount($largeVersion);
        $veloBefore = $alertCount($veloVersion);
        $activityBefore = $activityRows();

        // Replay this run's history through monitoring: it must change nothing for large-amount alerts.
        $firstEventId = (int) DB::selectOne("SELECT MIN(o.id) AS m FROM ($runTx) r JOIN outbox_events o ON $posted", [$like])->m;
        $this->newLine();
        $this->call('ledger:outbox-replay', ['consumer' => 'monitoring', '--from-id' => $firstEventId]);
        $largeAfter = $alertCount($largeVersion);
        $veloAfter = $alertCount($veloVersion);
        $activityAfter = $activityRows();

        // Scores and groups, checked AFTER the replay so replay-created alerts are covered too.
        $unscored = (int) DB::table('monitoring_alerts')->where('account_number', 'like', $like)
            ->where(fn ($q) => $q->whereNull('risk_score')->orWhereNull('group_id'))->count();
        $badGroups = (int) DB::selectOne(
            'SELECT count(*) AS c FROM monitoring_alert_groups g
              WHERE g.account_number LIKE ?
                AND g.alert_count <> (SELECT count(*) FROM monitoring_alerts a WHERE a.group_id = g.id)',
            [$like],
        )->c;

        $this->line("Velocity alerts: $veloBefore from live delivery, $veloAfter after sequential replay (any difference is the arrival-order effect)");

        DB::table('monitoring_rule_versions')->whereIn('id', [$largeVersion, $veloVersion])->update(['status' => 'retired']);

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
            ['Every committed transaction has exactly one event', $missing === 0 && $events === $txCount, "transactions=$txCount events=$events missing=$missing"],
            ['Event payloads match the ledger entries', $mismatch === 0, "mismatch=$mismatch"],
            ['Publishers finished cleanly', $pubCrashed === 0, "crashed=$pubCrashed"],
            ['Every event published, none failed or stuck', $unpublished === 0 && $retried === 0, "unpublished=$unpublished retried=$retried"],
            ['Each event published exactly once across all publishers', $publishedBy === $txCount + $backlog, "published=$publishedBy expected=" . ($txCount + $backlog)],
            ['Each event delivered to the consumer exactly once', $delivered === $txCount, "delivered=$delivered transactions=$txCount"],
            ['Every event delivered to monitoring exactly once', $monDelivered === $txCount, "delivered=$monDelivered transactions=$txCount"],
            ['Activity recorded exactly once per ledger entry', $activityBefore === $entryRows, "activity=$activityBefore entries=$entryRows"],
            ['Large-amount alerts match an independent count from the ledger', $largeBefore === $expectedLarge, "alerts=$largeBefore expected=$expectedLarge"],
            ['Velocity rule fired under load', $veloBefore > 0, "alerts=$veloBefore"],
            ['Replay left large-amount alerts and activity unchanged', $largeAfter === $largeBefore && $activityAfter === $activityBefore, "alerts $largeBefore->$largeAfter activity $activityBefore->$activityAfter"],
            ['Every alert has a risk score and a group', $unscored === 0, "unscored=$unscored"],
            ['Group counters match their alerts exactly', $badGroups === 0, "bad_groups=$badGroups"],
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