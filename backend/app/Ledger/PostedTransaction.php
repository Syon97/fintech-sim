<?php

namespace App\Ledger;

final class PostedTransaction
{
    public function __construct(
        public readonly string $transactionId,
        public readonly bool $replayed,
    ) {}
}