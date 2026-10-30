<?php

namespace App\Monitoring;

use App\Ledger\Events\LedgerEvent;
use Illuminate\Support\Facades\DB;

/** Monitoring's own history, built only from events. insertOrIgnore makes replays harmless. */
final class ActivityStore
{
    public function record(LedgerEvent $event): void
    {
        $rows = [];
        foreach ($event->payload['entries'] as $i => $e) {
            $rows[] = [
                'event_id' => $event->id,
                'entry_index' => $i,
                'transaction_id' => $event->aggregateId,
                'account_id' => $e['account_id'],
                'account_number' => $e['account_number'],
                'account_type' => $e['account_type'],
                'direction' => $e['direction'],
                'amount' => $e['amount'],
                'currency' => $e['currency'],
                'occurred_at' => $event->occurredAt,
            ];
        }

        DB::table('monitoring_activity')->insertOrIgnore($rows);
    }
}