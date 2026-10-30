<?php

namespace App\Ledger\Events;

/** The envelope a consumer receives. */
final class LedgerEvent
{
    public function __construct(
        public readonly int $id,            // outbox sequence id (internal ordering/replay cursor)
        public readonly string $eventId,    // stable public id
        public readonly string $type,
        public readonly int $schemaVersion,
        public readonly string $aggregateId,
        public readonly string $occurredAt,
        public readonly array $payload,
    ) {}

    public static function fromRow(object $row): self
    {
        return new self(
            (int) $row->id,
            $row->event_id,
            $row->event_type,
            (int) $row->schema_version,
            $row->aggregate_id,
            $row->occurred_at,
            json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR),
        );
    }
}