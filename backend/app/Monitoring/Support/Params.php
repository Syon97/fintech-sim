<?php

namespace App\Monitoring\Support;

use InvalidArgumentException;

final class Params
{
    private const ACCOUNT_TYPES = ['asset', 'liability', 'equity', 'income', 'expense'];

    /** @return string[] */
    public static function directions(array $p): array
    {
        return $p['directions'] ?? ['D', 'C'];
    }

    /** @return string[] */
    public static function accountTypes(array $p): array
    {
        return $p['account_types'] ?? ['liability']; // customer accounts only by default
    }

    public static function positiveInt(array $p, string $key): int
    {
        if (! isset($p[$key]) || ! is_int($p[$key]) || $p[$key] <= 0) {
            throw new InvalidArgumentException("Parameter '$key' must be a positive integer");
        }

        return $p[$key];
    }

    public static function validateFilters(array $p): void
    {
        $d = self::directions($p);
        if ($d === [] || array_diff($d, ['D', 'C']) !== []) {
            throw new InvalidArgumentException("Parameter 'directions' must be a non-empty subset of D, C");
        }

        $t = self::accountTypes($p);
        if ($t === [] || array_diff($t, self::ACCOUNT_TYPES) !== []) {
            throw new InvalidArgumentException("Parameter 'account_types' must be a non-empty subset of " . implode(', ', self::ACCOUNT_TYPES));
        }
    }

    /** Does this event entry fall inside the rule's direction and account-type filters? */
    public static function matches(array $entry, array $p): bool
    {
        return in_array($entry['direction'], self::directions($p), true)
            && in_array($entry['account_type'], self::accountTypes($p), true);
    }

        public static function intBetween(array $p, string $key, int $min, int $max): int
    {
        $v = $p[$key] ?? null;
        if (! is_int($v) || $v < $min || $v > $max) {
            throw new InvalidArgumentException("Parameter '$key' must be an integer between $min and $max");
        }

        return $v;
    }

    public static function validateAccountTypes(array $p): void
    {
        $t = self::accountTypes($p);
        if ($t === [] || array_diff($t, self::ACCOUNT_TYPES) !== []) {
            throw new InvalidArgumentException("Parameter 'account_types' must be a non-empty subset of " . implode(', ', self::ACCOUNT_TYPES));
        }
    }
}