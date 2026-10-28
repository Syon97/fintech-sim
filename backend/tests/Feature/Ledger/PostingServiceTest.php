<?php

namespace Tests\Feature\Ledger;

use App\Ledger\AccountService;
use App\Ledger\Exceptions\LedgerException;
use App\Ledger\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PostingServiceTest extends TestCase
{
    use RefreshDatabase;

    private LedgerService $ledger;
    private AccountService $accounts;
    private int $cash;
    private int $alice;
    private int $bob;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledger = app(LedgerService::class);
        $this->accounts = app(AccountService::class);

        $this->cash = $this->accounts->open('GL-CASH', 'Bank cash', 'asset', 'MYR', true);
        $this->alice = $this->accounts->open('C-ALICE', 'Alice', 'liability');
        $this->bob = $this->accounts->open('C-BOB', 'Bob', 'liability');
    }

    private function deposit(int $to, int $amount, string $key)
    {
        return $this->ledger->post('deposit', [
            ['account_id' => $this->cash, 'direction' => 'D', 'amount' => $amount],
            ['account_id' => $to, 'direction' => 'C', 'amount' => $amount],
        ], $key);
    }

    private function withdraw(int $from, int $amount, string $key)
    {
        return $this->ledger->post('withdrawal', [
            ['account_id' => $from, 'direction' => 'D', 'amount' => $amount],
            ['account_id' => $this->cash, 'direction' => 'C', 'amount' => $amount],
        ], $key);
    }

    private function transfer(int $from, int $to, int $amount, string $key)
    {
        return $this->ledger->post('transfer', [
            ['account_id' => $from, 'direction' => 'D', 'amount' => $amount],
            ['account_id' => $to, 'direction' => 'C', 'amount' => $amount],
        ], $key);
    }

    private function balance(int $id): int
    {
        return (int) DB::table('account_balances')->where('account_id', $id)->value('balance');
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

    public function test_deposit_updates_both_sides_and_records_balance_after(): void
    {
        $this->deposit($this->alice, 10_000, 'k1');

        $this->assertSame(10_000, $this->balance($this->alice)); // liability, credited
        $this->assertSame(10_000, $this->balance($this->cash));  // asset, debited
        $this->assertSame(
            10_000,
            (int) DB::table('entries')->where('account_id', $this->alice)->orderByDesc('id')->value('balance_after'),
        );
    }

    public function test_transfer_moves_money_between_customers(): void
    {
        $this->deposit($this->alice, 10_000, 'k1');
        $this->transfer($this->alice, $this->bob, 4_000, 'k2');

        $this->assertSame(6_000, $this->balance($this->alice));
        $this->assertSame(4_000, $this->balance($this->bob));
    }

    public function test_overdraft_is_rejected_and_nothing_is_written(): void
    {
        $this->deposit($this->alice, 5_000, 'k1');
        $keysBefore = DB::table('idempotency_keys')->count();
        $entriesBefore = DB::table('entries')->count();

        $this->assertRejected('INSUFFICIENT_FUNDS', fn () => $this->withdraw($this->alice, 6_000, 'k2'));

        $this->assertSame(5_000, $this->balance($this->alice));
        $this->assertSame($entriesBefore, DB::table('entries')->count());
        $this->assertSame($keysBefore, DB::table('idempotency_keys')->count()); // key released, retry is possible
    }

    public function test_frozen_account_cannot_be_posted_to(): void
    {
        $this->deposit($this->alice, 5_000, 'k1');
        $this->accounts->freeze($this->alice);

        $this->assertRejected('ACCOUNT_NOT_POSTABLE', fn () => $this->deposit($this->alice, 1_000, 'k2'));
        $this->assertSame(5_000, $this->balance($this->alice));
    }

    public function test_unbalanced_posting_is_rejected_before_touching_the_database(): void
    {
        $this->assertRejected('INVALID_POSTING', fn () => $this->ledger->post('transfer', [
            ['account_id' => $this->alice, 'direction' => 'D', 'amount' => 1_000],
            ['account_id' => $this->bob, 'direction' => 'C', 'amount' => 900],
        ], 'k1'));

        $this->assertSame(0, DB::table('transactions')->count());
    }

    public function test_duplicate_request_never_double_posts(): void
    {
        $first = $this->deposit($this->alice, 10_000, 'same-key');
        $second = $this->deposit($this->alice, 10_000, 'same-key');

        $this->assertFalse($first->replayed);
        $this->assertTrue($second->replayed);
        $this->assertSame($first->transactionId, $second->transactionId);
        $this->assertSame(10_000, $this->balance($this->alice));
        $this->assertSame(2, DB::table('entries')->count());
    }

    public function test_same_key_with_different_payload_is_an_error(): void
    {
        $this->deposit($this->alice, 10_000, 'same-key');

        $this->assertRejected('IDEMPOTENCY_MISMATCH', fn () => $this->deposit($this->alice, 20_000, 'same-key'));
        $this->assertSame(10_000, $this->balance($this->alice));
    }

    public function test_ledger_invariants_hold_after_many_random_postings(): void
    {
        mt_srand(42); // deterministic, so a failure is reproducible
        $ids = [$this->alice, $this->bob, $this->accounts->open('C-CAROL', 'Carol', 'liability')];

        foreach ($ids as $i => $id) {
            $this->deposit($id, 100_000, "seed-$i");
        }

        for ($i = 0; $i < 200; $i++) {
            $pair = array_rand($ids, 2);
            shuffle($pair);
            [$from, $to] = [$ids[$pair[0]], $ids[$pair[1]]];

            try {
                $this->transfer($from, $to, mt_rand(1, 60_000), "t-$i");
            } catch (LedgerException $e) {
                $this->assertSame('INSUFFICIENT_FUNDS', $e->reason);
            }
        }

        // Invariant 1: all debits equal all credits.
        $net = DB::selectOne("SELECT COALESCE(SUM(CASE WHEN direction='D' THEN amount ELSE -amount END), 0) AS s FROM entries");
        $this->assertSame(0, (int) $net->s);

        // Invariant 2: money is conserved. Customers hold exactly what the bank holds as cash.
        $customers = array_sum(array_map(fn ($id) => $this->balance($id), $ids));
        $this->assertSame(300_000, $customers);
        $this->assertSame(300_000, $this->balance($this->cash));

        // Invariant 3: cached balances match the entry history.
        $this->artisan('ledger:reconcile')->assertExitCode(0);
    }

        public function test_reversal_restores_balances_and_links_to_the_original(): void
    {
        $original = $this->deposit($this->alice, 10_000, 'k1');
        $reversal = $this->ledger->reverse($original->transactionId, 'r1', 'customer dispute');

        $this->assertSame(0, $this->balance($this->alice));
        $this->assertSame(0, $this->balance($this->cash));

        $row = DB::table('transactions')->where('id', $reversal->transactionId)->first();
        $this->assertSame('reversal', $row->type);
        $this->assertSame($original->transactionId, $row->reverses_transaction_id);

        // The original is untouched: history is never rewritten.
        $this->assertSame(4, DB::table('entries')->count());
    }

    public function test_a_transaction_can_only_be_reversed_once(): void
    {
        $original = $this->deposit($this->alice, 10_000, 'k1');
        $this->ledger->reverse($original->transactionId, 'r1');

        $this->assertRejected('ALREADY_REVERSED', fn () => $this->ledger->reverse($original->transactionId, 'r2'));
        $this->assertSame(0, $this->balance($this->alice));
    }

    public function test_reversal_retry_with_same_key_replays(): void
    {
        $original = $this->deposit($this->alice, 10_000, 'k1');
        $first = $this->ledger->reverse($original->transactionId, 'r1');
        $second = $this->ledger->reverse($original->transactionId, 'r1');

        $this->assertTrue($second->replayed);
        $this->assertSame($first->transactionId, $second->transactionId);
    }

    public function test_a_reversal_cannot_be_reversed(): void
    {
        $original = $this->deposit($this->alice, 10_000, 'k1');
        $reversal = $this->ledger->reverse($original->transactionId, 'r1');

        $this->assertRejected('CANNOT_REVERSE', fn () => $this->ledger->reverse($reversal->transactionId, 'r2'));
    }

    public function test_reversal_fails_if_the_money_has_already_been_spent(): void
    {
        $original = $this->deposit($this->alice, 10_000, 'k1');
        $this->transfer($this->alice, $this->bob, 9_000, 'k2');

        $this->assertRejected('INSUFFICIENT_FUNDS', fn () => $this->ledger->reverse($original->transactionId, 'r1'));
        $this->assertSame(1_000, $this->balance($this->alice));
    }

    public function test_reversing_an_unknown_transaction_is_rejected(): void
    {
        $this->assertRejected('TRANSACTION_NOT_FOUND', fn () => $this->ledger->reverse('not-a-uuid', 'r1'));
        $this->assertRejected('TRANSACTION_NOT_FOUND', fn () => $this->ledger->reverse('2f6c1f44-9d1a-4a3e-8a1e-0c9f3d5b7e11', 'r2'));
    }
}