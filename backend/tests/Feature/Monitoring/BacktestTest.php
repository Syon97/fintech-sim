<?php

namespace Tests\Feature\Monitoring;

use App\Ledger\AccountService;
use App\Ledger\Events\EventBus;
use App\Ledger\Events\OutboxPublisher;
use App\Ledger\LedgerService;
use App\Monitoring\Backtester;
use App\Monitoring\MonitoringConsumer;
use App\Monitoring\RuleAdmin;
use App\Monitoring\RuleChangeService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class BacktestTest extends TestCase
{
    use RefreshDatabase;

    private Backtester $bt;
    private int $cash;
    private int $alice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bt = app(Backtester::class);
        $bus = new EventBus(app(MonitoringConsumer::class));
        $this->app->instance(EventBus::class, $bus);

        $accounts = app(AccountService::class);
        $this->cash = $accounts->open('GL-CASH', 'Bank cash', 'asset', 'MYR', true);
        $this->alice = $accounts->open('C-ALICE', 'Alice', 'liability');

        app(RuleAdmin::class)->define('LARGE', 'large_amount', 'Large', 'r', $this->params(25_000), 'high');

        $ledger = app(LedgerService::class);
        foreach ([5_000, 20_000, 30_000] as $i => $amount) {
            $ledger->post('deposit', [
                ['account_id' => $this->cash, 'direction' => 'D', 'amount' => $amount],
                ['account_id' => $this->alice, 'direction' => 'C', 'amount' => $amount],
            ], "k$i");
        }
        $r = (new OutboxPublisher($bus))->publishBatch(100);
        $this->assertSame(0, $r['failed']);
    }

    private function params(int $threshold): array
    {
        return ['threshold_sen' => $threshold, 'directions' => ['D', 'C'], 'account_types' => ['liability']];
    }

    private function counts(): array
    {
        return array_map(fn ($t) => DB::table($t)->count(),
            ['monitoring_alerts', 'monitoring_rules', 'monitoring_rule_versions', 'monitoring_rule_change_requests', 'monitoring_alert_groups']);
    }

    public function test_it_reports_what_a_looser_rule_would_have_raised_and_the_overlap_with_live(): void
    {
        $r = $this->bt->run('LARGE', 'large_amount', $this->params(10_000), 'high');

        $this->assertSame(3, $r['events_scanned']);
        $this->assertSame(2, $r['alerts']);              // 20,000 and 30,000
        $this->assertSame(1, $r['distinct_accounts']);
        $this->assertSame(1, $r['live_alerts']);         // the live rule only caught 30,000
        $this->assertSame(1, $r['overlap_with_live']);
        $this->assertCount(2, $r['sample_explanations']);
        $this->assertSame([['account' => 'C-ALICE', 'alerts' => 2]], $r['top_accounts']);
    }

    public function test_a_backtest_leaves_nothing_behind(): void
    {
        $before = $this->counts();
        $liveBefore = DB::table('monitoring_rule_versions')->where('status', 'active')->pluck('id')->all();

        $this->bt->run('LARGE', 'large_amount', $this->params(1_000), 'high');
        $this->bt->run('BRAND-NEW', 'large_amount', $this->params(1_000), 'low');

        $this->assertSame($before, $this->counts());
        $this->assertSame($liveBefore, DB::table('monitoring_rule_versions')->where('status', 'active')->pluck('id')->all());
    }

    public function test_a_brand_new_rule_has_no_live_comparison(): void
    {
        $r = $this->bt->run('BRAND-NEW', 'large_amount', $this->params(1_000), 'low');

        $this->assertSame(3, $r['alerts']);
        $this->assertNull($r['live_version_id']);
        $this->assertSame(0, $r['overlap_with_live']);
    }

    public function test_invalid_drafts_and_type_changes_are_refused(): void
    {
        foreach ([
            fn () => $this->bt->run('X', 'large_amount', $this->params(0), 'low'),
            fn () => $this->bt->run('X', 'nonsense', $this->params(1_000), 'low'),
            fn () => $this->bt->run('LARGE', 'velocity', ['max_count' => 3, 'window_minutes' => 10], 'low'),
        ] as $call) {
            try {
                $call();
                $this->fail('Expected InvalidArgumentException');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_a_request_backtest_is_recorded_for_the_checker_and_changes_nothing(): void
    {
        $id = app(RuleChangeService::class)->propose('maker.m', 'LARGE', 'large_amount', 'Large', 'r', $this->params(10_000), 'high', 'Catch more');
        $before = $this->counts();

        $r = $this->bt->runForRequest($id, 'Checker.C');

        $row = DB::table('monitoring_backtests')->where('change_request_id', $id)->first();
        $this->assertSame(2, json_decode($row->result, true)['alerts']);
        $this->assertSame($r['alerts'], json_decode($row->result, true)['alerts']);
        $this->assertSame('checker.c', $row->requested_by);
        $this->assertSame('pending', DB::table('monitoring_rule_change_requests')->where('id', $id)->value('status'));
        $this->assertSame($before, $this->counts());

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('append-only');
        DB::table('monitoring_backtests')->where('id', $row->id)->update(['requested_by' => 'someone.else']);
    }
}