<?php

namespace App\Ledger\Events;

/**
 * Does nothing on purpose. Its inbox rows are delivery receipts, which the tests
 * and the stress test use to prove every event was delivered. The monitoring
 * engine will be registered next to it in Phase 3.
 */
final class ProbeConsumer implements EventConsumer
{
    public function name(): string { return 'probe'; }

    public function handle(LedgerEvent $event): void {}
}