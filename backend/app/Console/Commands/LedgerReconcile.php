<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class LedgerReconcile extends Command
{
    protected $signature = 'ledger:reconcile';
    protected $description = 'Prove that entries, balances and balance history agree';

    public function handle(): int
    {
        $signed = "CASE WHEN e.direction = a.normal_side THEN e.amount ELSE -e.amount END";

        // 1. Every transaction balances (debits = credits).
        $unbalanced = DB::select("
            SELECT transaction_id FROM entries
            GROUP BY transaction_id
            HAVING SUM(CASE WHEN direction = 'D' THEN amount ELSE -amount END) <> 0");

        // 2. Cached balance equals the balance recomputed from entries.
        $drift = DB::select("
            SELECT a.account_number, b.balance AS cached, COALESCE(SUM($signed), 0) AS computed
              FROM accounts a
              JOIN account_balances b ON b.account_id = a.id
              LEFT JOIN entries e ON e.account_id = a.id
             GROUP BY a.account_number, b.balance
            HAVING b.balance <> COALESCE(SUM($signed), 0)");

        // 3. The last entry's balance_after equals the cached balance.
        $chain = DB::select("
            SELECT a.account_number, b.balance AS cached, l.balance_after
              FROM account_balances b
              JOIN accounts a ON a.id = b.account_id
              JOIN LATERAL (
                    SELECT balance_after FROM entries
                     WHERE account_id = b.account_id ORDER BY id DESC LIMIT 1
              ) l ON true
             WHERE l.balance_after <> b.balance");

        $this->report('Unbalanced transactions', $unbalanced);
        $this->report('Balance drift (cached vs entries)', $drift);
        $this->report('Balance chain mismatch', $chain);

        $failed = count($unbalanced) + count($drift) + count($chain) > 0;
        $failed ? $this->error('RECONCILIATION FAILED') : $this->info('Reconciliation OK');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function report(string $label, array $rows): void
    {
        if ($rows === []) {
            $this->line("  [ok]   $label");
            return;
        }
        $this->line("  [FAIL] $label: " . count($rows));
        foreach ($rows as $r) {
            $this->line('         ' . json_encode($r));
        }
    }
}