<?php

namespace App\Console\Commands;

use App\Ledger\Events\EventBus;
use App\Ledger\Events\LedgerEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class LedgerOutboxReplay extends Command
{
    protected $signature = 'ledger:outbox-replay {consumer} {--from-id=1} {--to-id=} {--batch=200}';
    protected $description = 'Re-deliver historical events to ONE consumer, in id order (to rebuild its state)';

    public function handle(EventBus $bus): int
    {
        $name = (string) $this->argument('consumer');
        if (! isset($bus->consumers()[$name])) {
            $this->error("Unknown consumer '$name'. Known: " . implode(', ', array_keys($bus->consumers())));
            return self::FAILURE;
        }

        $cursor = (int) $this->option('from-id') - 1;
        $to = $this->option('to-id') !== null ? (int) $this->option('to-id') : PHP_INT_MAX;
        $batch = (int) $this->option('batch');
        $count = 0;

        do {
            $n = DB::transaction(function () use ($bus, $name, $to, $batch, &$cursor, &$count) {
                $rows = DB::select(
                    'SELECT id, event_id, event_type, schema_version, aggregate_type, aggregate_id, payload, occurred_at
                       FROM outbox_events WHERE id > ? AND id <= ? ORDER BY id LIMIT ?',
                    [$cursor, $to, $batch],
                );

                foreach ($rows as $row) {
                    // Forget that this consumer saw the event, then deliver it again.
                    DB::table('consumer_inbox')->where('consumer', $name)->where('event_id', $row->id)->delete();
                    $bus->deliver(LedgerEvent::fromRow($row), $name);
                    $cursor = (int) $row->id;
                    $count++;
                }

                return count($rows);
            });
        } while ($n === $batch);

        $this->info("Replayed $count events to '$name'");

        return self::SUCCESS;
    }
}