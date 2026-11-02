<?php

namespace App\Monitoring;

use RuntimeException;

final class AlertException extends RuntimeException
{
    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function notFound(int $id): self { return new self('ALERT_NOT_FOUND', "Alert $id does not exist"); }
    public static function illegalTransition(int $id, string $from, string $to): self { return new self('ILLEGAL_TRANSITION', "Alert $id cannot move from $from to $to"); }
    public static function notAssignee(int $id, string $actor): self { return new self('NOT_ASSIGNEE', "'$actor' is not the assignee of alert $id"); }
    public static function invalidInput(string $m): self { return new self('INVALID_INPUT', $m); }
}