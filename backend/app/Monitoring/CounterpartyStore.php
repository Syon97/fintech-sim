<?php

namespace App\Monitoring;

use App\Ledger\Events\LedgerEvent;
use Illuminate\Support\Facades\DB;

/** Who has paid whom, built only from transfer events. */
final class CounterpartyStore
{
    /**
     * A transfer has exactly one debit entry (sender) and one credit entry (beneficiary).
     *
     * @return array{from: array, to: array}|null
     */
    public static function pair(LedgerEvent $event): ?array
    {
        if (($event->payload['type'] ?? null) !== 'transfer') {
            return null;
        }

        $entries = $event->payload['entries'];
        if (count($entries) !== 2) {
            return null;
        }

        $from = $to = null;
        foreach ($entries as $e) {
            $e['direction'] === 'D' ? $from = $e : $to = $e;
        }

        if ($from === null || $to === null || $from['account_id'] === $to['account_id']) {
            return null;
        }

        return ['from' => $from, 'to' => $to];
    }

    public function record(LedgerEvent $event): void
    {
        $pair = self::pair($event);
        if ($pair === null) {
            return;
        }

        // Keeps the EARLIEST position seen, so out-of-order delivery still ends up correct.
        // Replays change nothing: the stored position is already the earliest.
        DB::statement(
            'INSERT INTO monitoring_counterparties
                 (account_id, account_number, counterparty_account_id, counterparty_account_number, first_seen_event_id, first_seen_at)
             VALUES (?, ?, ?, ?, ?, ?)
             ON CONFLICT (account_id, counterparty_account_id) DO UPDATE
                SET first_seen_event_id = EXCLUDED.first_seen_event_id,
                    first_seen_at = EXCLUDED.first_seen_at
              WHERE (EXCLUDED.first_seen_at, EXCLUDED.first_seen_event_id)
                  < (monitoring_counterparties.first_seen_at, monitoring_counterparties.first_seen_event_id)',
            [
                $pair['from']['account_id'], $pair['from']['account_number'],
                $pair['to']['account_id'], $pair['to']['account_number'],
                $event->id, $event->occurredAt,
            ],
        );
    }
}