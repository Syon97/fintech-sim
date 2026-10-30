<?php

namespace App\Ledger\Events;

interface EventConsumer
{
    /** Stable identifier. It is the key in consumer_inbox, so never rename it casually. */
    public function name(): string;

    /**
     * Runs inside the publisher's database transaction (savepoint). Writes made here
     * commit together with the inbox row, or roll back together with it.
     */
    public function handle(LedgerEvent $event): void;
}