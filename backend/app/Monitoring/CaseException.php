<?php

namespace App\Monitoring;

use RuntimeException;

/** Messages are written to be shown to people as they are. */
final class CaseException extends RuntimeException
{
    private function __construct(public readonly string $reason, string $message, public readonly array $context = [])
    {
        parent::__construct($message);
    }

    public static function notFound(int $id): self { return new self('CASE_NOT_FOUND', "We couldn't find that case."); }
    public static function groupNotFound(int $id): self { return new self('GROUP_NOT_FOUND', "We couldn't find that group of alerts."); }
    public static function caseExists(int $existingId): self { return new self('CASE_EXISTS', 'This group already has a case.', ['case_id' => $existingId]); }
    public static function closed(int $id): self { return new self('CASE_CLOSED', 'This case is closed and can no longer be changed.'); }
    public static function notOwner(int $id): self { return new self('NOT_OWNER', 'Only the case owner can do that. Assign the case to yourself first if you are taking over.'); }
    public static function invalid(string $message): self { return new self('INVALID_INPUT', $message); }

    public static function alertsUnresolved(int $n): self
    {
        return new self('ALERTS_UNRESOLVED', $n === 1
            ? '1 alert in this case still needs a decision. Close it first.'
            : "$n alerts in this case still need a decision. Close them first.", ['unresolved' => $n]);
    }

    public static function outcomeConflict(): self
    {
        return new self('OUTCOME_CONFLICT',
            "Some alerts in this case were confirmed suspicious, so it can't be closed as no further action. Escalate it to compliance instead.");
    }
}