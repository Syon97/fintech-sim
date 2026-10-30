<?php

namespace App\Monitoring;

/** What a rule reports: one suspicious thing, with its explanation and evidence. */
final class Finding
{
    public function __construct(
        public readonly int $accountId,
        public readonly string $accountNumber,
        public readonly string $dedupeKey,     // deterministic: same event + same subject = same key
        public readonly string $explanation,
        public readonly array $details,
    ) {}
}