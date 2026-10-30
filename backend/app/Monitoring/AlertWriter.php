<?php

namespace App\Monitoring;

use App\Ledger\Events\LedgerEvent;
use Illuminate\Support\Facades\DB;

final class AlertWriter
{
    /** @return bool true if a new alert was created, false if it already existed */
    public function write(RuleVersion $version, LedgerEvent $event, Finding $finding): bool
    {
        return DB::table('monitoring_alerts')->insertOrIgnore([
            'rule_version_id' => $version->id,
            'dedupe_key' => $finding->dedupeKey,
            'event_id' => $event->id,
            'transaction_id' => $event->aggregateId,
            'account_id' => $finding->accountId,
            'account_number' => $finding->accountNumber,
            'severity' => $version->severity,
            'explanation' => $finding->explanation,
            'details' => json_encode($finding->details, JSON_THROW_ON_ERROR),
            'event_occurred_at' => $event->occurredAt,
        ]) === 1;
    }
}