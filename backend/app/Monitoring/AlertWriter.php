<?php

namespace App\Monitoring;

use App\Ledger\Events\LedgerEvent;
use Illuminate\Support\Facades\DB;

final class AlertWriter
{
    public function __construct(
        private readonly RiskScorer $scorer,
        private readonly AlertGrouper $grouper,
    ) {}

    /** @return bool true if a new alert was created, false if it already existed */
    public function write(RuleVersion $version, LedgerEvent $event, Finding $finding): bool
    {
        // Replays and duplicate deliveries stop here, BEFORE any score or group counter
        // is touched. Safe because the caller holds the per-account advisory lock.
        $exists = DB::table('monitoring_alerts')
            ->where('rule_version_id', $version->id)
            ->where('dedupe_key', $finding->dedupeKey)
            ->exists();
        if ($exists) {
            return false;
        }

        $scored = $this->scorer->score($version, $event, $finding);
        $groupId = $this->grouper->assign($finding->accountId, $finding->accountNumber, $event->occurredAt, $scored['score']);

        // A plain insert on purpose: the unique index is the last line of defence, and a
        // violation rolls back the whole event, so the group counter can never drift.
        DB::table('monitoring_alerts')->insert([
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
            'risk_score' => $scored['score'],
            'score_breakdown' => json_encode($scored['breakdown'], JSON_THROW_ON_ERROR),
            'group_id' => $groupId,
        ]);

        return true;
    }
}