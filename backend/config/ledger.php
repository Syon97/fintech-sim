<?php

// use App\Monitoring\MonitoringConsumer;

return [
    // Consumers that receive every ledger event. Monitoring is added here.
    'consumers' => [
        App\Ledger\Events\ProbeConsumer::class,
        App\Monitoring\MonitoringConsumer::class,
    ],
];