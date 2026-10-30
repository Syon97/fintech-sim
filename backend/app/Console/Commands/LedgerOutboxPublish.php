<?php

namespace App\Console\Commands;

use App\Ledger\Events\EventBus;
use App\Ledger\Events\OutboxPublisher;
use Illuminate\Console\Command;

class LedgerOutboxPublish extends Command
{
    protected $signature = 'ledger:outbox-publish
                            {--drain : Exit as soon as there is nothing left to publish}
                            {--batch=100}
                            {--sleep=200 : Milliseconds to wait when idle}
                            {--stop-file= : Exit after the backlog is drained once this file exists}';
    protected $description = 'Deliver unpublished outbox events to consumers';

    public function handle(OutboxPublisher $publisher, EventBus $bus): int
    {

        if ($bus->consumers() === []) {
            $this->error('No consumers registered (check config/ledger.php). Refusing to mark events as published.');
            return self::FAILURE;
        }
        
        $drain = (bool) $this->option('drain');
        $batch = (int) $this->option('batch');
        $sleepMs = (int) $this->option('sleep');
        $stopFile = (string) ($this->option('stop-file') ?? '');
        $total = 0;

        while (true) {
            // Check BEFORE the batch, so a stop request still gets one final drain pass.
            $stopRequested = $stopFile !== '' && file_exists($stopFile);

            $r = $publisher->publishBatch($batch);
            $total += $r['published'];

            if ($r['published'] + $r['failed'] === 0) {
                if ($drain || $stopRequested) {
                    break;
                }
                usleep($sleepMs * 1000);
            }
        }

        $this->line("PUBLISHED $total");

        return self::SUCCESS;
    }
}