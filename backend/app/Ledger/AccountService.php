<?php

namespace App\Ledger;

use App\Ledger\Events\OutboxWriter;
use App\Ledger\Exceptions\LedgerException;
use Illuminate\Support\Facades\DB;

final class AccountService
{
    public function __construct(private readonly OutboxWriter $outbox) {}

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

    /** Stop postings to an account. Recorded with who, why and when, and announced to consumers. */
    public function freeze(int $accountId, string $actor, string $reason): void
    {
        $this->changeStatus($accountId, 'frozen', $actor, $reason);
    }

    /** Lift a freeze. A freeze with no way back would be a trap. */
    public function unfreeze(int $accountId, string $actor, string $reason): void
    {
        $this->changeStatus($accountId, 'active', $actor, $reason);
    }

    /**
     * Current status of each account, for screens. A plain read through the ledger's own API.
     *
     * @param int[] $ids
     * @return array<int, string>
     */
    public function statuses(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('accounts')->whereIn('id', $ids)->pluck('status', 'id')
            ->map(fn ($s) => (string) $s)->all();
    }

    private function changeStatus(int $accountId, string $to, string $actor, string $reason): void
    {
        $actor = trim($actor);
        $reason = trim($reason);
        if ($actor === '' || strlen($actor) > 64) {
            throw LedgerException::invalid('actor must be 1 to 64 characters');
        }
        if ($reason === '') {
            throw LedgerException::invalid("A reason is required to change an account's status");
        }

        DB::transaction(function () use ($accountId, $to, $actor, $reason) {
            // The same row locks a posting takes: a freeze and a posting on this account are serialized.
            $account = DB::selectOne(
                'SELECT a.id, a.account_number, a.status
                   FROM account_balances b
                   JOIN accounts a ON a.id = b.account_id
                  WHERE b.account_id = ?
                    FOR UPDATE',
                [$accountId],
            );

            if ($account === null) {
                throw LedgerException::unknownAccount($accountId);
            }
            if ($account->status === 'closed') {
                throw LedgerException::accountClosed($accountId);
            }
            if ($account->status === $to) {
                if ($to === 'frozen') {
                    throw LedgerException::alreadyFrozen($accountId);
                }
                throw LedgerException::notFrozen($accountId);
            }

            DB::table('accounts')->where('id', $accountId)
                ->update(['status' => $to, 'updated_at' => DB::raw('now()')]);

            $changeId = (int) DB::table('account_status_changes')->insertGetId([
                'account_id' => $accountId,
                'from_status' => $account->status,
                'to_status' => $to,
                'actor' => $actor,
                'reason' => $reason,
            ]);

            $this->outbox->accountStatusChanged(
                $to === 'frozen' ? OutboxWriter::ACCOUNT_FROZEN : OutboxWriter::ACCOUNT_UNFROZEN,
                $changeId, $account, $account->status, $to, $actor, $reason,
            );
        });
    }
}