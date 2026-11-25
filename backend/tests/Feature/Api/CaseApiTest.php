<?php

namespace Tests\Feature\Api;

use App\Ledger\AccountService;
use App\Ledger\Events\EventBus;
use App\Ledger\Events\OutboxPublisher;
use App\Ledger\Exceptions\LedgerException;
use App\Ledger\LedgerService;
use App\Models\User;
use App\Monitoring\AlertWorkflow;
use App\Monitoring\MonitoringConsumer;
use App\Monitoring\RuleAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CaseApiTest extends TestCase
{
    use RefreshDatabase;

    private EventBus $bus;
    private int $cash;
    private int $alice;
    private User $maya;
    private User $sue;
    private User $sam;
    private User $ruth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bus = new EventBus(app(MonitoringConsumer::class));
        $this->app->instance(EventBus::class, $this->bus);

        $accounts = app(AccountService::class);
        $this->cash = $accounts->open('GL-CASH', 'Bank cash', 'asset', 'MYR', true);
        $this->alice = $accounts->open('C-ALICE', 'Alice', 'liability');

        $this->maya = User::factory()->create(['username' => 'maya.a', 'name' => 'Maya', 'role' => 'analyst']);
        $this->sue = User::factory()->create(['username' => 'sue.p', 'name' => 'Sue', 'role' => 'supervisor']);
        $this->sam = User::factory()->create(['username' => 'sam.s', 'name' => 'Sam', 'role' => 'supervisor']);
        $this->ruth = User::factory()->create(['username' => 'ruth.r', 'name' => 'Ruth', 'role' => 'rule_admin']);

        app(RuleAdmin::class)->define('LARGE', 'large_amount', 'Large single transaction', 'test rationale',
            ['threshold_sen' => 1_000, 'directions' => ['D', 'C'], 'account_types' => ['liability']], 'high');
    }

    /** Two deposits raise two alerts in one group. @return int the group id */
    private function raise(): int
    {
        $ledger = app(LedgerService::class);
        foreach (['k1', 'k2'] as $key) {
            $ledger->post('deposit', [
                ['account_id' => $this->cash, 'direction' => 'D', 'amount' => 5_000],
                ['account_id' => $this->alice, 'direction' => 'C', 'amount' => 5_000],
            ], $key);
        }
        $r = (new OutboxPublisher($this->bus))->publishBatch(100);
        $this->assertSame(0, $r['failed']);

        return (int) DB::table('monitoring_alert_groups')->value('id');
    }

    private function frontend(): array
    {
        return ['Referer' => 'http://localhost:5173/'];
    }

    private function actAs(User $user): static
    {
        $this->app['auth']->forgetGuards();
        return $this->actingAs($user);
    }

    private function fetch(User $user, string $uri)
    {
        return $this->actAs($user)->getJson($uri, $this->frontend());
    }

    private function send(User $user, string $uri, array $body = [])
    {
        return $this->actAs($user)->postJson($uri, $body, $this->frontend());
    }

    private function openCase(): int
    {
        return (int) $this->send($this->sue, '/api/groups/' . $this->raise() . '/case')->assertStatus(201)->json('id');
    }

    private function resolveAll(string $disposition = 'false_positive'): void
    {
        $wf = app(AlertWorkflow::class);
        foreach (DB::table('monitoring_alerts')->where('status', 'open')->pluck('id') as $id) {
            $wf->start((int) $id, 'maya.a');
            $wf->close((int) $id, 'maya.a', $disposition, 'Reviewed');
        }
    }

    private function lastOf(array $detail): array
    {
        return $detail['timeline'][array_key_last($detail['timeline'])];
    }

    public function test_cases_are_a_supervisors_tool(): void
    {
        $group = $this->raise();

        $this->getJson('/api/cases')->assertStatus(401);
        $this->fetch($this->maya, '/api/cases')->assertStatus(403);
        $this->fetch($this->ruth, '/api/cases')->assertStatus(403);
        $this->send($this->maya, "/api/groups/$group/case")->assertStatus(403);
        $this->fetch($this->sue, '/api/cases')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_opening_a_case_uses_the_login_and_the_group_list_shows_it(): void
    {
        $group = $this->raise();

        $r = $this->send($this->sue, "/api/groups/$group/case", ['note' => 'Pattern worth a look', 'owner' => 'sam.s'])
            ->assertStatus(201)->json();

        $this->assertSame('sue.p', $r['owner']['username']);   // the body cannot name the owner
        $this->assertSame('open', $r['status']);
        $this->assertSame('Sue opened this case.', $r['timeline'][0]['sentence']);
        $this->assertSame('Pattern worth a look', $r['timeline'][0]['note']);
        $this->assertSame(2, $r['group']['alert_count']);
        $this->assertCount(2, $r['alerts']);
        $this->assertSame(['number' => 'C-ALICE', 'status' => 'active'], $r['account']);

        $this->send($this->sue, "/api/groups/$group/case")
            ->assertStatus(409)->assertJsonPath('reason', 'CASE_EXISTS')->assertJsonPath('context.case_id', $r['id']);
        $this->send($this->sue, '/api/groups/999999/case')->assertStatus(404)->assertJsonPath('reason', 'GROUP_NOT_FOUND');

        $groups = $this->fetch($this->maya, '/api/groups')->json('data');   // analysts can see that a case exists
        $this->assertSame(['id' => $r['id'], 'status' => 'open'], $groups[0]['case']);
    }

    public function test_the_detail_tells_each_supervisor_what_they_can_do(): void
    {
        $id = $this->openCase();

        $mine = $this->fetch($this->sue, "/api/cases/$id")->assertOk()->json();
        $this->assertSame(['add_note' => true, 'assign' => true, 'freeze' => true, 'unfreeze' => false, 'close' => false], $mine['can']);
        $this->assertSame(['2 alerts still need a decision.'], $mine['close_blockers']);
        $this->assertFalse($mine['must_escalate']);

        $other = $this->fetch($this->sam, "/api/cases/$id")->assertOk()->json();
        $this->assertSame(['add_note' => true, 'assign' => true, 'freeze' => false, 'unfreeze' => false, 'close' => false], $other['can']);
    }

    public function test_notes_and_handover_read_like_a_conversation(): void
    {
        $id = $this->openCase();

        $r = $this->send($this->sam, "/api/cases/$id/notes", ['note' => 'Checked the history.'])->assertOk()->json();
        $this->assertSame('Sam added a note.', $this->lastOf($r)['sentence']);
        $this->assertSame('Checked the history.', $this->lastOf($r)['note']);

        $r = $this->send($this->sue, "/api/cases/$id/assign", ['to' => 'sam.s', 'note' => 'Over to you'])->assertOk()->json();
        $this->assertSame('sam.s', $r['owner']['username']);
        $this->assertSame('Sue assigned this case to Sam.', $this->lastOf($r)['sentence']);

        $this->send($this->sam, "/api/cases/$id/assign", ['to' => 'maya.a'])->assertStatus(422)->assertJsonPath('reason', 'INVALID_ASSIGNEE');
        $this->send($this->sam, "/api/cases/$id/assign", ['to' => 'sam.s'])->assertStatus(422)->assertJsonPath('reason', 'INVALID_INPUT');
        $this->send($this->sam, "/api/cases/$id/notes", [])->assertStatus(422)->assertJsonValidationErrors('note');
    }

    public function test_freezing_through_a_case_acts_on_the_ledger_and_is_recorded_everywhere(): void
    {
        $id = $this->openCase();
        $reason = 'Possible account takeover, pending contact with the customer';

        $this->send($this->sam, "/api/cases/$id/freeze", ['reason' => $reason])->assertStatus(403)->assertJsonPath('reason', 'NOT_OWNER');
        $this->send($this->sue, "/api/cases/$id/freeze", [])->assertStatus(422)->assertJsonValidationErrors('reason');

        $r = $this->send($this->sue, "/api/cases/$id/freeze", ['reason' => $reason])->assertOk()->json();

        $this->assertSame('frozen', $r['account']['status']);
        $this->assertSame('Sue froze account C-ALICE.', $this->lastOf($r)['sentence']);
        $this->assertSame($reason, $this->lastOf($r)['note']);
        $this->assertSame(['add_note' => true, 'assign' => true, 'freeze' => false, 'unfreeze' => true, 'close' => false], $r['can']);

        // The ledger really stopped.
        try {
            app(LedgerService::class)->post('deposit', [
                ['account_id' => $this->cash, 'direction' => 'D', 'amount' => 1_000],
                ['account_id' => $this->alice, 'direction' => 'C', 'amount' => 1_000],
            ], 'after-freeze');
            $this->fail('The frozen account accepted a posting');
        } catch (LedgerException $e) {
            $this->assertSame('ACCOUNT_NOT_POSTABLE', $e->reason);
        }

        // The audit row and the event carry the same story.
        $change = DB::table('account_status_changes')->first();
        $this->assertSame(['sue.p', 'active', 'frozen', $reason], [$change->actor, $change->from_status, $change->to_status, $change->reason]);
        $event = DB::table('outbox_events')->where('event_type', 'account.frozen')->first();
        $this->assertSame('sue.p', json_decode($event->payload, true)['actor']);

        $this->send($this->sue, "/api/cases/$id/freeze", ['reason' => 'again'])->assertStatus(409)->assertJsonPath('reason', 'ALREADY_FROZEN');
    }

    public function test_lifting_a_freeze_through_a_case(): void
    {
        $id = $this->openCase();
        $this->send($this->sue, "/api/cases/$id/freeze", ['reason' => 'Pending a call'])->assertOk();

        $r = $this->send($this->sue, "/api/cases/$id/unfreeze", ['reason' => 'Customer verified'])->assertOk()->json();

        $this->assertSame('active', $r['account']['status']);
        $this->assertSame('Sue lifted the freeze on account C-ALICE.', $this->lastOf($r)['sentence']);
        $this->assertFalse(app(LedgerService::class)->post('deposit', [
            ['account_id' => $this->cash, 'direction' => 'D', 'amount' => 1_000],
            ['account_id' => $this->alice, 'direction' => 'C', 'amount' => 1_000],
        ], 'after-unfreeze')->replayed);

        $this->send($this->sue, "/api/cases/$id/unfreeze", ['reason' => 'again'])->assertStatus(409)->assertJsonPath('reason', 'NOT_FROZEN');
        $this->assertSame(1, DB::table('outbox_events')->where('event_type', 'account.unfrozen')->count());
    }

    public function test_closing_a_case_through_the_api(): void
    {
        $id = $this->openCase();
        $close = ['outcome' => 'no_further_action', 'note' => 'The customer explained everything.'];

        $this->send($this->sue, "/api/cases/$id/close", $close)
            ->assertStatus(409)->assertJsonPath('reason', 'ALERTS_UNRESOLVED')
            ->assertJsonPath('message', '2 alerts in this case still need a decision. Close them first.');

        $this->send($this->sue, "/api/cases/$id/close", ['outcome' => 'whatever', 'note' => 'x'])->assertStatus(422)->assertJsonValidationErrors('outcome');
        $this->send($this->sue, "/api/cases/$id/close", ['outcome' => 'no_further_action'])->assertStatus(422)->assertJsonValidationErrors('note');

        $this->resolveAll();
        $r = $this->send($this->sue, "/api/cases/$id/close", $close)->assertOk()->json();

        $this->assertSame('closed', $r['status']);
        $this->assertSame('no further action', $r['outcome_label']);
        $this->assertSame('Sue closed this case as no further action.', $this->lastOf($r)['sentence']);
        $this->assertSame(['add_note' => false, 'assign' => false, 'freeze' => false, 'unfreeze' => false, 'close' => false], $r['can']);

        $this->send($this->sue, "/api/cases/$id/freeze", ['reason' => 'x'])->assertStatus(409)->assertJsonPath('reason', 'CASE_CLOSED');
        $this->send($this->sam, "/api/cases/$id/notes", ['note' => 'Late thought'])->assertStatus(409)->assertJsonPath('reason', 'CASE_CLOSED');
    }

    public function test_a_suspicious_finding_cannot_be_closed_as_nothing(): void
    {
        $id = $this->openCase();
        $ids = DB::table('monitoring_alerts')->orderBy('id')->pluck('id');
        $wf = app(AlertWorkflow::class);
        $wf->start((int) $ids[0], 'maya.a');
        $wf->close((int) $ids[0], 'maya.a', 'confirmed_suspicious', 'No explanation');
        $wf->start((int) $ids[1], 'maya.a');
        $wf->close((int) $ids[1], 'maya.a', 'false_positive', 'Normal');

        $this->assertTrue($this->fetch($this->sue, "/api/cases/$id")->json('must_escalate'));

        $this->send($this->sue, "/api/cases/$id/close", ['outcome' => 'no_further_action', 'note' => 'Nothing'])
            ->assertStatus(409)->assertJsonPath('reason', 'OUTCOME_CONFLICT');
        $this->send($this->sue, "/api/cases/$id/close", ['outcome' => 'escalated_to_compliance', 'note' => 'Passed on'])
            ->assertOk()->assertJsonPath('outcome_label', 'escalated to compliance');
    }

    public function test_the_case_list_filters_and_counts(): void
    {
        $id = $this->openCase();

        $r = $this->fetch($this->sue, '/api/cases')->assertOk()->json();
        $this->assertSame(1, $r['total']);
        $this->assertSame($id, $r['data'][0]['id']);
        $this->assertSame(['total' => 2, 'unresolved' => 2, 'max_score' => 65], $r['data'][0]['alerts']);
        $this->assertSame(['number' => 'C-ALICE', 'status' => 'active'], $r['data'][0]['account']);
        $this->assertSame('Sue', $r['data'][0]['owner']['name']);

        $this->fetch($this->sam, '/api/cases?owner=me')->assertOk()->assertJsonPath('total', 0);
        $this->fetch($this->sue, '/api/cases?owner=me')->assertOk()->assertJsonPath('total', 1);
        $this->fetch($this->sue, '/api/cases?status=closed')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_the_case_assignee_picker_lists_only_supervisors(): void
    {
        $names = array_column($this->fetch($this->sue, '/api/case-assignees')->assertOk()->json('data'), 'name');

        $this->assertSame(['Sam', 'Sue'], $names);   // not Maya (analyst), not Ruth (rule admin)
    }
}