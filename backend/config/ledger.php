<?php

return [
    // Consumers that receive every ledger event. Monitoring is added here in Phase 3.
    'consumers' => [
        App\Ledger\Events\ProbeConsumer::class,
    ],
];