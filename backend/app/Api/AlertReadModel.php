<?php

namespace App\Api;

use App\Models\User;
use App\Monitoring\Support\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Everything the screens read. Joins monitoring's own tables to users, to show names. */
final class AlertReadModel
{
    private const ORDER = 'a.risk_score DESC NULLS LAST, a.event_occurred_at DESC, a.id DESC';
    private const CLOSE_LABELS = ['false_positive' => 'a false positive', 'confirmed_suspicious' => 'confirmed suspicious'];

    /** @return array{data: array, page: int, per_page: int, total: int} */
    public function list(array $f, string $me): array
    {
        $page = (int) ($f['page'] ?? 1);
        $per = (int) ($f['per_page'] ?? 25);

        $q = $this->base();
        $status = $f['status'] ?? 'unresolved';
        if ($status === 'unresolved') {
            $q->whereIn('a.status', ['open', 'investigating']);
        } elseif ($status !== 'all') {
            $q->where('a.status', $status);
        }

        if (($f['assigned'] ?? null) === 'me') {
            $q->where('a.assigned_to', $me);
        } elseif (($f['assigned'] ?? null) === 'unassigned') {
            $q->whereNull('a.assigned_to');
        }
        if (isset($f['min_score'])) {
            $q->where('a.risk_score', '>=', (int) $f['min_score']);
        }
        if (! empty($f['account'])) {
            $q->where('a.account_number', $f['account']);
        }
        if (! empty($f['group'])) {
            $q->where('a.group_id', (int) $f['group']);
        }

        $total = (clone $q)->count();
        $rows = $q->orderByRaw(self::ORDER)->forPage($page, $per)->get([
            'a.id', 'a.status', 'a.severity', 'a.risk_score', 'a.account_number', 'a.explanation',
            'a.event_occurred_at', 'a.assigned_to', 'a.group_id',
            'u.name as assignee_name', 'r.code as rule_code', 'r.name as rule_name',
        ]);

        return [
            'data' => $rows->map(fn ($r) => $this->summary($r))->all(),
            'page' => $page,
            'per_page' => $per,
            'total' => $total,
        ];
    }

    public function detail(int $id, User $user): ?array
    {
        $a = $this->base()->where('a.id', $id)->first([
            'a.id', 'a.account_id', 'a.account_number', 'a.transaction_id', 'a.severity', 'a.status',
            'a.risk_score', 'a.score_breakdown', 'a.explanation', 'a.details', 'a.event_occurred_at',
            'a.created_at', 'a.assigned_to', 'a.disposition', 'a.closed_at', 'a.group_id',
            'u.name as assignee_name', 'r.code as rule_code', 'r.name as rule_name',
            'r.rationale', 'v.version as rule_version',
        ]);
        if ($a === null) {
            return null;
        }

        $details = json_decode($a->details, true) ?? [];
        $breakdown = $a->score_breakdown !== null ? json_decode($a->score_breakdown, true) : null;

        $group = null;
        if ($a->group_id !== null) {
            $g = DB::selectOne(
                "SELECT g.alert_count, g.max_score, g.first_alert_at, g.last_alert_at,
                        count(x.id) FILTER (WHERE x.status <> 'closed') AS unresolved
                   FROM monitoring_alert_groups g
                   LEFT JOIN monitoring_alerts x ON x.group_id = g.id
                  WHERE g.id = ?
                  GROUP BY g.id",
                [$a->group_id],
            );
            $group = [
                'id' => (int) $a->group_id,
                'alert_count' => (int) $g->alert_count,
                'unresolved' => (int) $g->unresolved,
                'max_score' => (int) $g->max_score,
                'first_alert_at' => self::iso($g->first_alert_at),
                'last_alert_at' => self::iso($g->last_alert_at),
            ];
        }

        $mine = $a->assigned_to === $user->username;
        $gate = Gate::forUser($user);

        return array_merge ($this->summary($a), [
            'disposition' => $a->disposition,
            'closed_at' => self::iso($a->closed_at),
            'rule' => [
                'code' => $a->rule_code,
                'name' => $a->rule_name,
                'version' => (int) $a->rule_version,
                'rationale' => $a->rationale,
            ],
            'score' => [
                'total' => $a->risk_score !== null ? (int) $a->risk_score : null,
                'sentences' => $breakdown !== null ? $this->scoreSentences($breakdown, $a->severity) : [],
            ],
            'evidence' => $this->evidence((int) $a->account_id, array_slice($details['evidence_transaction_ids'] ?? [], 0, 50)),
            'details' => $details,
            'group' => $group,
            'timeline' => $this->timeline($a),
            'can' => [
                'start' => $a->status === 'open' && $gate->allows('alerts.work'),
                'release' => $a->status === 'investigating' && $mine && $gate->allows('alerts.work'),
                'close' => $a->status === 'investigating' && $mine && $gate->allows('alerts.work'),
                'reassign' => $a->status === 'investigating' && $gate->allows('alerts.reassign'),
            ],
        ]);
    }

    /** @return array{data: array, page: int, per_page: int, total: int} */
    public function groups(string $state, int $page, int $per): array
    {
        $having = $state === 'unresolved' ? "HAVING count(a.id) FILTER (WHERE a.status <> 'closed') > 0" : '';
        $base = "FROM monitoring_alert_groups g LEFT JOIN monitoring_alerts a ON a.group_id = g.id GROUP BY g.id $having";

        $total = (int) DB::selectOne("SELECT count(*) AS c FROM (SELECT g.id $base) t")->c;
        $rows = DB::select(
            "SELECT g.id, g.account_number, g.alert_count, g.max_score, g.first_alert_at, g.last_alert_at,
                    count(a.id) FILTER (WHERE a.status <> 'closed') AS unresolved
               $base
              ORDER BY g.max_score DESC, g.last_alert_at DESC, g.id DESC
              LIMIT ? OFFSET ?",
            [$per, ($page - 1) * $per],
        );

        return [
            'data' => array_map(fn ($r) => [
                'id' => (int) $r->id,
                'account_number' => $r->account_number,
                'alert_count' => (int) $r->alert_count,
                'unresolved' => (int) $r->unresolved,
                'max_score' => (int) $r->max_score,
                'first_alert_at' => self::iso($r->first_alert_at),
                'last_alert_at' => self::iso($r->last_alert_at),
            ], $rows),
            'page' => $page,
            'per_page' => $per,
            'total' => $total,
        ];
    }

    /** Active people who can take an alert, for the reassign picker. */
    public function assignees(): array
    {
        return User::query()->where('active', true)->orderBy('name')->get()
            ->filter(fn (User $u) => Gate::forUser($u)->allows('alerts.work'))
            ->map(fn (User $u) => ['username' => $u->username, 'name' => $u->name, 'role' => $u->role])
            ->values()->all();
    }

    private function base()
    {
        return DB::table('monitoring_alerts as a')
            ->join('monitoring_rule_versions as v', 'v.id', '=', 'a.rule_version_id')
            ->join('monitoring_rules as r', 'r.id', '=', 'v.rule_id')
            ->leftJoin('users as u', 'u.username', '=', 'a.assigned_to');
    }

    private function summary(object $r): array
    {
        return [
            'id' => (int) $r->id,
            'status' => $r->status,
            'severity' => $r->severity,
            'risk_score' => $r->risk_score !== null ? (int) $r->risk_score : null,
            'account_number' => $r->account_number,
            'explanation' => $r->explanation,
            'occurred_at' => self::iso($r->event_occurred_at),
            'rule' => ['code' => $r->rule_code, 'name' => $r->rule_name],
            'assignee' => self::person($r->assigned_to, $r->assignee_name),
            'group_id' => $r->group_id !== null ? (int) $r->group_id : null,
        ];
    }

    private function scoreSentences(array $b, string $severity): array
    {
        $out = [];
        foreach ($b['components'] as $c) {
            $out[] = match ($c['factor']) {
                'severity' => "Starts at {$c['points']} because the rule's severity is $severity.",
                'repeat_alerts' => "Adds {$c['points']} for {$b['prior_alerts']} earlier "
                    . ($b['prior_alerts'] === 1 ? 'alert' : 'alerts')
                    . " on this account in the last {$b['lookback_hours']} hours.",
                'other_rule_alerts' => "Adds {$c['points']} because a different rule also fired on this account in that period.",
                default => "{$c['factor']}: {$c['points']}.",
            };
        }

        return $out;
    }

    private function evidence(int $accountId, array $transactionIds): array
    {
        if ($transactionIds === []) {
            return [];
        }

        return DB::table('monitoring_activity')
            ->where('account_id', $accountId)->whereIn('transaction_id', $transactionIds)
            ->orderBy('occurred_at')->orderBy('id')
            ->get(['transaction_id', 'direction', 'amount', 'occurred_at'])
            ->map(fn ($e) => [
                'transaction_id' => $e->transaction_id,
                'direction' => $e->direction,
                'amount_sen' => (int) $e->amount,
                'amount_display' => Money::myr((int) $e->amount),
                'occurred_at' => self::iso($e->occurred_at),
            ])->all();
    }

    private function timeline(object $a): array
    {
        $timeline = [[
            'at' => self::iso($a->created_at),
            'actor' => null,
            'sentence' => "Raised by the rule \"{$a->rule_name}\" (version {$a->rule_version}).",
            'note' => null,
        ]];

        $events = DB::table('monitoring_alert_events as e')
            ->leftJoin('users as ua', 'ua.username', '=', 'e.actor')
            ->leftJoin('users as ub', 'ub.username', '=', 'e.assigned_to')
            ->where('e.alert_id', $a->id)->orderBy('e.id')
            ->get(['e.from_status', 'e.to_status', 'e.actor', 'e.note', 'e.assigned_to', 'e.created_at',
                'ua.name as actor_name', 'ub.name as assignee_name']);

        foreach ($events as $e) {
            $who = $e->actor_name ?? $e->actor;
            $sentence = match (true) {
                $e->from_status === 'open' && $e->to_status === 'investigating' => "$who started looking at this.",
                $e->from_status === 'investigating' && $e->to_status === 'open' => "$who released this back to the queue.",
                $e->from_status === 'investigating' && $e->to_status === 'investigating'
                    => "$who reassigned this to " . ($e->assignee_name ?? $e->assigned_to ?? 'someone else') . '.',
                $e->to_status === 'closed' => "$who closed this as " . (self::CLOSE_LABELS[$a->disposition] ?? 'resolved') . '.',
                default => "$who changed the status from {$e->from_status} to {$e->to_status}.",
            };

            $timeline[] = [
                'at' => self::iso($e->created_at),
                'actor' => self::person($e->actor, $e->actor_name),
                'sentence' => $sentence,
                'note' => $e->note,
            ];
        }

        return $timeline;
    }

    private static function person(?string $username, ?string $name): ?array
    {
        return $username === null ? null : ['username' => $username, 'name' => $name ?? $username];
    }

    private static function iso(?string $value): ?string
    {
        return $value === null ? null : Carbon::parse($value)->utc()->toIso8601String();
    }
}