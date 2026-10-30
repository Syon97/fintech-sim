<?php

namespace App\Monitoring;

use App\Ledger\Events\EventConsumer;
use App\Ledger\Events\LedgerEvent;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class MonitoringConsumer implements EventConsumer
{
    private const LOCK_NAMESPACE = 7001;

    public function __construct(
        private readonly ActivityStore $activity,
        private readonly RuleRepository $rules,
        private readonly RuleRegistry $registry,
        private readonly AlertWriter $alerts,
    ) {}

    public function name(): string
    {
        return 'monitoring';
    }

    public function handle(LedgerEvent $event): void
    {
        if ($event->type !== 'transaction.posted') {
            return;
        }
        if ($event->schemaVersion !== 1) {
            // Fail loudly: the event is quarantined, not silently misread.
            throw new RuntimeException("Unsupported transaction.posted schema version {$event->schemaVersion}");
        }

        // Evaluate one event per account at a time, in ascending account id order
        // (same rule as the ledger: no deadlocks). The lock is released when the
        // publisher's per-event transaction ends.
        $ids = array_values(array_unique(array_column($event->payload['entries'], 'account_id')));
        sort($ids);

        foreach ($ids as $id) {
            DB::select('SELECT pg_advisory_xact_lock(?::int, ?::int)', [self::LOCK_NAMESPACE, $id]);
        }

        $this->activity->record($event);

        foreach ($this->rules->activeVersions() as $version) {
            $rule = $this->registry->for($version->type);
            foreach ($rule->evaluate($event, $version) as $finding) {
                $this->alerts->write($version, $event, $finding);
            }
        }
    }
}