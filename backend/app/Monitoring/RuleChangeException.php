<?php

namespace App\Monitoring;

use RuntimeException;

final class RuleChangeException extends RuntimeException
{
    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function notFound(int $id): self { return new self('REQUEST_NOT_FOUND', "Change request $id does not exist"); }
    public static function invalid(string $m): self { return new self('INVALID_INPUT', $m); }
    public static function invalidRule(string $m): self { return new self('INVALID_RULE', $m); }
    public static function selfApproval(int $id): self { return new self('SELF_APPROVAL', "Change request $id cannot be decided by the person who proposed it"); }
    public static function alreadyDecided(int $id, string $status): self { return new self('ALREADY_DECIDED', "Change request $id is already $status"); }
    public static function notMaker(int $id): self { return new self('NOT_MAKER', "Only the proposer can withdraw change request $id"); }
    public static function pendingExists(string $code): self { return new self('PENDING_EXISTS', "Rule '$code' already has a pending change request"); }
    public static function unknownRule(string $code): self { return new self('UNKNOWN_RULE', "Rule '$code' does not exist"); }
    public static function typeMismatch(string $code, string $existing, string $given): self { return new self('TYPE_MISMATCH', "Rule '$code' is of type '$existing' and cannot become '$given'"); }
    public static function nothingToRetire(string $code): self { return new self('NOTHING_TO_RETIRE', "Rule '$code' has no active version to retire"); }
}