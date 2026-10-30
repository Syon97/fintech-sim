<?php

namespace App\Ledger\Events;

use Illuminate\Support\Facades\DB;
use Throwable;

final class OutboxPublisher
{
    public function __construct(private readonly EventBus $bus) {}

    /** @return array{published:int, failed:int} */
    public function publishBatch(int $limit = 100, int $maxAttempts = 5): array
    {
        return DB::transaction(function () use ($limit, $maxAttempts) {
            // SKIP LOCKED: concurrent publishers take different rows instead of waiting
            // for each other, and no row is ever delivered by two publishers at once.
            $rows = DB::select(
                'SELECT id, event_id, event_type, schema_version, aggregate_type, aggregate_id, payload, occurred_at
                   FROM outbox_events
                  WHERE published_at IS NULL AND attempts < ?
                  ORDER BY id
                  LIMIT ?
                    FOR UPDATE SKIP LOCKED
                    ',
                [$maxAttempts, $limit],
            );

            $published = 0;
            $failed = 0;

            foreach ($rows as $row) {
                try {
                    // Nested transaction = savepoint: a failing consumer undos only its own writes.
                    DB::transaction(fn () => $this->bus->deliver(LedgerEvent::fromRow($row)));

                    DB::update('UPDATE outbox_events SET published_at = clock_timestamp() WHERE id = ?', [$row->id]);
                    $published++;
                } catch (Throwable $e) {
                    DB::update(
                        'UPDATE outbox_events SET attempts = attempts + 1, last_error = ? WHERE id = ?',
                        [substr(class_basename($e) . ': ' . $e->getMessage(), 0, 500), $row->id],
                    );
                    $failed++;
                }
            }

            return ['published' => $published, 'failed' => $failed];
        });
    }
}