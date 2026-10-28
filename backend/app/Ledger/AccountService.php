<?php

namespace App\Ledger;

use Illuminate\Support\Facades\DB;

final class AccountService
{
    public function open(
        string $number,
        string $name,
        string $type,
        string $currency = 'MYR',
        bool $allowNegative = false,
    ): int {
        // Normal side is derived from the type, never passed in by the caller.
        $normalSide = in_array($type, ['asset', 'expense'], true) ? 'D' : 'C';

        return DB::transaction(function () use ($number, $name, $type, $normalSide, $currency, $allowNegative) {
            $id = DB::table('accounts')->insertGetId([
                'account_number' => $number,
                'name' => $name,
                'type' => $type,
                'normal_side' => $normalSide,
                'currency' => $currency,
                'allow_negative' => $allowNegative,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('account_balances')->insert(['account_id' => $id]);

            return $id;
        });
    }

    public function freeze(int $accountId): void
    {
        DB::table('accounts')->where('id', $accountId)
            ->update(['status' => 'frozen', 'updated_at' => now()]);
    }
}