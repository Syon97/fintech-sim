<?php

namespace App\Api;

use App\Ledger\AccountService;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Everything the case screens read. The account's status comes through the ledger's own service. */
final class CaseReadModel
{
    private const OUTCOMES = ['no_further_action' => 'no further action', 'escalated_to_compliance' => 'escalated to compliance'];

    public function __construct(private readonly AccountService $accounts) {}

    /** @return array{data: array, page: int, per_page: int, total: int} */
    public function list(array $f, string $me): array
    {
        $page = (int) ($f['page'] ?? 1);
        $per = (int) ($f['per_page'] ?? 25);

        $q = DB::table('monitoring_cases as c')->leftJoin('users as u', 'u.username', '=', 'c.owner');
        $status = $f['status'] ?? 'open';
        if ($status !== 'all') {
            $q->where('c.status', $status);
        }
        if (($f['owner'] ?? null) === 'me') {
            $q->where('c.owner', $me);
        }

        $total = (clone $q)->count();
        $rows = $q->orderByDesc('c.id')->forPage($page, $per)->get([
            'c.id', 'c.group_id', 'c.account_id', 'c.account_number', 'c.status', 'c.owner',
            'c.outcome', 'c.opened_at', 'c.closed_at', 'u.name as owner_name',
        ]);

        $counts = $rows->isEmpty() ? collect() : DB::table('monitoring_alerts')
            ->whereIn('group_id', $rows->pluck('group_id')->all())
            ->groupBy('group_id')
            ->selectRaw("group_id, count(*) AS total, count(*) FILTER (WHERE status <> 'closed') AS unresolved, max(risk_score) AS max_score")
            ->get()->keyBy('group_id');
        $statuses = $this->accounts->statuses($rows->pluck('account_id')->map(fn ($i) => (int) $i)->all());

        return [
            'data' => $rows->map(function ($r) use ($counts, $statuses) {
                $n = $counts->get((int) $r->group_id);

                return [
                    'id' => (int) $r->id,
                    'group_id' => (int) $r->group_id,
                    'status' => $r->status,
                    'outcome' => $r->outcome,
                    'outcome_label' => self::OUTCOMES[$r->outcome] ?? null,
                    'opened_at' => self::iso($r->opened_at),
                    'closed_at' => self::iso($r->closed_at),
                    'owner' => self::person($r->owner, $r->owner_name),
                    'account' => ['number' => $r->account_number, 'status' => $statuses[(int) $r->account_id] ?? 'unknown'],
                    'alerts' => [
                        'total' => $n ? (int) $n->total : 0,
                        'unresolved' => $n ? (int) $n->unresolved : 0,
                        'max_score' => $n && $n->max_score !== null ? (int) $n->max_score : null,
                    ],
                ];
            })->all(),
            'page' => $page,
            'per_page' => $per,
            'total' => $total,
        ];
    }

    public function detail(int $id, User $user): ?array
    {
        $c = DB::table('monitoring_cases as c')
            ->leftJoin('users as uo', 'uo.username', '=', 'c.owner')
            ->leftJoin('users as ub', 'ub.username', '=', 'c.opened_by')
            ->where('c.id', $id)
            ->first(['c.*', 'uo.name as owner_name', 'ub.name as opened_by_name']);
        if ($c === null) {
            return null;
        }

        $g = DB::table('monitoring_alert_groups')->where('id', $c->group_id)->first();

        $alerts = DB::table('monitoring_alerts as a')
            ->join('monitoring_rule_versions as v', 'v.id', '=', 'a.rule_version_id')
            ->join('monitoring_rules as r', 'r.id', '=', 'v.rule_id')
            ->leftJoin('users as u', 'u.username', '=', 'a.assigned_to')
            ->where('a.group_id', $c->group_id)
            ->orderByRaw('a.risk_score DESC NULLS LAST, a.id DESC')
            ->get(['a.id', 'a.status', 'a.severity', 'a.risk_score', 'a.explanation', 'a.disposition',
                'a.event_occurred_at', 'a.assigned_to', 'u.name as assignee_name', 'r.name as rule_name']);

        $unresolved = $alerts->where('status', '!=', 'closed')->count();
        $mustEscalate = $alerts->where('disposition', 'confirmed_suspicious')->count() > 0;
        $accountStatus = $this->accounts->statuses([(int) $c->account_id])[(int) $c->account_id] ?? 'unknown';

        $open = $c->status === 'open';
        $manage = Gate::forUser($user)->allows('cases.manage');
        $owner = $c->owner === $user->username;

        return [
            'id' => (int) $c->id,
            'status' => $c->status,
            'outcome' => $c->outcome,
            'outcome_label' => self::OUTCOMES[$c->outcome] ?? null,
            'closing_note' => $c->closing_note,
            'opened_at' => self::iso($c->opened_at),
            'closed_at' => self::iso($c->closed_at),
            'owner' => self::person($c->owner, $c->owner_name),
            'opened_by' => self::person($c->opened_by, $c->opened_by_name),
            'account' => ['number' => $c->account_number, 'status' => $accountStatus],
            'group' => [
                'id' => (int) $c->group_id,
                'alert_count' => (int) $g->alert_count,
                'max_score' => (int) $g->max_score,
                'first_alert_at' => self::iso($g->first_alert_at),
                'last_alert_at' => self::iso($g->last_alert_at),
            ],
            'alerts' => $alerts->map(fn ($a) => [
                'id' => (int) $a->id,
                'status' => $a->status,
                'severity' => $a->severity,
                'risk_score' => $a->risk_score !== null ? (int) $a->risk_score : null,
                'explanation' => $a->explanation,
                'rule_name' => $a->rule_name,
                'disposition' => $a->disposition,
                'occurred_at' => self::iso($a->event_occurred_at),
                'assignee' => self::person($a->assigned_to, $a->assignee_name),
            ])->all(),
            'timeline' => $this->timeline($c),
            'can' => [
                'add_note' => $open && $manage,
                'assign' => $open && $manage,
                'freeze' => $open && $manage && $owner && $accountStatus === 'active',
                'unfreeze' => $open && $manage && $owner && $accountStatus === 'frozen',
                'close' => $open && $manage && $owner && $unresolved === 0,
            ],
            'close_blockers' => $open && $unresolved > 0
                ? [$unresolved === 1 ? '1 alert still needs a decision.' : "$unresolved alerts still need a decision."]
                : [],
            'must_escalate' => $mustEscalate,
        ];
    }

    /** Active people who can own a case, for the assign picker. */
    public function assignees(): array
    {
        return User::query()->where('active', true)->orderBy('name')->get()
            ->filter(fn (User $u) => Gate::forUser($u)->allows('cases.manage'))
            ->map(fn (User $u) => ['username' => $u->username, 'name' => $u->name, 'role' => $u->role])
            ->values()->all();
    }

    private function timeline(object $c): array
    {
        $label = self::OUTCOMES[$c->outcome] ?? 'resolved';

        return DB::table('monitoring_case_events as e')
            ->leftJoin('users as ua', 'ua.username', '=', 'e.actor')
            ->leftJoin('users as ub', 'ub.username', '=', 'e.assigned_to')
            ->where('e.case_id', $c->id)->orderBy('e.id')
            ->get(['e.event', 'e.actor', 'e.note', 'e.assigned_to', 'e.created_at', 'ua.name as actor_name', 'ub.name as assignee_name'])
            ->map(function ($e) use ($c, $label) {
                $who = $e->actor_name ?? $e->actor;

                return [
                    'at' => self::iso($e->created_at),
                    'actor' => self::person($e->actor, $e->actor_name),
                    'sentence' => match ($e->event) {
                        'opened' => "$who opened this case.",
                        'assigned' => "$who assigned this case to " . ($e->assignee_name ?? $e->assigned_to ?? 'someone else') . '.',
                        'note' => "$who added a note.",
                        'account_frozen' => "$who froze account {$c->account_number}.",
                        'account_unfrozen' => "$who lifted the freeze on account {$c->account_number}.",
                        'closed' => "$who closed this case as $label.",
                        default => "$who updated this case.",
                    },
                    'note' => $e->note,
                ];
            })->all();
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