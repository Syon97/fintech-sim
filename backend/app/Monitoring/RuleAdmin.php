<?php

namespace App\Monitoring;

use Illuminate\Support\Facades\DB;

/**
 * Creates rules and rule versions. In Slice 4 this gets a maker-checker approval
 * step. Until then, defining a rule activates it immediately.
 */
final class RuleAdmin
{
    public function __construct(private readonly RuleRegistry $registry) {}

    /** @return int the new rule version id */
    public function define(
        string $code,
        string $type,
        string $name,
        string $rationale,
        array $parameters,
        string $severity,
        bool $activate = true,
    ): int {
        // Fail before anything is stored: a bad rule must never become active.
        $this->registry->for($type)->validate($parameters);

        return DB::transaction(function () use ($code, $type, $name, $rationale, $parameters, $severity, $activate) {
            $rule = DB::table('monitoring_rules')->where('code', $code)->lockForUpdate()->first();
            $ruleId = $rule !== null
                ? (int) $rule->id
                : DB::table('monitoring_rules')->insertGetId(compact('code', 'type', 'name', 'rationale'));

            $next = (int) DB::table('monitoring_rule_versions')->where('rule_id', $ruleId)->max('version') + 1;

            if ($activate) {
                DB::table('monitoring_rule_versions')
                    ->where('rule_id', $ruleId)->where('status', 'active')
                    ->update(['status' => 'retired']);
            }

            return DB::table('monitoring_rule_versions')->insertGetId([
                'rule_id' => $ruleId,
                'version' => $next,
                'parameters' => json_encode($parameters, JSON_THROW_ON_ERROR),
                'severity' => $severity,
                'status' => $activate ? 'active' : 'draft',
                'activated_at' => $activate ? now() : null,
            ]);
        });
    }
}