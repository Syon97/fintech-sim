<?php

namespace Tests\Feature\Api;

use App\Ledger\AccountService;
use App\Ledger\Events\EventBus;
use App\Ledger\Events\OutboxPublisher;
use App\Ledger\LedgerService;
use App\Models\User;
use App\Monitoring\AlertWorkflow;
use App\Monitoring\MonitoringConsumer;
use App\Monitoring\RuleAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AlertApiTest extends TestCase
{
    use RefreshDatabase;

    private EventBus $bus;
    private int $cash;
    private int $alice;
    private User $maya;
    private User $ben;
    private User $sue;
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
        $this->ben = User::factory()->create(['username' => 'ben.b', 'name' => 'Ben', 'role' => 'analyst']);
        $this->sue = User::factory()->create(['username' => 'sue.p', 'name' => 'Sue', 'role' => 'supervisor']);
        $this->ruth = User::factory()->create(['username' => 'ruth.r', 'name' => 'Ruth', 'role' => 'rule_admin']);

        app(RuleAdmin::class)->define('LARGE', 'large_amount', 'Large single transaction', 'test rationale',
            ['threshold_sen' => 1_000, 'directions' => ['D', 'C'], 'account_types' => ['liability']], 'high');
    }

    /** Two deposits of RM50 raise two alerts: the second scores higher (a repeat). @return int[] alert ids, oldest first */
    private function raise(): array
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

        return DB::table('monitoring_alerts')->orderBy('id')->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    private function frontend(): array
    {
        return ['Referer' => 'http://localhost:5173/'];
    }

    private function actAs(User $user): static
    {
        $this->app['auth']->forgetGuards(); // each request starts with nobody remembered
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

    private function lastOf(array $detail): array
    {
        return $detail['timeline'][array_key_last($detail['timeline'])];
    }

    public function test_the_queue_leads_with_the_highest_score_and_speaks_plainly(): void
    {
        $this->raise();

        $r = $this->fetch($this->maya, '/api/alerts')->assertOk();
        $rows = $r->json('data');

        $this->assertSame([65, 60], array_column($rows, 'risk_score'));
        $this->assertSame(2, $r->json('total'));
        $this->assertSame('C-ALICE', $rows[0]['account_number']);
        $this->assertStringContainsString('RM 50.00', $rows[0]['explanation']);
        $this->assertSame('Large single transaction', $rows[0]['rule']['name']);
        $this->assertSame('open', $rows[0]['status']);
        $this->assertNull($rows[0]['assignee']);
    }

    public function test_the_queue_can_be_filtered(): void
    {
        [$a, $b] = $this->raise(); // $a scores 60, $b scores 65
        $this->send($this->maya, "/api/alerts/$a/start")->assertOk();

        $ids = fn (string $q) => array_column($this->fetch($this->maya, "/api/alerts$q")->assertOk()->json('data'), 'id');

        $this->assertEqualsCanonicalizing([$a, $b], $ids(''));          // unresolved by default
        $this->assertSame([$b], $ids('?status=open'));
        $this->assertSame([$a], $ids('?status=investigating'));
        $this->assertSame([$a], $ids('?assigned=me'));
        $this->assertSame([$b], $ids('?assigned=unassigned'));
        $this->assertSame([$b], $ids('?min_score=65'));
        $this->assertSame([], $ids('?account=NOPE'));
    }

    public function test_an_alert_explains_itself(): void
    {
        [, $b] = $this->raise();

        $d = $this->fetch($this->maya, "/api/alerts/$b")->assertOk()->json();

        $this->assertSame(65, $d['risk_score']);
        $this->assertSame('test rationale', $d['rule']['rationale']);
        $this->assertSame(1, $d['rule']['version']);
        $this->assertSame("Starts at 60 because the rule's severity is high.", $d['score']['sentences'][0]);
        $this->assertStringContainsString('Adds 5 for 1 earlier alert', $d['score']['sentences'][1]);

        $this->assertCount(1, $d['evidence']);
        $this->assertSame('RM 50.00', $d['evidence'][0]['amount_display']);
        $this->assertSame('C', $d['evidence'][0]['direction']);

        $this->assertCount(1, $d['timeline']);
        $this->assertStringStartsWith('Raised by', $d['timeline'][0]['sentence']);

        $this->assertSame(2, $d['group']['alert_count']);
        $this->assertSame(2, $d['group']['unresolved']);
        $this->assertSame(['start' => true, 'release' => false, 'close' => false, 'reassign' => false], $d['can']);
    }

    public function test_permissions_are_enforced_on_every_route(): void
    {
        [$a] = $this->raise();

        $this->getJson('/api/alerts')->assertStatus(401);
        $this->fetch($this->ruth, '/api/alerts')->assertStatus(403);        // rule admins do not work alerts
        $this->fetch($this->ruth, '/api/groups')->assertStatus(403);
        $this->send($this->ruth, "/api/alerts/$a/start")->assertStatus(403);
        $this->send($this->maya, "/api/alerts/$a/reassign", ['to' => 'ben.b'])->assertStatus(403); // analysts cannot reassign
        $this->fetch($this->maya, '/api/assignees')->assertStatus(403);
    }

    public function test_working_an_alert_through_the_api_is_recorded_in_words(): void
    {
        [$a] = $this->raise();

        $started = $this->send($this->maya, "/api/alerts/$a/start")->assertOk()->json();
        $this->assertSame('investigating', $started['status']);
        $this->assertSame(['username' => 'maya.a', 'name' => 'Maya'], $started['assignee']);
        $this->assertSame('Maya started looking at this.', $this->lastOf($started)['sentence']);
        $this->assertSame(['start' => false, 'release' => true, 'close' => true, 'reassign' => false], $started['can']);

        $closed = $this->send($this->maya, "/api/alerts/$a/close", [
            'disposition' => 'false_positive',
            'note' => 'Customer confirmed a property purchase.',
        ])->assertOk()->json();

        $this->assertSame('closed', $closed['status']);
        $this->assertSame('false_positive', $closed['disposition']);
        $this->assertSame('Maya closed this as a false positive.', $this->lastOf($closed)['sentence']);
        $this->assertSame('Customer confirmed a property purchase.', $this->lastOf($closed)['note']);
        $this->assertSame(['start' => false, 'release' => false, 'close' => false, 'reassign' => false], $closed['can']);

        $this->assertSame([$a], array_column($this->fetch($this->maya, '/api/alerts?status=closed')->json('data'), 'id'));
    }

    public function test_the_actor_comes_from_the_login_never_from_the_request(): void
    {
        [$a] = $this->raise();

        $r = $this->send($this->maya, "/api/alerts/$a/start", ['actor' => 'ben.b', 'assigned_to' => 'ben.b'])
            ->assertOk()->json();

        $this->assertSame('maya.a', $r['assignee']['username']);
        $this->assertSame('maya.a', $this->lastOf($r)['actor']['username']);
    }

    public function test_only_the_assignee_can_release_or_close_and_stale_actions_are_refused_politely(): void
    {
        [$a, $b] = $this->raise();
        $this->send($this->maya, "/api/alerts/$a/start")->assertOk();

        $this->send($this->ben, "/api/alerts/$a/close", ['disposition' => 'false_positive', 'note' => 'n'])
            ->assertStatus(403)->assertJsonPath('reason', 'NOT_ASSIGNEE');
        $this->send($this->ben, "/api/alerts/$a/release")
            ->assertStatus(403)->assertJsonPath('reason', 'NOT_ASSIGNEE');

        // Ben opened the queue before Maya started it: his "start" is now stale.
        $this->send($this->ben, "/api/alerts/$a/start")
            ->assertStatus(409)->assertJsonPath('reason', 'ILLEGAL_TRANSITION')
            ->assertJsonPath('message', 'This alert has moved on since you opened it. Refresh to see where it stands.');

        // An alert nobody has started cannot be closed.
        $this->send($this->maya, "/api/alerts/$b/close", ['disposition' => 'false_positive', 'note' => 'n'])->assertStatus(409);
    }

    public function test_closing_needs_a_note_and_a_valid_disposition(): void
    {
        [$a] = $this->raise();
        $this->send($this->maya, "/api/alerts/$a/start")->assertOk();

        $this->send($this->maya, "/api/alerts/$a/close", ['disposition' => 'false_positive'])
            ->assertStatus(422)->assertJsonValidationErrors('note');
        $this->send($this->maya, "/api/alerts/$a/close", ['disposition' => 'whatever', 'note' => 'n'])
            ->assertStatus(422)->assertJsonValidationErrors('disposition');

        $this->assertSame('investigating', $this->fetch($this->maya, "/api/alerts/$a")->json('status'));
    }

    public function test_a_supervisor_can_reassign_and_the_timeline_says_so(): void
    {
        [$a] = $this->raise();
        $this->send($this->maya, "/api/alerts/$a/start")->assertOk();

        $r = $this->send($this->sue, "/api/alerts/$a/reassign", ['to' => 'ben.b', 'note' => 'Maya is out today'])
            ->assertOk()->json();

        $this->assertSame('ben.b', $r['assignee']['username']);
        $this->assertSame('Sue reassigned this to Ben.', $this->lastOf($r)['sentence']);
        $this->assertSame('Maya is out today', $this->lastOf($r)['note']);
        $this->assertSame(['start' => false, 'release' => false, 'close' => false, 'reassign' => true], $r['can']);

        $this->send($this->maya, "/api/alerts/$a/close", ['disposition' => 'false_positive', 'note' => 'n'])->assertStatus(403);
        $this->send($this->ben, "/api/alerts/$a/close", ['disposition' => 'false_positive', 'note' => 'Checked'])->assertOk();
    }

    public function test_reassign_refuses_bad_targets(): void
    {
        [$a, $b] = $this->raise();
        $this->send($this->maya, "/api/alerts/$a/start")->assertOk();

        $this->send($this->sue, "/api/alerts/$a/reassign", ['to' => 'ruth.r'])->assertStatus(422)->assertJsonPath('reason', 'INVALID_ASSIGNEE');
        $this->send($this->sue, "/api/alerts/$a/reassign", ['to' => 'nobody.here'])->assertStatus(422)->assertJsonPath('reason', 'INVALID_ASSIGNEE');
        $this->send($this->sue, "/api/alerts/$a/reassign", ['to' => 'maya.a'])->assertStatus(422)->assertJsonPath('reason', 'INVALID_INPUT');
        $this->send($this->sue, "/api/alerts/$b/reassign", ['to' => 'ben.b'])->assertStatus(409); // nobody is working it yet

        $inactive = User::factory()->create(['username' => 'old.a', 'role' => 'analyst', 'active' => false]);
        $this->send($this->sue, "/api/alerts/$a/reassign", ['to' => $inactive->username])->assertStatus(422);
    }

    public function test_groups_show_what_is_still_unresolved(): void
    {
        [$a, $b] = $this->raise();

        $g = $this->fetch($this->maya, '/api/groups')->assertOk()->json('data');
        $this->assertCount(1, $g);
        $this->assertSame(['C-ALICE', 2, 2, 65], [$g[0]['account_number'], $g[0]['alert_count'], $g[0]['unresolved'], $g[0]['max_score']]);

        $wf = app(AlertWorkflow::class);
        foreach ([$a, $b] as $id) {
            $wf->start($id, 'maya.a');
            $wf->close($id, 'maya.a', 'false_positive', 'Reviewed');
        }

        $this->assertSame([], $this->fetch($this->maya, '/api/groups')->json('data'));
        $this->assertCount(1, $this->fetch($this->maya, '/api/groups?state=all')->json('data'));
    }

    public function test_the_assignee_picker_lists_only_active_people_who_can_work_alerts(): void
    {
        User::factory()->create(['username' => 'old.a', 'name' => 'Olive', 'role' => 'analyst', 'active' => false]);

        $names = array_column($this->fetch($this->sue, '/api/assignees')->assertOk()->json('data'), 'name');

        $this->assertSame(['Ben', 'Maya', 'Sue'], $names); // no Ruth (rule admin), no Olive (inactive)
    }

    public function test_unknown_alerts_are_a_plain_404(): void
    {
        $this->fetch($this->maya, '/api/alerts/999999999')->assertStatus(404)->assertJsonPath('reason', 'ALERT_NOT_FOUND');
        $this->send($this->maya, '/api/alerts/999999999/start')->assertStatus(404)->assertJsonPath('reason', 'ALERT_NOT_FOUND');
    }

    public function test_controllers_never_take_the_actor_from_the_request(): void
    {
        foreach (File::allFiles(app_path('Http/Controllers')) as $file) {
            $this->assertDoesNotMatchRegularExpression('/[\'"]actor[\'"]/', $file->getContents(),
                $file->getFilename() . ' reads an actor from the request; use the logged-in user');
        }
    }
}