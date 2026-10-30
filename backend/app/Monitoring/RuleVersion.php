<?php

namespace App\Monitoring;

final class RuleVersion
{
    public function __construct(
        public readonly int $id,
        public readonly string $code,
        public readonly string $type,
        public readonly int $version,
        public readonly string $severity,
        public readonly array $parameters,
    ) {}

    public static function fromRow(object $r): self
    {
        return new self(
            (int) $r->id,
            $r->code,
            $r->type,
            (int) $r->version,
            $r->severity,
            json_decode($r->parameters, true, 512, JSON_THROW_ON_ERROR),
        );
    }
}