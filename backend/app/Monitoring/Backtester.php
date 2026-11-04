<?php

namespace App\Monitoring;

use App\Ledger\Events\LedgerEvent;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Runs a DRAFT rule over history and reports what it would have raised.
 * Everything happens inside a transaction that is always rolled back, so nothing persists.
 */
final class Backtester
{
    private const BATCH = 500;

    public function __construct(private readonly RuleRegistry $registry) {}

    /** Backtest the parameters proposed in a pending define request, and record the result. */
    public function runForRequest(int $requestId, string $actor, ?int $fromId = null, ?int $toId = null): array
    {
        $req = DB::table('monitoring_rule_change_requests')->where('id', $requestId)->first();
        if ($req === null || $req->change_type !== 'define') {
            throw new InvalidArgumentException("Request $requestId is not a rule definition request");
        }

        $parameters = json_decode($req->parameters, true, 512, JSON_THROW_ON_ERROR);
        $result = $this->run($req->rule_code, $req->rule_type, $parameters, $req->severity, $fromId, $toId);

        DB::table('monitoring_backtests')->insert([
            'change_request_id' => $requestId,
            'rule_code' => $req->rule_code,
            'parameters' => json_encode($parameters, JSON_THROW_ON_ERROR),
            'severity' => $req->severity,
            'requested_by' => strtolower(trim($actor)),
            'result' => json_encode($result, JSON_THROW_ON_ERROR),
        ]);

        return $result;
    }

    public function run(string $code, string $type, array $parameters, string $severity, ?int $fromId = null, ?int $toId = null): array
    {
        $rule = $this->registry->for($type);
        $rule->validate($parameters);

        $existing = DB::table('monitoring_rules')->where('code', $code)->first();
        if ($existing !== null && $existing->type !== $type) {
            throw new InvalidArgumentException("Rule '$code' is of type '{$existing->type}', not '$type'");
        }

        $to = $toId ?? PHP_INT_MAX;
        DB::beginTransaction();

        try {
            $ruleId = $existing !== null
                ? (int) $existing->id
                : (int) DB::table('monitoring_rules')->insertGetId([
                    'code' => $code, 'type' => $type, 'name' => 'Backtest', 'rationale' => 'Backtest only',
                ]);

            $liveId = DB::table('monitoring_rule_versions')->where('rule_id', $ruleId)->where('status', 'active')->value('id');
            $number = (int) DB::table('monitoring_rule_versions')->where('rule_id', $ruleId)->max('version') + 1;

            $draftId = (int) DB::table('monitoring_rule_versions')->insertGetId([
                'rule_id' => $ruleId,
                'version' => $number,
                'parameters' => json_encode($parameters, JSON_THROW_ON_ERROR),
                'severity' => $severity,
                'status' => 'draft',
            ]);
            $draft = new RuleVersion($draftId, $code, $type, $number, $severity, $parameters);

            $scanned = 0;
            $first = null;
            $last = null;
            $cursor = ($fromId ?? 1) - 1;

            while (true) {
                $rows = DB::select(
                    "SELECT o.id, o.event_id, o.event_type, o.schema_version, o.aggregate_type, o.aggregate_id, o.payload, o.occurred_at
                       FROM outbox_events o
                       JOIN consumer_inbox ci ON ci.event_id = o.id AND ci.consumer = 'monitoring'
                      WHERE o.event_type = 'transaction.posted' AND o.id > ? AND o.id <= ?
                      ORDER BY o.id
                      LIMIT " . self::BATCH,
                    [$cursor, $to],
                );
                if ($rows === []) {
                    break;
                }

                foreach ($rows as $row) {
                    $event = LedgerEvent::fromRow($row);
                    $cursor = $event->id;
                    $first ??= $event->id;
                    $last = $event->id;
                    $scanned++;

                    foreach ($rule->evaluate($event, $draft) as $f) {
                        // Written so the rule's own suppression logic sees earlier draft alerts. Rolled back below.
                        DB::table('monitoring_alerts')->insertOrIgnore([
                            'rule_version_id' => $draftId,
                            'dedupe_key' => $f->dedupeKey,
                            'event_id' => $event->id,
                            'transaction_id' => $event->aggregateId,
                            'account_id' => $f->accountId,
                            'account_number' => $f->accountNumber,
                            'severity' => $severity,
                            'explanation' => $f->explanation,
                            'details' => json_encode($f->details, JSON_THROW_ON_ERROR),
                            'event_occurred_at' => $event->occurredAt,
                        ]);
                    }
                }
            }

            $summary = DB::selectOne(
                'SELECT count(*) AS alerts, count(DISTINCT account_id) AS accounts FROM monitoring_alerts WHERE rule_version_id = ?',
                [$draftId],
            );

            $liveAlerts = 0;
            $overlap = 0;
            if ($liveId !== null && $scanned > 0) {
                $liveAlerts = (int) DB::table('monitoring_alerts')->where('rule_version_id', $liveId)
                    ->whereBetween('event_id', [$first, $last])->count();
                $overlap = (int) DB::selectOne(
                    'SELECT count(*) AS c FROM monitoring_alerts b
                       JOIN monitoring_alerts l ON l.transaction_id = b.transaction_id AND l.account_id = b.account_id AND l.rule_version_id = ?
                      WHERE b.rule_version_id = ?',
                    [$liveId, $draftId],
                )->c;
            }

            $top = DB::select(
                'SELECT account_number, count(*) AS alerts FROM monitoring_alerts WHERE rule_version_id = ?
                  GROUP BY account_number ORDER BY alerts DESC, account_number LIMIT 5',
                [$draftId],
            );
            $samples = DB::table('monitoring_alerts')->where('rule_version_id', $draftId)->orderBy('id')->limit(5)->pluck('explanation')->all();

            return [
                'events_scanned' => $scanned,
                'from_event_id' => $first,
                'to_event_id' => $last,
                'alerts' => (int) $summary->alerts,
                'distinct_accounts' => (int) $summary->accounts,
                'live_version_id' => $liveId !== null ? (int) $liveId : null,
                'live_alerts' => $liveAlerts,
                'overlap_with_live' => $overlap,
                'top_accounts' => array_map(fn ($r) => ['account' => $r->account_number, 'alerts' => (int) $r->alerts], $top),
                'sample_explanations' => $samples,
            ];
        } finally {
            DB::rollBack(); // always: a backtest never leaves anything behind
        }
    }
}