<?php

namespace Tests\Feature\Ledger;

use App\Ledger\AccountService;
use App\Ledger\Events\EventBus;
use App\Ledger\Events\EventConsumer;
use App\Ledger\Events\LedgerEvent;
use App\Ledger\Events\OutboxPublisher;
use App\Ledger\Events\ProbeConsumer;
use App\Ledger\Exceptions\LedgerException;
use App\Ledger\LedgerService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class OutboxTest extends TestCase
{
    use RefreshDatabase;

    private LedgerService $ledger;
    private int $cash;
    private int $alice;
    private int $bob;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledger = app(LedgerService::class);
        $accounts = app(AccountService::class);

        $this->cash = $accounts->open('GL-CASH', 'Bank cash', 'asset', 'MYR', true);
        $this->alice = $accounts->open('C-ALICE', 'Alice', 'liability');
        $this->bob = $accounts->open('C-BOB', 'Bob', 'liability');
    }

    private function deposit(int $to, int $amount, string $key)
    {
        return $this->ledger->post('deposit', [
            ['account_id' => $this->cash, 'direction' => 'D', 'amount' => $amount],
            ['account_id' => $to, 'direction' => 'C', 'amount' => $amount],
        ], $key);
    }

    public function test_posting_writes_exactly_one_versioned_event(): void
    {
        $tx = $this->deposit($this->alice, 10_000, 'k1');

        $events = DB::table('outbox_events')->get();
        $this->assertCount(1, $events);

        $e = $events[0];
        $this->assertSame('transaction.posted', $e->event_type);
        $this->assertSame(1, (int) $e->schema_version);
        $this->assertSame($tx->transactionId, $e->aggregate_id);
        $this->assertNull($e->published_at);

        $p = json_decode($e->payload, true);
        $this->assertSame('deposit', $p['type']);
        $this->assertCount(2, $p['entries']);

        $alice = collect($p['entries'])->firstWhere('account_number', 'C-ALICE');
        $this->assertSame(['C', 10_000, 'MYR', 10_000, 'liability'],
            [$alice['direction'], $alice['amount'], $alice['currency'], $alice['balance_after'], $alice['account_type']]);
    }

    public function test_rejected_and_replayed_postings_write_no_extra_events(): void
    {
        $this->deposit($this->alice, 5_000, 'k1');
        $this->deposit($this->alice, 5_000, 'k1'); // idempotent replay

        try {
            $this->ledger->post('withdrawal', [
                ['account_id' => $this->alice, 'direction' => 'D', 'amount' => 9_000],
                ['account_id' => $this->cash, 'direction' => 'C', 'amount' => 9_000],
            ], 'k2');
            $this->fail('Expected overdraft rejection');
        } catch (LedgerException $e) {
            $this->assertSame('INSUFFICIENT_FUNDS', $e->reason);
        }

        $this->assertSame(1, DB::table('outbox_events')->count());
    }

    public function test_reversal_event_links_to_the_original(): void
    {
        $original = $this->deposit($this->alice, 10_000, 'k1');
        $reversal = $this->ledger->reverse($original->transactionId, 'r1');

        $row = DB::table('outbox_events')->where('aggregate_id', $reversal->transactionId)->first();
        $p = json_decode($row->payload, true);

        $this->assertSame('reversal', $p['type']);
        $this->assertSame($original->transactionId, $p['reverses_transaction_id']);
    }

    public function test_event_payload_cannot_be_changed(): void
    {
        $this->deposit($this->alice, 10_000, 'k1');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('only delivery bookkeeping');
        DB::table('outbox_events')->update(['payload' => '{}']);
    }

    public function test_events_cannot_be_deleted(): void
    {
        $this->deposit($this->alice, 10_000, 'k1');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('append-only');
        DB::table('outbox_events')->delete();
    }

    public function test_publisher_delivers_each_event_once_and_marks_it_published(): void
    {
        $this->deposit($this->alice, 1_000, 'k1');
        $this->deposit($this->bob, 2_000, 'k2');

        $publisher = new OutboxPublisher(new EventBus(new ProbeConsumer()));

        $this->assertSame(['published' => 2, 'failed' => 0], $publisher->publishBatch());
        $this->assertSame(0, DB::table('outbox_events')->whereNull('published_at')->count());
        $this->assertSame(2, DB::table('consumer_inbox')->where('consumer', 'probe')->count());

        $this->assertSame(['published' => 0, 'failed' => 0], $publisher->publishBatch());
    }

    public function test_failing_consumer_leaves_the_event_pending_and_it_can_be_retried(): void
    {
        $this->deposit($this->alice, 1_000, 'k1');

        $bad = new class implements EventConsumer {
            public function name(): string { return 'bad'; }
            public function handle(LedgerEvent $event): void { throw new RuntimeException('boom'); }
        };

        $r = (new OutboxPublisher(new EventBus($bad)))->publishBatch();
        $this->assertSame(['published' => 0, 'failed' => 1], $r);

        $row = DB::table('outbox_events')->first();
        $this->assertSame(1, (int) $row->attempts);
        $this->assertStringContainsString('boom', $row->last_error);
        $this->assertNull($row->published_at);
        $this->assertSame(0, DB::table('consumer_inbox')->count()); // consumer's savepoint rolled back

        $good = new OutboxPublisher(new EventBus(new ProbeConsumer()));

        // Quarantine: at the attempt limit the event is no longer retried...
        $this->assertSame(['published' => 0, 'failed' => 0], $good->publishBatch(100, 1));
        // ...but it is still there, and a normal retry succeeds.
        $this->assertSame(['published' => 1, 'failed' => 0], $good->publishBatch());
    }

    public function test_replay_redelivers_to_one_consumer(): void
    {
        $counter = new class implements EventConsumer {
            public int $n = 0;
            public function name(): string { return 'counter'; }
            public function handle(LedgerEvent $event): void { $this->n++; }
        };
        $bus = new EventBus($counter);
        $this->app->instance(EventBus::class, $bus);

        $this->deposit($this->alice, 1_000, 'k1');
        $this->deposit($this->bob, 2_000, 'k2');
        (new OutboxPublisher($bus))->publishBatch();
        $this->assertSame(2, $counter->n);

        $this->artisan('ledger:outbox-replay', ['consumer' => 'counter', '--from-id' => 1])->assertExitCode(0);
        $this->assertSame(4, $counter->n);

        $this->artisan('ledger:outbox-replay', ['consumer' => 'nope'])->assertExitCode(1);
    }
}