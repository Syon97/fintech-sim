<?php

namespace App\Ledger;

use App\Ledger\Exceptions\LedgerException;
use App\Ledger\Events\OutboxWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class LedgerService
{
    public function __construct(private readonly OutboxWriter $outbox) {}
    private const SCOPE = 'ledger.post';
    private const TYPES = ['deposit', 'withdrawal', 'transfer', 'fee']; // reversals go through reverse()

    /**
     * @param array<int, array{account_id:int, direction:string, amount:int}> $lines
     */
    public function post(
        string $type,
        array $lines,
        string $idempotencyKey,
        ?string $description = null,
        ?string $effectiveDate = null,
    ): PostedTransaction {
        if (! in_array($type, self::TYPES, true)) {
            throw LedgerException::invalid("Unsupported transaction type '$type'");
        }

        return $this->execute($type, $lines, $idempotencyKey, $description, $effectiveDate, null);
    }

    public function reverse(string $transactionId, string $idempotencyKey, ?string $reason = null): PostedTransaction
    {
        if (! Str::isUuid($transactionId)) {
            throw LedgerException::transactionNotFound($transactionId);
        }

        $original = DB::table('transactions')->where('id', $transactionId)->first();
        if ($original === null) {
            throw LedgerException::transactionNotFound($transactionId);
        }
        if ($original->type === 'reversal') {
            throw LedgerException::cannotReverse($transactionId, 'it is itself a reversal; post a new transaction instead');
        }

        // Entries are immutable, so reading them outside the posting transaction is safe.
        $lines = DB::table('entries')
            ->where('transaction_id', $transactionId)
            ->orderBy('id')
            ->get()
            ->map(fn ($e) => [
                'account_id' => (int) $e->account_id,
                'direction' => $e->direction === 'D' ? 'C' : 'D',
                'amount' => (int) $e->amount,
            ])
            ->all();

        $description = "Reversal of $transactionId" . ($reason !== null ? ": $reason" : '');

        return $this->execute('reversal', $lines, $idempotencyKey, $description, null, $transactionId);
    }

    private function execute(
        string $type,
        array $lines,
        string $idempotencyKey,
        ?string $description,
        ?string $effectiveDate,
        ?string $reverses,
    ): PostedTransaction {
        $lines = $this->normalise($lines);
        $effectiveDate ??= today()->toDateString();
        $hash = $this->requestHash($type, $lines, $description, $effectiveDate, $reverses);

        return DB::transaction(function () use ($type, $lines, $idempotencyKey, $description, $effectiveDate, $hash, $reverses) {
            // 1. Claim the idempotency key (waits if another open transaction holds it).
            $claimed = DB::table('idempotency_keys')->insertOrIgnore([
                'scope' => self::SCOPE,
                'key' => $idempotencyKey,
                'request_hash' => $hash,
            ]);

            if ($claimed === 0) {
                return $this->replay($idempotencyKey, $hash);
            }

            // 2. Lock every affected account in ascending id order (prevents deadlocks).
            $accounts = $this->lockAccounts(array_values(array_unique(array_column($lines, 'account_id'))));
            $this->assertPostable($lines, $accounts);

            // 3. Reversal guard. This runs AFTER the locks on purpose: two concurrent
            //    reversals of the same transaction touch the same accounts, so the second
            //    one waits here and then sees the first one's committed reversal.
            if ($reverses !== null
                && DB::table('transactions')->where('reverses_transaction_id', $reverses)->exists()) {
                throw LedgerException::alreadyReversed($reverses);
            }

            // 4. Transaction header.
            $txId = (string) Str::uuid();
            DB::table('transactions')->insert([
                'id' => $txId,
                'type' => $type,
                'description' => $description,
                'effective_date' => $effectiveDate,
                'reverses_transaction_id' => $reverses,
            ]);

            // 5. Entries with a running balance per account.
            $balances = [];
            foreach ($accounts as $id => $acct) {
                $balances[$id] = (int) $acct->balance;
            }

            $entries = [];
            foreach ($lines as $line) {
                $acct = $accounts[$line['account_id']];
                $delta = $line['direction'] === $acct->normal_side ? $line['amount'] : -$line['amount'];
                $balances[$line['account_id']] += $delta;

                $entries[] = [
                    'transaction_id' => $txId,
                    'account_id' => $line['account_id'],
                    'direction' => $line['direction'],
                    'amount' => $line['amount'],
                    'currency' => $acct->currency,
                    'balance_after' => $balances[$line['account_id']],
                ];
            }

            // 6. Overdraft check on the FINAL balance per account.
            foreach ($balances as $id => $final) {
                if ($final < 0 && ! $accounts[$id]->allow_negative) {
                    throw LedgerException::insufficientFunds($id);
                }
            }

            // 7a. Persist entries and balances in the same transaction.
            DB::table('entries')->insert($entries);
            foreach ($balances as $id => $final) {
                DB::update(
                    'UPDATE account_balances SET balance = ?, version = version + 1, updated_at = now() WHERE account_id = ?',
                    [$final, $id],
                );
            }

            // 7b. Outbox: the event commits with the posting, or not at all.
            $this->outbox->transactionPosted($txId, $type, $effectiveDate, $description, $reverses, $entries, $accounts);

            // 8. Record the outcome against the idempotency key.
            DB::table('idempotency_keys')
                ->where('scope', self::SCOPE)->where('key', $idempotencyKey)
                ->update([
                    'transaction_id' => $txId,
                    'response' => json_encode(['transaction_id' => $txId]),
                ]);

            return new PostedTransaction($txId, false);
        });
    }

    private function normalise(array $lines): array
    {
        if (count($lines) < 2) {
            throw LedgerException::invalid('A posting needs at least two lines');
        }

        $out = [];
        $debits = 0;
        $credits = 0;

        foreach ($lines as $line) {
            $acct = $line['account_id'] ?? null;
            $dir = $line['direction'] ?? null;
            $amount = $line['amount'] ?? null;

            if (! is_int($acct)) throw LedgerException::invalid('account_id must be an integer');
            if (! in_array($dir, ['D', 'C'], true)) throw LedgerException::invalid("direction must be 'D' or 'C'");
            if (! is_int($amount) || $amount <= 0) throw LedgerException::invalid('amount must be a positive integer (minor units)');

            $dir === 'D' ? $debits += $amount : $credits += $amount;
            $out[] = ['account_id' => $acct, 'direction' => $dir, 'amount' => $amount];
        }

        if ($debits !== $credits) {
            throw LedgerException::invalid("Unbalanced posting: debits=$debits credits=$credits");
        }

        return $out;
    }

    private function requestHash(string $type, array $lines, ?string $description, string $date, ?string $reverses): string
    {
        usort($lines, fn ($a, $b) => [$a['account_id'], $a['direction'], $a['amount']]
            <=> [$b['account_id'], $b['direction'], $b['amount']]);

        return hash('sha256', json_encode([$type, $lines, $description, $date, $reverses]));
    }

    private function replay(string $key, string $hash): PostedTransaction
    {
        $row = DB::table('idempotency_keys')->where('scope', self::SCOPE)->where('key', $key)->first();

        if ($row->request_hash !== $hash) {
            throw LedgerException::idempotencyMismatch($key);
        }
        if ($row->transaction_id === null) {
            throw LedgerException::inProgress($key);
        }

        return new PostedTransaction($row->transaction_id, true);
    }

    /** @return array<int, object> keyed by account id */
    private function lockAccounts(array $ids): array
    {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $rows = DB::select(
            "SELECT b.account_id, b.balance, a.account_number, a.type AS account_type, a.normal_side, a.currency, a.status, a.allow_negative
               FROM account_balances b
               JOIN accounts a ON a.id = b.account_id
              WHERE b.account_id IN ($placeholders)
              ORDER BY b.account_id
                FOR UPDATE",
            $ids,
        );

        return collect($rows)->keyBy('account_id')->all();
    }

    private function assertPostable(array $lines, array $accounts): void
    {
        $currencies = [];

        foreach (array_unique(array_column($lines, 'account_id')) as $id) {
            if (! isset($accounts[$id])) throw LedgerException::unknownAccount($id);
            if ($accounts[$id]->status !== 'active') throw LedgerException::notPostable($id, $accounts[$id]->status);
            $currencies[$accounts[$id]->currency] = true;
        }

        if (count($currencies) > 1) {
            throw LedgerException::invalid('Multi-currency postings are out of scope');
        }
    }
}