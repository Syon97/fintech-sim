<?php

namespace App\Ledger\Exceptions;

use RuntimeException;

final class LedgerException extends RuntimeException
{
    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function invalid(string $m): self { return new self('INVALID_POSTING', $m); }
    public static function unknownAccount(int $id): self { return new self('UNKNOWN_ACCOUNT', "Account $id does not exist"); }
    public static function notPostable(int $id, string $status): self { return new self('ACCOUNT_NOT_POSTABLE', "Account $id is $status"); }
    public static function insufficientFunds(int $id): self { return new self('INSUFFICIENT_FUNDS', "Account $id has insufficient funds"); }
    public static function idempotencyMismatch(string $key): self { return new self('IDEMPOTENCY_MISMATCH', "Key '$key' was already used with a different request"); }
    public static function inProgress(string $key): self { return new self('IDEMPOTENCY_IN_PROGRESS', "Key '$key' is still being processed"); }
    public static function transactionNotFound(string $id): self { return new self('TRANSACTION_NOT_FOUND', "Transaction $id does not exist"); }
    public static function cannotReverse(string $id, string $why): self { return new self('CANNOT_REVERSE', "Transaction $id cannot be reversed: $why"); }
    public static function alreadyReversed(string $id): self { return new self('ALREADY_REVERSED', "Transaction $id has already been reversed"); }
}