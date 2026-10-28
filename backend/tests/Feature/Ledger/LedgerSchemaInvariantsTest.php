<?php

namespace Tests\Feature\Ledger;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class LedgerSchemaInvariantsTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $number, string $ccy = 'MYR'): int
    {
        return DB::table('accounts')->insertGetId([
            'account_number' => $number,
            'name' => $number,
            'type' => 'liability',
            'normal_side' => 'C',
            'currency' => $ccy,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function transaction(): string
    {
        $id = (string) Str::uuid();
        DB::table('transactions')->insert([
            'id' => $id, 'type' => 'transfer',
            'effective_date' => today(), 'created_at' => now(),
        ]);
        return $id;
    }

    private function entry(string $tx, int $acct, string $dir, int $amount, string $ccy = 'MYR'): array
    {
        return [
            'transaction_id' => $tx, 'account_id' => $acct, 'direction' => $dir,
            'amount' => $amount, 'currency' => $ccy, 'balance_after' => 0,
            'created_at' => now(),
        ];
    }

    public function test_balanced_transaction_is_accepted(): void
    {
        $a = $this->account('A1'); $b = $this->account('B1');
        $tx = $this->transaction();

        DB::table('entries')->insert([
            $this->entry($tx, $a, 'D', 1000),
            $this->entry($tx, $b, 'C', 1000),
        ]);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE'); // would throw if unbalanced

        $this->assertSame(2, DB::table('entries')->count());
    }

    public function test_unbalanced_transaction_is_rejected(): void
    {
        $a = $this->account('A1'); $b = $this->account('B1');
        $tx = $this->transaction();

        DB::table('entries')->insert([
            $this->entry($tx, $a, 'D', 1000),
            $this->entry($tx, $b, 'C', 900),
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('unbalanced');
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_entries_are_append_only(): void
    {
        $a = $this->account('A1'); $b = $this->account('B1');
        $tx = $this->transaction();
        DB::table('entries')->insert([
            $this->entry($tx, $a, 'D', 1000),
            $this->entry($tx, $b, 'C', 1000),
        ]);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('append-only');
        DB::table('entries')->where('transaction_id', $tx)->update(['amount' => 1]);
    }

    public function test_entry_amount_must_be_positive(): void
    {
        $a = $this->account('A1');
        $tx = $this->transaction();

        $this->expectException(QueryException::class);
        DB::table('entries')->insert($this->entry($tx, $a, 'D', 0));
    }

    public function test_entry_currency_must_match_account_currency(): void
    {
        $a = $this->account('A1', 'MYR');
        $tx = $this->transaction();

        $this->expectException(QueryException::class);
        DB::table('entries')->insert($this->entry($tx, $a, 'D', 1000, 'USD'));
    }
}