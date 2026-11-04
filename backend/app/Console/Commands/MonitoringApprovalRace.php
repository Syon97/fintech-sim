<?php

namespace App\Console\Commands;

use App\Monitoring\RuleChangeException;
use App\Monitoring\RuleChangeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

class MonitoringApprovalRace extends Command
{
    protected $signature = 'monitoring:approval-race {--checkers=6} {--requests=20} {--worker=} {--run=} {--start-at=}';
    protected $description = 'Several checkers race to approve the same requests; prove each is approved exactly once';

    public function handle(RuleChangeService $svc): int
    {
        return $this->option('worker') !== null ? $this->worker($svc) : $this->orchestrate($svc);
    }

    private function worker(RuleChangeService $svc): int
    {
        $run = (string) $this->option('run');
        $w = (int) $this->option('worker');
        $ids = DB::table('monitoring_rule_change_requests')->where('rule_code', 'like', "$run-R%")->orderBy('id')->pluck('id');
        $s = ['approved' => 0, 'already_decided' => 0, 'unexpected' => []];

        while (microtime(true) < (float) $this->option('start-at')) { usleep(500); }

        foreach ($ids as $id) { // every worker attacks the SAME requests in the SAME order
            try {
                $svc->approve((int) $id, "checker.$w", 'race');
                $s['approved']++;
            } catch (RuleChangeException $e) {
                $e->reason === 'ALREADY_DECIDED' ? $s['already_decided']++ : $s['unexpected'][] = $e->reason;
            } catch (Throwable $e) {
                $s['unexpected'][] = class_basename($e) . ': ' . substr($e->getMessage(), 0, 160);
            }
        }

        $this->line('RESULT ' . json_encode($s));

        return self::SUCCESS;
    }

    private function orchestrate(RuleChangeService $svc): int
    {
        $checkers = (int) $this->option('checkers');
        $n = (int) $this->option('requests');
        $run = 'race' . now()->format('His') . Str::lower(Str::random(4));
        $params = ['threshold_sen' => 1_000_000, 'directions' => ['D', 'C'], 'account_types' => ['liability']];

        $propose = fn (string $code) => $svc->propose('maker.m', $code, 'large_amount', "Race $code", 'Approval race test', $params, 'low', 'race test');

        for ($i = 1; $i <= $n; $i++) {
            $propose("$run-R$i");
        }

        // A maker approving their own request must fail, then the request is cleaned up.
        $selfId = $propose("$run-SELF");
        $selfBlocked = false;
        try {
            $svc->approve($selfId, 'maker.m');
        } catch (RuleChangeException $e) {
            $selfBlocked = $e->reason === 'SELF_APPROVAL';
        }
        $svc->reject($selfId, 'checker.0', 'cleanup');

        $this->info("Run $run: $checkers checkers racing over $n requests");

        $started = microtime(true);
        $startAt = microtime(true) + 4;
        $procs = [];
        for ($w = 1; $w <= $checkers; $w++) {
            $p = new Process([PHP_BINARY, base_path('artisan'), 'monitoring:approval-race', "--worker=$w", "--run=$run", "--start-at=$startAt"], base_path());
            $p->setTimeout(300);
            $p->start();
            $procs[$w] = $p;
        }
        foreach ($procs as $p) {
            $p->wait();
        }
        $elapsed = round(microtime(true) - $started, 1);

        $sum = ['approved' => 0, 'already_decided' => 0];
        $unexpected = [];
        $crashed = 0;
        foreach ($procs as $w => $p) {
            if (! $p->isSuccessful() || ! preg_match('/^RESULT (.+)$/m', $p->getOutput(), $m)) {
                $crashed++;
                $this->error("Checker $w failed:\n" . $p->getErrorOutput() . $p->getOutput());
                continue;
            }
            $r = json_decode(trim($m[1]), true);
            $sum['approved'] += $r['approved'];
            $sum['already_decided'] += $r['already_decided'];
            $unexpected = array_merge($unexpected, $r['unexpected']);
        }

        $rows = DB::table('monitoring_rule_change_requests')->where('rule_code', 'like', "$run-R%")->get();
        $versionIds = $rows->pluck('version_id')->filter()->unique();
        $versionsPerRule = DB::table('monitoring_rules as r')->join('monitoring_rule_versions as v', 'v.rule_id', '=', 'r.id')
            ->where('r.code', 'like', "$run-R%")->count();
        $active = DB::table('monitoring_rule_versions')->whereIn('id', $versionIds)->where('status', 'active')->count();

        $this->table(['metric', 'count'], [
            ['approvals won', $sum['approved']],
            ['lost the race (ALREADY_DECIDED)', $sum['already_decided']],
        ]);
        $this->line("Elapsed: {$elapsed}s");
        $this->line('Wins per checker: ' . $rows->groupBy('checker')->map->count()->sortKeys()->map(fn ($c, $k) => "$k=$c")->implode(', '));

        // Leave nothing live: these rules existed only for the test.
        DB::table('monitoring_rule_versions')->whereIn('id', $versionIds)->update(['status' => 'retired']);

        $checks = [
            ['All checkers finished cleanly', $crashed === 0, "crashed=$crashed"],
            ['No unexpected errors (raw database errors, duplicates)', $unexpected === [], count($unexpected) . ' errors ' . json_encode(array_slice(array_count_values($unexpected), 0, 3))],
            ['A maker cannot approve their own request', $selfBlocked, ''],
            ['Every request approved exactly once', $rows->where('status', 'approved')->count() === $n && $sum['approved'] === $n, "approved_rows={$rows->where('status', 'approved')->count()} wins={$sum['approved']} requests=$n"],
            ['Every lost race was a clean ALREADY_DECIDED', $sum['already_decided'] === ($checkers - 1) * $n, "lost={$sum['already_decided']} expected=" . (($checkers - 1) * $n)],
            ['Exactly one version created per rule, and it went live', $versionsPerRule === $n && $versionIds->count() === $n && $active === $n, "versions=$versionsPerRule distinct={$versionIds->count()} active=$active"],
        ];

        $this->newLine();
        $failed = 0;
        foreach ($checks as [$label, $ok, $detail]) {
            $ok ? $this->line("  [ok]   $label") : $this->line("  [FAIL] $label   ($detail)");
            $failed += $ok ? 0 : 1;
        }

        $failed ? $this->error('APPROVAL RACE FAILED') : $this->info('APPROVAL RACE PASSED');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}