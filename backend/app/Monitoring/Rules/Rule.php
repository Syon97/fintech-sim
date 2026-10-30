<?php

namespace App\Monitoring\Rules;

use App\Ledger\Events\LedgerEvent;
use App\Monitoring\Finding;
use App\Monitoring\RuleVersion;

interface Rule
{
    public function type(): string;

    /** Throws InvalidArgumentException if the parameters are not usable. */
    public function validate(array $parameters): void;

    /** @return Finding[] */
    public function evaluate(LedgerEvent $event, RuleVersion $rule): array;
}