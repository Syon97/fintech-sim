<?php

namespace App\Ledger\Events;

use Illuminate\Support\Facades\DB;

final class EventBus
{
    /** @var array<string, EventConsumer> */
    private array $consumers = [];

    public function __construct(EventConsumer ...$consumers)
    {
        foreach ($consumers as $c) {
            $this->consumers[$c->name()] = $c;
        }
    }

    /** @return array<string, EventConsumer> */
    public function consumers(): array
    {
        return $this->consumers;
    }

    /** Deliver to every consumer (or just $only). Consumers that already have the event are skipped. */
    public function deliver(LedgerEvent $event, ?string $only = null): void
    {
        foreach ($this->consumers as $name => $consumer) {
            if ($only !== null && $only !== $name) {
                continue;
            }

            // The inbox claim: a duplicate delivery inserts nothing, so the handler is skipped.
            $claimed = DB::table('consumer_inbox')->insertOrIgnore([
                'consumer' => $name,
                'event_id' => $event->id,
            ]);

            if ($claimed === 0) {
                continue;
            }

            $consumer->handle($event);
        }
    }
}