<?php

namespace App\Ledger\Events;

use Illuminate\Support\Facades\DB;

final class OutboxWriter
{
    public const TRANSACTION_POSTED = 'transaction.posted';
    public const ACCOUNT_FROZEN = 'account.frozen';
    public const ACCOUNT_UNFROZEN = 'account.unfrozen';
    public const SCHEMA_VERSION = 1;

    /**
     * Must be called inside the posting's database transaction. That is the whole point.
     *
     * @param array<int, array<string,mixed>> $entries  rows exactly as inserted into `entries`
     * @param array<int, object> $accounts              locked account rows keyed by id
     */
    public function transactionPosted(
        string $txId,
        string $type,
        string $effectiveDate,
        ?string $description,
        ?string $reverses,
        array $entries,
        array $accounts,
    ): void {
        $payload = [
            'transaction_id' => $txId,
            'type' => $type,
            'effective_date' => $effectiveDate,
            'description' => $description,
            'reverses_transaction_id' => $reverses,
            'entries' => array_map(fn (array $e) => [
                'account_id' => $e['account_id'],
                'account_number' => $accounts[$e['account_id']]->account_number,
                'account_type' => $accounts[$e['account_id']]->account_type,
                'direction' => $e['direction'],
                'amount' => $e['amount'],            // integer sen
                'currency' => $e['currency'],
                'balance_after' => $e['balance_after'],
            ], $entries),
        ];

        DB::table('outbox_events')->insert([
            'event_type' => self::TRANSACTION_POSTED,
            'schema_version' => self::SCHEMA_VERSION,
            'aggregate_type' => 'transaction',
            'aggregate_id' => $txId,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);
    }

    /** Also written inside the status change's own transaction: no change without its event. */
    public function accountStatusChanged(
        string $eventType,
        int $changeId,
        object $account,
        string $from,
        string $to,
        string $actor,
        string $reason,
    ): void {
        $payload = [
            'account_id' => (int) $account->id,
            'account_number' => $account->account_number,
            'from_status' => $from,
            'to_status' => $to,
            'actor' => $actor,
            'reason' => $reason,
            'change_id' => $changeId,
        ];

        DB::table('outbox_events')->insert([
            'event_type' => $eventType,
            'schema_version' => self::SCHEMA_VERSION,
            'aggregate_type' => 'account',
            'aggregate_id' => (string) $account->id,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);
    }
}