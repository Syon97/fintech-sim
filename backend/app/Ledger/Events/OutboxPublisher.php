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
        $published = 0;
        $failed = 0;
        $cursor = 0; // a failed event is not retried again within the same batch

        for ($i = 0; $i < $limit; $i++) {
            $currentId = null;

            try {
                $result = DB::transaction(function () use (&$cursor, &$currentId, $maxAttempts) {
                    $row = DB::selectOne(
                        'SELECT id, event_id, event_type, schema_version, aggregate_type, aggregate_id, payload, occurred_at
                           FROM outbox_events
                          WHERE published_at IS NULL AND attempts < ? AND id > ?
                          ORDER BY id
                          LIMIT 1
                            FOR UPDATE SKIP LOCKED',
                        [$maxAttempts, $cursor],
                    );

                    if ($row === null) {
                        return null;
                    }
                    $cursor = (int) $row->id;
                    $currentId = (int) $row->id;

                    DB::transaction(fn () => $this->bus->deliver(LedgerEvent::fromRow($row)));
                    DB::update('UPDATE outbox_events SET published_at = clock_timestamp() WHERE id = ?', [$row->id]);

                    return 'published';
                });
            } catch (Throwable $e) {
                if ($currentId === null) {
                    throw $e; // failed before an event was picked: not a delivery failure
                }

                // The event's transaction has rolled back (including a deadlock abort),
                // so this bookkeeping runs in a fresh, healthy transaction.
                DB::update(
                    'UPDATE outbox_events SET attempts = attempts + 1, last_error = ? WHERE id = ?',
                    [substr(class_basename($e) . ': ' . $e->getMessage(), 0, 500), $currentId],
                );
                $result = 'failed';
            }

            if ($result === null) {
                break;
            }
            $result === 'published' ? $published++ : $failed++;
        }

        return ['published' => $published, 'failed' => $failed];
    }
}