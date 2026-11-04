<?php

namespace App\Access;

final class Permissions
{
    /** Roles are duties. Rule administration and alert work are deliberately kept apart. */
    public const ROLES = [
        'analyst' => ['alerts.view', 'alerts.work'],
        'supervisor' => ['alerts.view', 'alerts.work', 'alerts.reassign', 'cases.view', 'cases.manage', 'rules.view'],
        'rule_admin' => ['rules.view', 'rules.propose', 'rules.decide', 'rules.backtest'],
    ];

    /** @return string[] */
    public static function for(string $role): array
    {
        return self::ROLES[$role] ?? [];
    }

    /** @return string[] */
    public static function all(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::ROLES))));
    }
}