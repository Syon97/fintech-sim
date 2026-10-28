<?php

namespace Tests\Feature\Ledger;

use App\Ledger\AccountService;
use App\Ledger\LedgerService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDOException;
use Tests\TestCase;

class CommitBehaviourTest extends TestCase
{
    use DatabaseMigrations; // real commits; migrates fresh before each test and rolls back after

    public function test_unbalanced_entries_cannot_be_committed(): void
    {
        $accounts = app(AccountService::class);
        $a = $accounts->open('A1', 'A1', 'liability');
        $b = $accounts->open('B1', 'B1', 'liability');
        $tx = (string) Str::uuid();

        $row = fn (int $acct, string $dir, int $amt) => [
            'transaction_id' => $tx, 'account_id' => $acct, 'direction' => $dir,
            'amount' => $amt, 'currency' => 'MYR', 'balance_after' => 0,
        ];

        try {
            DB::transaction(function () use ($tx, $a, $b, $row) {
                DB::table('transactions')->insert(['id' => $tx, 'type' => 'transfer', 'effective_date' => today()]);
                DB::table('entries')->insert([$row($a, 'D', 1000), $row($b, 'C', 900)]);
            }); // <- the deferred trigger fires here, at COMMIT
            $this->fail('An unbalanced transaction was committed');
        } catch (PDOException $e) {
            $this->assertStringContainsString('unbalanced', $e->getMessage());
        }

        // The whole transaction rolled back: nothing partial survives.
        $this->assertSame(0, DB::table('entries')->count());
        $this->assertSame(0, DB::table('transactions')->count());
    }

    public function test_a_real_posting_is_durable_and_reconciles(): void
    {
        $accounts = app(AccountService::class);
        $cash = $accounts->open('GL-CASH', 'Cash', 'asset', 'MYR', true);
        $alice = $accounts->open('C-ALICE', 'Alice', 'liability');

        app(LedgerService::class)->post('deposit', [
            ['account_id' => $cash, 'direction' => 'D', 'amount' => 5_000],
            ['account_id' => $alice, 'direction' => 'C', 'amount' => 5_000],
        ], 'k1');

        DB::reconnect(); // fresh connection: only committed data is visible

        $this->assertSame(5_000, (int) DB::table('account_balances')->where('account_id', $alice)->value('balance'));
        $this->artisan('ledger:reconcile')->assertExitCode(0);
    }
}