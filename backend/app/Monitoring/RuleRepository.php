<?php

namespace App\Monitoring;

use Illuminate\Support\Facades\DB;

final class RuleRepository
{
    /** @return RuleVersion[] */
    public function activeVersions(): array
    {
        return DB::table('monitoring_rule_versions as v')
            ->join('monitoring_rules as r', 'r.id', '=', 'v.rule_id')
            ->where('v.status', 'active')
            ->orderBy('v.id')
            ->get(['v.id', 'r.code', 'r.type', 'v.version', 'v.severity', 'v.parameters'])
            ->map(fn ($row) => RuleVersion::fromRow($row))
            ->all();
    }
}