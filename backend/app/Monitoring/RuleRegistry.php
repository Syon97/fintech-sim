<?php

namespace App\Monitoring;

use App\Monitoring\Rules\LargeAmountRule;
use App\Monitoring\Rules\Rule;
use App\Monitoring\Rules\VelocityRule;
use InvalidArgumentException;

final class RuleRegistry
{
    /** @var array<string, Rule> */
    private array $rules = [];

    public function __construct()
    {
        foreach ([new LargeAmountRule(), new VelocityRule()] as $rule) {
            $this->rules[$rule->type()] = $rule;
        }
    }

    public function for(string $type): Rule
    {
        return $this->rules[$type]
            ?? throw new InvalidArgumentException("Unknown rule type '$type'. Known: " . implode(', ', array_keys($this->rules)));
    }
}