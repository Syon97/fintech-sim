<?php

namespace Tests\Feature\Monitoring;

use App\Ledger\AccountService;
use App\Ledger\Events\EventBus;
use App\Ledger\Events\OutboxPublisher;
use App\Ledger\LedgerService;
use App\Monitoring\AlertWorkflow;
use App\Monitoring\CaseException;
use App\Monitoring\CaseService;
use App\Monitoring\MonitoringConsumer;
use App\Monitoring\RuleAdmin;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CaseTest extends TestCase
{
    use RefreshDatabase;

    private CaseService $cases;
    private AlertWorkflow $alerts;
    private EventBus $bus;
    private int $cash;
    private int $alice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cases = app(CaseService::class);
        $this->alerts = app(AlertWorkflow::class);
        $this->bus = new EventBus(app(MonitoringConsumer::class));
        $this->app->instance(EventBus::class, $this->bus);

        $accounts = app(AccountService::class);
        $this->cash = $accounts->open('GL-CASH', 'Bank cash', 'asset', 'MYR', true);
        $this->alice = $accounts->open('C-ALICE', 'Alice', 'liability');

        app(RuleAdmin::class)->define('LARGE', 'large_amount', 'Large single transaction', 'test rationale',
            ['threshold_sen' => 1_000, 'directions' => ['D', 'C'], 'account_types' => ['liability']], 'high');
    }

    private function deposit(string $key): void
    {
        app(LedgerService::class)->post('deposit', [
            ['account_id' => $this->cash, 'direction' => 'D', 'amount' => 5_000],
            ['account_id' => $this->alice, 'direction' => 'C', 'amount' => 5_000],
        ], $key);
    }

    private function publish(): void
    {
        $r = (new OutboxPublisher($this->bus))->publishBatch(100);
        $this->assertSame(0, $r['failed']);
    }

    /** Two deposits raise two alerts, which land in one group. @return int[] alert ids */
    private function raise(): array
    {
        $this->deposit('k1');
        $this->deposit('k2');
        $this->publish();

        return DB::table('monitoring_alerts')->orderBy('id')->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    private function groupId(): int
    {
        return (int) DB::table('monitoring_alert_groups')->value('id');
    }

    private function resolveAll(string $disposition = 'false_positive'): void
    {
        foreach (DB::table('monitoring_alerts')->where('status', 'open')->pluck('id') as $id) {
            $this->alerts->start((int) $id, 'maya.a');
            $this->alerts->close((int) $id, 'maya.a', $disposition, 'Reviewed');
        }
    }

    private function assertRejected(string $reason, callable $fn): void
    {
        try {
            $fn();
            $this->fail("Expected CaseException with reason $reason");
        } catch (CaseException $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    public function test_a_case_is_opened_from_a_group_and_only_once(): void
    {
        $this->raise();
        $groupId = $this->groupId();

        $id = $this->cases->open($groupId, 'sue.p', 'Looks like a pattern');

        $case = DB::table('monitoring_cases')->where('id', $id)->first();
        $this->assertSame(['open', 'sue.p', 'sue.p', 'C-ALICE', $groupId],
            [$case->status, $case->owner, $case->opened_by, $case->account_number, (int) $case->group_id]);

        $events = DB::table('monitoring_case_events')->where('case_id', $id)->get();
        $this->assertSame(['opened'], $events->pluck('event')->all());
        $this->assertSame('Looks like a pattern', $events[0]->note);

        $this->assertRejected('CASE_EXISTS', fn () => $this->cases->open($groupId, 'sam.s'));
        $this->assertRejected('GROUP_NOT_FOUND', fn () => $this->cases->open(999_999, 'sue.p'));
    }

    public function test_notes_and_handover_are_recorded(): void
    {
        $this->raise();
        $id = $this->cases->open($this->groupId(), 'sue.p');

        $this->cases->addNote($id, 'sam.s', 'Second opinion: the amounts look unusual.');
        $this->cases->assign($id, 'sue.p', 'sam.s', 'Over to you');

        $this->assertSame('sam.s', DB::table('monitoring_cases')->where('id', $id)->value('owner'));
        $events = DB::table('monitoring_case_events')->where('case_id', $id)->orderBy('id')->get();
        $this->assertSame(['opened', 'note', 'assigned'], $events->pluck('event')->all());
        $this->assertSame('sam.s', $events[2]->assigned_to);

        $this->assertRejected('INVALID_INPUT', fn () => $this->cases->assign($id, 'sue.p', 'sam.s'));  // already the owner
        $this->assertRejected('INVALID_INPUT', fn () => $this->cases->addNote($id, 'sam.s', '   '));
        $this->assertRejected('CASE_NOT_FOUND', fn () => $this->cases->addNote(999_999, 'sam.s', 'x'));
    }

    public function test_closing_needs_the_owner_decided_alerts_a_note_and_an_outcome(): void
    {
        $this->raise();
        $id = $this->cases->open($this->groupId(), 'sue.p');

        $this->assertRejected('NOT_OWNER', fn () => $this->cases->close($id, 'sam.s', 'no_further_action', 'Done'));
        $this->assertRejected('ALERTS_UNRESOLVED', fn () => $this->cases->close($id, 'sue.p', 'no_further_action', 'Done'));

        $this->resolveAll();

        $this->assertRejected('INVALID_INPUT', fn () => $this->cases->close($id, 'sue.p', 'whatever', 'Done'));
        $this->assertRejected('INVALID_INPUT', fn () => $this->cases->close($id, 'sue.p', 'no_further_action', '  '));
        $this->assertSame('open', DB::table('monitoring_cases')->where('id', $id)->value('status'));

        $this->cases->close($id, 'sue.p', 'no_further_action', 'All explained by the customer');

        $case = DB::table('monitoring_cases')->where('id', $id)->first();
        $this->assertSame(['closed', 'no_further_action', 'sue.p'], [$case->status, $case->outcome, $case->closed_by]);
        $this->assertNotNull($case->closed_at);
        $this->assertSame('closed', DB::table('monitoring_case_events')->where('case_id', $id)->orderByDesc('id')->value('event'));
    }

    public function test_a_confirmed_suspicious_alert_forces_escalation(): void
    {
        [$a, $b] = $this->raise();
        $id = $this->cases->open($this->groupId(), 'sue.p');

        $this->alerts->start($a, 'maya.a');
        $this->alerts->close($a, 'maya.a', 'confirmed_suspicious', 'Customer could not explain it');
        $this->alerts->start($b, 'maya.a');
        $this->alerts->close($b, 'maya.a', 'false_positive', 'Normal');

        $this->assertRejected('OUTCOME_CONFLICT', fn () => $this->cases->close($id, 'sue.p', 'no_further_action', 'Nothing to see'));

        $this->cases->close($id, 'sue.p', 'escalated_to_compliance', 'Passed to the compliance officer');
        $this->assertSame('escalated_to_compliance', DB::table('monitoring_cases')->where('id', $id)->value('outcome'));
    }

    public function test_a_closed_case_cannot_be_changed_through_the_service(): void
    {
        $this->raise();
        $id = $this->cases->open($this->groupId(), 'sue.p');
        $this->resolveAll();
        $this->cases->close($id, 'sue.p', 'no_further_action', 'Done');

        $this->assertRejected('CASE_CLOSED', fn () => $this->cases->addNote($id, 'sue.p', 'Late thought'));
        $this->assertRejected('CASE_CLOSED', fn () => $this->cases->assign($id, 'sue.p', 'sam.s'));
        $this->assertRejected('CASE_CLOSED', fn () => $this->cases->close($id, 'sue.p', 'no_further_action', 'Again'));
    }

    public function test_the_database_refuses_to_close_a_case_with_unresolved_alerts(): void
    {
        $this->raise();
        $id = $this->cases->open($this->groupId(), 'sue.p');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('unresolved');
        DB::table('monitoring_cases')->where('id', $id)->update([
            'status' => 'closed', 'outcome' => 'no_further_action', 'closing_note' => 'x',
            'closed_by' => 'sue.p', 'closed_at' => now(),
        ]);
    }

    public function test_the_database_lets_only_the_owner_close(): void
    {
        $this->raise();
        $id = $this->cases->open($this->groupId(), 'sue.p');
        $this->resolveAll();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('monitoring_cases_closer_chk');
        DB::table('monitoring_cases')->where('id', $id)->update([
            'status' => 'closed', 'outcome' => 'no_further_action', 'closing_note' => 'x',
            'closed_by' => 'sam.s', 'closed_at' => now(),
        ]);
    }

    public function test_closed_cases_are_final_in_the_database_too(): void
    {
        $this->raise();
        $id = $this->cases->open($this->groupId(), 'sue.p');
        $this->resolveAll();
        $this->cases->close($id, 'sue.p', 'no_further_action', 'Done');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('final');
        DB::table('monitoring_cases')->where('id', $id)->update(['owner' => 'sam.s']);
    }

    public function test_cases_cannot_be_deleted(): void
    {
        $this->raise();
        $id = $this->cases->open($this->groupId(), 'sue.p');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('not allowed');
        DB::table('monitoring_cases')->where('id', $id)->delete();
    }

    public function test_the_case_history_is_append_only(): void
    {
        $this->raise();
        $id = $this->cases->open($this->groupId(), 'sue.p', 'First look');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('append-only');
        DB::table('monitoring_case_events')->where('case_id', $id)->update(['note' => 'edited later']);
    }

    public function test_closing_a_case_seals_its_group(): void
    {
        $this->raise();
        $first = $this->groupId();
        $id = $this->cases->open($first, 'sue.p');
        $this->resolveAll();
        $this->cases->close($id, 'sue.p', 'no_further_action', 'Done');

        $this->deposit('k3');   // a new alert for the same account, minutes later
        $this->publish();

        $newest = DB::table('monitoring_alerts')->orderByDesc('id')->first();
        $this->assertNotSame($first, (int) $newest->group_id);                                          // it did not join the finished case's group
        $this->assertSame(2, (int) DB::table('monitoring_alert_groups')->where('id', $first)->value('alert_count'));
        $this->assertSame(2, DB::table('monitoring_alert_groups')->count());
    }
}