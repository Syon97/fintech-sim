<?php

namespace Tests\Feature\Monitoring;

use App\Ledger\AccountService;
use App\Ledger\Events\EventBus;
use App\Ledger\Events\OutboxPublisher;
use App\Ledger\LedgerService;
use App\Monitoring\AlertException;
use App\Monitoring\AlertGrouper;
use App\Monitoring\AlertWorkflow;
use App\Monitoring\MonitoringConsumer;
use App\Monitoring\RuleAdmin;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MonitoringWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private LedgerService $ledger;
    private RuleAdmin $rules;
    private AlertWorkflow $workflow;
    private EventBus $bus;
    private int $cash;
    private int $alice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledger = app(LedgerService::class);
        $this->rules = app(RuleAdmin::class);
        $this->workflow = app(AlertWorkflow::class);
        $this->bus = new EventBus(app(MonitoringConsumer::class));
        $this->app->instance(EventBus::class, $this->bus);

        $accounts = app(AccountService::class);
        $this->cash = $accounts->open('GL-CASH', 'Bank cash', 'asset', 'MYR', true);
        $this->alice = $accounts->open('C-ALICE', 'Alice', 'liability');
    }

    private function deposit(int $amount, string $key)
    {
        return $this->ledger->post('deposit', [
            ['account_id' => $this->cash, 'direction' => 'D', 'amount' => $amount],
            ['account_id' => $this->alice, 'direction' => 'C', 'amount' => $amount],
        ], $key);
    }

    private function publish(): void
    {
        $r = (new OutboxPublisher($this->bus))->publishBatch(1000);
        $this->assertSame(0, $r['failed']);
    }

    private function largeRule(): void
    {
        $this->rules->define('LARGE', 'large_amount', 'Large amount', 'test rationale',
            ['threshold_sen' => 1_000, 'directions' => ['D', 'C'], 'account_types' => ['liability']], 'high');
    }

    private function openAlert(): int
    {
        $this->largeRule();
        $this->deposit(5_000, 'k1');
        $this->publish();

        return (int) DB::table('monitoring_alerts')->value('id');
    }

    private function assertRejected(string $reason, callable $fn): void
    {
        try {
            $fn();
            $this->fail("Expected AlertException with reason $reason");
        } catch (AlertException $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    public function test_alerts_are_scored_with_a_breakdown_and_grouped_per_account(): void
    {
        $this->largeRule();
        $this->rules->define('VELO', 'velocity', 'Velocity', 'test rationale',
            ['max_count' => 1, 'window_minutes' => 10, 'directions' => ['D', 'C'], 'account_types' => ['liability']], 'medium');

        $this->deposit(5_000, 'k1');
        $this->deposit(5_000, 'k2');
        $this->publish();

        $alerts = DB::table('monitoring_alerts as a')
            ->join('monitoring_rule_versions as v', 'v.id', '=', 'a.rule_version_id')
            ->join('monitoring_rules as r', 'r.id', '=', 'v.rule_id')
            ->orderBy('a.id')->get(['a.*', 'r.code']);

        // event 1: LARGE (60). event 2: LARGE (60 + 5 repeat), VELOCITY (40 + 5 repeat + 15 other rule).
        $this->assertSame(['LARGE', 'LARGE', 'VELO'], $alerts->pluck('code')->all());
        $this->assertSame([60, 65, 60], $alerts->pluck('risk_score')->map(fn ($s) => (int) $s)->all());

        $velo = json_decode($alerts[2]->score_breakdown, true);
        $this->assertSame(
            ['severity' => 40, 'repeat_alerts' => 5, 'other_rule_alerts' => 15],
            array_column($velo['components'], 'points', 'factor'),
        );
        $this->assertSame(60, $velo['total']);

        $this->assertCount(1, $alerts->pluck('group_id')->unique());
        $group = DB::table('monitoring_alert_groups')->first();
        $this->assertSame(3, (int) $group->alert_count);
        $this->assertSame(65, (int) $group->max_score);
    }

    public function test_workflow_happy_path_is_audited(): void
    {
        $id = $this->openAlert();

        $this->workflow->start($id, 'analyst.a');
        $this->workflow->close($id, 'analyst.a', 'false_positive', 'Customer confirmed a property purchase');

        $alert = DB::table('monitoring_alerts')->find($id);
        $this->assertSame('closed', $alert->status);
        $this->assertSame('false_positive', $alert->disposition);
        $this->assertSame('analyst.a', $alert->assigned_to);
        $this->assertNotNull($alert->closed_at);

        $trail = DB::table('monitoring_alert_events')->where('alert_id', $id)->orderBy('id')->get();
        $this->assertCount(2, $trail);
        $this->assertSame(['open', 'investigating'], [$trail[0]->from_status, $trail[0]->to_status]);
        $this->assertSame(['investigating', 'closed'], [$trail[1]->from_status, $trail[1]->to_status]);
        $this->assertStringContainsString('property', $trail[1]->note);
    }

    public function test_closing_requires_a_valid_disposition_and_a_note(): void
    {
        $id = $this->openAlert();
        $this->workflow->start($id, 'analyst.a');

        $this->assertRejected('INVALID_INPUT', fn () => $this->workflow->close($id, 'analyst.a', 'false_positive', '   '));
        $this->assertRejected('INVALID_INPUT', fn () => $this->workflow->close($id, 'analyst.a', 'whatever', 'a note'));

        $this->assertSame('investigating', DB::table('monitoring_alerts')->where('id', $id)->value('status'));
    }

    public function test_only_the_assignee_can_release_or_close(): void
    {
        $id = $this->openAlert();
        $this->workflow->start($id, 'analyst.a');

        $this->assertRejected('NOT_ASSIGNEE', fn () => $this->workflow->close($id, 'analyst.b', 'false_positive', 'n'));
        $this->assertRejected('NOT_ASSIGNEE', fn () => $this->workflow->release($id, 'analyst.b'));

        $this->workflow->release($id, 'analyst.a', 'need more information');
        $alert = DB::table('monitoring_alerts')->find($id);
        $this->assertSame('open', $alert->status);
        $this->assertNull($alert->assigned_to);
    }

    public function test_illegal_transitions_are_rejected_by_the_service(): void
    {
        $id = $this->openAlert();

        $this->assertRejected('ILLEGAL_TRANSITION', fn () => $this->workflow->close($id, 'analyst.a', 'false_positive', 'n')); // open -> closed
        $this->workflow->start($id, 'analyst.a');
        $this->assertRejected('ILLEGAL_TRANSITION', fn () => $this->workflow->start($id, 'analyst.b'));                        // already investigating
        $this->assertRejected('ALERT_NOT_FOUND', fn () => $this->workflow->start(999_999, 'analyst.a'));
    }

    public function test_illegal_transitions_are_rejected_by_the_database_too(): void
    {
        $id = $this->openAlert();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('illegal status transition');
        DB::table('monitoring_alerts')->where('id', $id)->update(['status' => 'closed', 'disposition' => 'false_positive']);
    }

    public function test_alert_facts_cannot_be_rewritten(): void
    {
        $id = $this->openAlert();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('only workflow fields');
        DB::table('monitoring_alerts')->where('id', $id)->update(['explanation' => 'edited after the fact']);
    }

    public function test_closed_alerts_are_final(): void
    {
        $id = $this->openAlert();
        $this->workflow->start($id, 'analyst.a');
        $this->workflow->close($id, 'analyst.a', 'confirmed_suspicious', 'Escalated to compliance');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('final');
        DB::table('monitoring_alerts')->where('id', $id)->update(['assigned_to' => 'someone.else']);
    }

    public function test_the_audit_trail_is_append_only(): void
    {
        $id = $this->openAlert();
        $this->workflow->start($id, 'analyst.a');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('append-only');
        DB::table('monitoring_alert_events')->where('alert_id', $id)->update(['actor' => 'someone.else']);
    }

    public function test_groups_split_after_a_24_hour_gap_and_never_mix_accounts(): void
    {
        $g = app(AlertGrouper::class);

        $a = $g->assign(999, 'X-1', '2026-01-01 00:00:00+00', 50);
        $b = $g->assign(999, 'X-1', '2026-01-01 23:00:00+00', 70);   // within 24h of the group's last alert
        $c = $g->assign(999, 'X-1', '2026-01-02 23:00:01+00', 30);   // more than 24h after the last alert
        $d = $g->assign(998, 'Y-1', '2026-01-01 00:30:00+00', 10);   // different account

        $this->assertSame($a, $b);
        $this->assertNotSame($b, $c);
        $this->assertNotSame($a, $d);

        $group = DB::table('monitoring_alert_groups')->find($a);
        $this->assertSame(2, (int) $group->alert_count);
        $this->assertSame(70, (int) $group->max_score);
    }
}