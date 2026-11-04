<?php

namespace Tests\Feature\Monitoring;

use App\Ledger\AccountService;
use App\Ledger\Events\EventBus;
use App\Ledger\Events\OutboxPublisher;
use App\Ledger\LedgerService;
use App\Monitoring\MonitoringConsumer;
use App\Monitoring\RuleAdmin;
use App\Monitoring\RuleChangeException;
use App\Monitoring\RuleChangeService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class RuleChangeTest extends TestCase
{
    use RefreshDatabase;

    private RuleChangeService $svc;
    private LedgerService $ledger;
    private EventBus $bus;
    private int $cash;
    private int $alice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(RuleChangeService::class);
        $this->ledger = app(LedgerService::class);
        $this->bus = new EventBus(app(MonitoringConsumer::class));
        $this->app->instance(EventBus::class, $this->bus);

        $accounts = app(AccountService::class);
        $this->cash = $accounts->open('GL-CASH', 'Bank cash', 'asset', 'MYR', true);
        $this->alice = $accounts->open('C-ALICE', 'Alice', 'liability');
    }

    private function params(int $threshold = 1_000): array
    {
        return ['threshold_sen' => $threshold, 'directions' => ['D', 'C'], 'account_types' => ['liability']];
    }

    private function propose(string $code = 'LARGE', int $threshold = 1_000, string $reason = 'Initial rule'): int
    {
        return $this->svc->propose('maker.m', $code, 'large_amount', 'Large amount', 'test rationale', $this->params($threshold), 'high', $reason);
    }

    private function deposit(int $amount, string $key): void
    {
        $this->ledger->post('deposit', [
            ['account_id' => $this->cash, 'direction' => 'D', 'amount' => $amount],
            ['account_id' => $this->alice, 'direction' => 'C', 'amount' => $amount],
        ], $key);
    }

    private function publish(): void
    {
        $r = (new OutboxPublisher($this->bus))->publishBatch(1000);
        $this->assertSame(0, $r['failed']);
    }

    private function requestStatus(int $id): string
    {
        return DB::table('monitoring_rule_change_requests')->where('id', $id)->value('status');
    }

    private function assertRejected(string $reason, callable $fn): void
    {
        try {
            $fn();
            $this->fail("Expected RuleChangeException with reason $reason");
        } catch (RuleChangeException $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    public function test_nothing_goes_live_until_approved_and_alerts_trace_back_to_the_change(): void
    {
        $id = $this->propose();

        $this->deposit(5_000, 'k1');
        $this->publish();
        $this->assertSame(0, DB::table('monitoring_rules')->count());
        $this->assertSame(0, DB::table('monitoring_alerts')->count()); // proposed is not live

        $versionId = $this->svc->approve($id, 'checker.c', 'Looks right');

        $this->deposit(5_000, 'k2');
        $this->publish();
        $alert = DB::table('monitoring_alerts')->first();
        $this->assertNotNull($alert);
        $this->assertSame($versionId, (int) $alert->rule_version_id);

        // Alert -> rule version -> the approved change: who proposed, who approved, and why.
        $req = DB::table('monitoring_rule_change_requests')
            ->where('version_id', $alert->rule_version_id)->where('change_type', 'define')->first();
        $this->assertSame(['maker.m', 'checker.c', 'approved', 'Initial rule'], [$req->maker, $req->checker, $req->status, $req->reason]);
        $this->assertSame('Looks right', $req->decision_note);
        $this->assertNotNull($req->decided_at);
    }

    public function test_the_maker_cannot_approve_or_reject_their_own_request(): void
    {
        $id = $this->propose();

        $this->assertRejected('SELF_APPROVAL', fn () => $this->svc->approve($id, 'maker.m'));
        $this->assertRejected('SELF_APPROVAL', fn () => $this->svc->approve($id, '  MAKER.M '));  // identity is normalised
        $this->assertRejected('SELF_APPROVAL', fn () => $this->svc->reject($id, 'maker.m', 'no'));

        $this->assertSame('pending', $this->requestStatus($id));
        $this->assertSame(0, DB::table('monitoring_rules')->count()); // activation rolled back with the failed approval
    }

    public function test_four_eyes_is_also_enforced_by_the_database(): void
    {
        $id = $this->propose();
        $versionId = app(RuleAdmin::class)->define('OTHER', 'large_amount', 'Other', 'rationale', $this->params(), 'low');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('mrcr_four_eyes_chk');
        DB::table('monitoring_rule_change_requests')->where('id', $id)->update([
            'status' => 'approved', 'checker' => 'maker.m', 'version_id' => $versionId, 'decided_at' => now(),
        ]);
    }

    public function test_rejection_needs_a_note_and_is_final(): void
    {
        $id = $this->propose();

        $this->assertRejected('INVALID_INPUT', fn () => $this->svc->reject($id, 'checker.c', '  '));
        $this->assertSame('pending', $this->requestStatus($id));

        $this->svc->reject($id, 'checker.c', 'Threshold too low for retail customers');
        $this->assertSame('rejected', $this->requestStatus($id));

        $this->assertRejected('ALREADY_DECIDED', fn () => $this->svc->approve($id, 'checker.d'));
        $this->assertSame(0, DB::table('monitoring_rules')->count());
    }

    public function test_only_the_maker_can_withdraw_and_a_withdrawn_request_frees_the_rule_code(): void
    {
        $id = $this->propose();

        $this->assertRejected('NOT_MAKER', fn () => $this->svc->withdraw($id, 'someone.else'));
        $this->svc->withdraw($id, 'maker.m');
        $this->assertSame('withdrawn', $this->requestStatus($id));

        $again = $this->propose('LARGE', 2_000, 'Second attempt');
        $this->assertNotSame($id, $again);
    }

    public function test_only_one_request_per_rule_can_be_pending(): void
    {
        $this->propose();
        $this->assertRejected('PENDING_EXISTS', fn () => $this->propose('LARGE', 2_000));

        // The unique index is the backstop if two proposals ever race past the check.
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('mrcr_one_pending_per_rule');
        DB::table('monitoring_rule_change_requests')->insert([
            'rule_code' => 'LARGE', 'change_type' => 'define', 'rule_type' => 'large_amount', 'name' => 'n',
            'rationale' => 'r', 'parameters' => json_encode($this->params()), 'severity' => 'low',
            'reason' => 'x', 'maker' => 'maker.m',
        ]);
    }

    public function test_a_new_version_replaces_the_old_one_after_approval_and_the_rule_type_cannot_change(): void
    {
        $v1 = $this->svc->approve($this->propose(), 'checker.c');
        $v2 = $this->svc->approve($this->propose('LARGE', 2_000, 'Raise the limit'), 'checker.c');

        $this->assertNotSame($v1, $v2);
        $this->assertSame('retired', DB::table('monitoring_rule_versions')->where('id', $v1)->value('status'));
        $this->assertSame('active', DB::table('monitoring_rule_versions')->where('id', $v2)->value('status'));
        $this->assertSame(2, (int) DB::table('monitoring_rule_versions')->where('id', $v2)->value('version'));

        $this->assertRejected('TYPE_MISMATCH', fn () => $this->svc->propose(
            'maker.m', 'LARGE', 'velocity', 'Velocity', 'r', ['max_count' => 3, 'window_minutes' => 10], 'low', 'Change type'));
    }

    public function test_retiring_a_rule_needs_approval_too(): void
    {
        $this->svc->approve($this->propose(), 'checker.c');
        $this->deposit(5_000, 'k1');
        $this->publish();
        $this->assertSame(1, DB::table('monitoring_alerts')->count()); // live

        $id = $this->svc->proposeRetire('maker.m', 'LARGE', 'Replaced by a segment-specific rule');
        $this->deposit(5_000, 'k2');
        $this->publish();
        $this->assertSame(2, DB::table('monitoring_alerts')->count()); // still no change: proposal only
        $this->assertSame(1, DB::table('monitoring_rule_versions')->where('status', 'active')->count());

        $this->assertRejected('SELF_APPROVAL', fn () => $this->svc->approve($id, 'maker.m'));
        $versionId = $this->svc->approve($id, 'checker.c');
        $this->assertSame('retired', DB::table('monitoring_rule_versions')->where('id', $versionId)->value('status'));

        $this->deposit(5_000, 'k3');
        $this->publish();
        $this->assertSame(2, DB::table('monitoring_alerts')->count()); // retired: nothing new

        $this->assertRejected('NOTHING_TO_RETIRE', fn () => $this->svc->proposeRetire('maker.m', 'LARGE', 'again'));
        $this->assertRejected('UNKNOWN_RULE', fn () => $this->svc->proposeRetire('maker.m', 'NOPE', 'no such rule'));
    }

    public function test_invalid_proposals_are_rejected_before_anything_is_stored(): void
    {
        $this->assertRejected('INVALID_RULE', fn () => $this->svc->propose('maker.m', 'X', 'large_amount', 'n', 'r', $this->params(0), 'low', 'why'));
        $this->assertRejected('INVALID_RULE', fn () => $this->svc->propose('maker.m', 'X', 'nonsense', 'n', 'r', $this->params(), 'low', 'why'));
        $this->assertRejected('INVALID_INPUT', fn () => $this->svc->propose('maker.m', 'X', 'large_amount', 'n', 'r', $this->params(), 'low', '  '));
        $this->assertRejected('INVALID_INPUT', fn () => $this->svc->propose('maker.m', 'X', 'large_amount', 'n', 'r', $this->params(), 'urgent', 'why'));

        $this->assertSame(0, DB::table('monitoring_rule_change_requests')->count());
    }

    public function test_proposal_facts_cannot_be_edited(): void
    {
        $id = $this->propose();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('only decision fields');
        DB::table('monitoring_rule_change_requests')->where('id', $id)->update(['parameters' => '{"threshold_sen": 1}']);
    }

    public function test_decided_requests_are_final(): void
    {
        $id = $this->propose();
        $this->svc->approve($id, 'checker.c');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('final');
        DB::table('monitoring_rule_change_requests')->where('id', $id)->update(['decision_note' => 'edited later']);
    }

    public function test_requests_cannot_be_deleted(): void
    {
        $id = $this->propose();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('not allowed');
        DB::table('monitoring_rule_change_requests')->where('id', $id)->delete();
    }

    public function test_only_the_approval_workflow_activates_rules(): void
    {
        // Seeding and the stress test are the only other trusted callers of the low-level primitive.
        $allowed = ['Monitoring/RuleChangeService.php', 'Console/Commands/MonitoringInstallRules.php', 'Console/Commands/LedgerStress.php'];

        foreach (File::allFiles(app_path()) as $file) {
            $rel = str_replace('\\', '/', $file->getRelativePathname());
            if (in_array($rel, $allowed, true)) {
                continue;
            }
            $this->assertDoesNotMatchRegularExpression('/->define\(/', $file->getContents(),
                "$rel calls RuleAdmin::define() directly; go through RuleChangeService");
        }
    }
}