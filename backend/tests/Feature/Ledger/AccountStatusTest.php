<?php

namespace Tests\Feature\Ledger;

use App\Ledger\AccountService;
use App\Ledger\Events\EventBus;
use App\Ledger\Events\OutboxPublisher;
use App\Ledger\Exceptions\LedgerException;
use App\Ledger\LedgerService;
use App\Monitoring\MonitoringConsumer;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AccountStatusTest extends TestCase
{
    use RefreshDatabase;

    private AccountService $accounts;
    private LedgerService $ledger;
    private int $cash;
    private int $alice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accounts = app(AccountService::class);
        $this->ledger = app(LedgerService::class);
        $this->cash = $this->accounts->open('GL-CASH', 'Bank cash', 'asset', 'MYR', true);
        $this->alice = $this->accounts->open('C-ALICE', 'Alice', 'liability');
    }

    private function deposit(string $key)
    {
        return $this->ledger->post('deposit', [
            ['account_id' => $this->cash, 'direction' => 'D', 'amount' => 1_000],
            ['account_id' => $this->alice, 'direction' => 'C', 'amount' => 1_000],
        ], $key);
    }

    private function assertRejected(string $reason, callable $fn): void
    {
        try {
            $fn();
            $this->fail("Expected LedgerException with reason $reason");
        } catch (LedgerException $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    public function test_freezing_is_audited_announced_and_stops_postings(): void
    {
        $this->accounts->freeze($this->alice, 'sue.p', 'Possible account takeover');

        $this->assertSame('frozen', DB::table('accounts')->where('id', $this->alice)->value('status'));

        $change = DB::table('account_status_changes')->first();
        $this->assertSame(
            [$this->alice, 'active', 'frozen', 'sue.p', 'Possible account takeover'],
            [(int) $change->account_id, $change->from_status, $change->to_status, $change->actor, $change->reason],
        );

        $event = DB::table('outbox_events')->where('event_type', 'account.frozen')->first();
        $this->assertSame('account', $event->aggregate_type);
        $this->assertSame((string) $this->alice, $event->aggregate_id);
        $p = json_decode($event->payload, true);
        $this->assertSame(
            ['C-ALICE', 'active', 'frozen', 'sue.p', 'Possible account takeover'],
            [$p['account_number'], $p['from_status'], $p['to_status'], $p['actor'], $p['reason']],
        );
        $this->assertSame((int) $change->id, $p['change_id']);

        $this->assertRejected('ACCOUNT_NOT_POSTABLE', fn () => $this->deposit('k1'));
    }

    public function test_lifting_a_freeze_restores_postings_and_is_recorded(): void
    {
        $this->accounts->freeze($this->alice, 'sue.p', 'Pending a call with the customer');
        $this->accounts->unfreeze($this->alice, 'sue.p', 'Customer verified');

        $this->assertSame(['frozen', 'active'], DB::table('account_status_changes')->orderBy('id')->pluck('to_status')->all());
        $this->assertSame(1, DB::table('outbox_events')->where('event_type', 'account.unfrozen')->count());
        $this->assertFalse($this->deposit('k1')->replayed);
    }

    public function test_refused_changes_write_nothing(): void
    {
        $this->assertRejected('NOT_FROZEN', fn () => $this->accounts->unfreeze($this->alice, 'sue.p', 'Mistake'));
        $this->assertRejected('UNKNOWN_ACCOUNT', fn () => $this->accounts->freeze(999_999, 'sue.p', 'No such account'));
        $this->assertRejected('INVALID_POSTING', fn () => $this->accounts->freeze($this->alice, 'sue.p', '   '));
        $this->assertRejected('INVALID_POSTING', fn () => $this->accounts->freeze($this->alice, '', 'A reason'));
        $this->assertSame(0, DB::table('account_status_changes')->count());
        $this->assertSame(0, DB::table('outbox_events')->count());

        $this->accounts->freeze($this->alice, 'sue.p', 'First freeze');
        $this->assertRejected('ALREADY_FROZEN', fn () => $this->accounts->freeze($this->alice, 'sue.p', 'Second freeze'));
        $this->assertSame(1, DB::table('account_status_changes')->count());
        $this->assertSame(1, DB::table('outbox_events')->count());

        DB::table('accounts')->where('id', $this->cash)->update(['status' => 'closed']);
        $this->assertRejected('ACCOUNT_CLOSED', fn () => $this->accounts->freeze($this->cash, 'sue.p', 'Closed one'));
    }

    public function test_the_database_refuses_a_status_change_that_has_no_audit_record(): void
    {
        DB::table('accounts')->where('id', $this->alice)->update(['status' => 'frozen']);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('no audit record');
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE'); // the check that normally runs at COMMIT
    }

    public function test_a_properly_audited_change_passes_the_commit_check(): void
    {
        $this->accounts->freeze($this->alice, 'sue.p', 'Reason one');
        $this->accounts->unfreeze($this->alice, 'sue.p', 'Reason two');

        DB::statement('SET CONSTRAINTS ALL IMMEDIATE'); // would throw if any change lacked its audit record

        $this->assertSame(2, DB::table('account_status_changes')->count());
    }

    public function test_the_status_audit_trail_is_append_only(): void
    {
        $this->accounts->freeze($this->alice, 'sue.p', 'Reason');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('append-only');
        DB::table('account_status_changes')->update(['reason' => 'edited later']);
    }

    public function test_account_events_pass_through_the_monitoring_consumer_without_harm(): void
    {
        $this->accounts->freeze($this->alice, 'sue.p', 'Reason');

        $result = (new OutboxPublisher(new EventBus(app(MonitoringConsumer::class))))->publishBatch(10);

        $this->assertSame(['published' => 1, 'failed' => 0], $result);
        $this->assertSame(0, DB::table('monitoring_activity')->count());
    }

    public function test_the_ledger_knows_nothing_about_monitoring(): void
    {
        foreach (File::allFiles(app_path('Ledger')) as $file) {
            $this->assertStringNotContainsString('App\\Monitoring', $file->getContents(), $file->getFilename() . ' depends on monitoring');
        }
    }
}