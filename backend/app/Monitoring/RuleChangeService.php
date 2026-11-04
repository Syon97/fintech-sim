<?php

namespace App\Monitoring;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Maker-checker for rule changes. Nothing here changes what is live until a second
 * person approves, and approval plus activation happen in one database transaction.
 */
final class RuleChangeService
{
    private const SEVERITIES = ['low', 'medium', 'high', 'critical'];

    public function __construct(
        private readonly RuleRegistry $registry,
        private readonly RuleAdmin $admin,
    ) {}

    /** Propose a new rule, or a new version of an existing one. @return int request id */
    public function propose(
        string $maker,
        string $code,
        string $type,
        string $name,
        string $rationale,
        array $parameters,
        string $severity,
        string $reason,
    ): int {
        $maker = $this->actor($maker);
        $code = $this->text($code, 'code', 64);
        $name = $this->text($name, 'name', 255);
        $rationale = $this->text($rationale, 'rationale');
        $reason = $this->text($reason, 'reason');
        if (! in_array($severity, self::SEVERITIES, true)) {
            throw RuleChangeException::invalid('severity must be one of: ' . implode(', ', self::SEVERITIES));
        }

        $this->validateDefinition($type, $parameters);
        $this->assertSameType($code, $type);
        $this->assertNoPending($code);

        return $this->insert([
            'rule_code' => $code,
            'change_type' => 'define',
            'rule_type' => $type,
            'name' => $name,
            'rationale' => $rationale,
            'parameters' => json_encode($parameters, JSON_THROW_ON_ERROR),
            'severity' => $severity,
            'reason' => $reason,
            'maker' => $maker,
        ]);
    }

    /** Propose switching a rule off. @return int request id */
    public function proposeRetire(string $maker, string $code, string $reason): int
    {
        $maker = $this->actor($maker);
        $code = $this->text($code, 'code', 64);
        $reason = $this->text($reason, 'reason');

        $rule = DB::table('monitoring_rules')->where('code', $code)->first();
        if ($rule === null) {
            throw RuleChangeException::unknownRule($code);
        }
        if (! DB::table('monitoring_rule_versions')->where('rule_id', $rule->id)->where('status', 'active')->exists()) {
            throw RuleChangeException::nothingToRetire($code);
        }
        $this->assertNoPending($code);

        return $this->insert([
            'rule_code' => $code,
            'change_type' => 'retire',
            'reason' => $reason,
            'maker' => $maker,
        ]);
    }

    /** @return int the rule version that was created (define) or retired (retire) */
    public function approve(int $requestId, string $checker, ?string $note = null): int
    {
        $checker = $this->actor($checker);
        $note = $note !== null && trim($note) !== '' ? trim($note) : null;

        return DB::transaction(function () use ($requestId, $checker, $note) {
            $req = $this->lock($requestId);
            $this->assertCanDecide($req, $checker);

            $versionId = $req->change_type === 'define' ? $this->applyDefine($req) : $this->applyRetire($req);

            DB::table('monitoring_rule_change_requests')->where('id', $requestId)->update([
                'status' => 'approved',
                'checker' => $checker,
                'decision_note' => $note,
                'version_id' => $versionId,
                'decided_at' => now(),
            ]);

            return $versionId;
        });
    }

    public function reject(int $requestId, string $checker, string $note): void
    {
        $checker = $this->actor($checker);
        $note = $this->text($note, 'note');

        DB::transaction(function () use ($requestId, $checker, $note) {
            $req = $this->lock($requestId);
            $this->assertCanDecide($req, $checker);

            DB::table('monitoring_rule_change_requests')->where('id', $requestId)->update([
                'status' => 'rejected',
                'checker' => $checker,
                'decision_note' => $note,
                'decided_at' => now(),
            ]);
        });
    }

    public function withdraw(int $requestId, string $actor): void
    {
        $actor = $this->actor($actor);

        DB::transaction(function () use ($requestId, $actor) {
            $req = $this->lock($requestId);
            if ($req->status !== 'pending') {
                throw RuleChangeException::alreadyDecided($requestId, $req->status);
            }
            if ($req->maker !== $actor) {
                throw RuleChangeException::notMaker($requestId);
            }

            DB::table('monitoring_rule_change_requests')->where('id', $requestId)
                ->update(['status' => 'withdrawn', 'decided_at' => now()]);
        });
    }

    private function applyDefine(object $req): int
    {
        $this->assertSameType($req->rule_code, $req->rule_type); // may have changed since the proposal

        try {
            return $this->admin->define(
                $req->rule_code, $req->rule_type, $req->name, $req->rationale,
                json_decode($req->parameters, true, 512, JSON_THROW_ON_ERROR), $req->severity, true,
            );
        } catch (InvalidArgumentException $e) {
            throw RuleChangeException::invalidRule($e->getMessage());
        }
    }

    private function applyRetire(object $req): int
    {
        $rule = DB::table('monitoring_rules')->where('code', $req->rule_code)->lockForUpdate()->first();
        $version = $rule === null ? null : DB::table('monitoring_rule_versions')
            ->where('rule_id', $rule->id)->where('status', 'active')->lockForUpdate()->first();

        if ($version === null) {
            throw RuleChangeException::nothingToRetire($req->rule_code);
        }

        DB::table('monitoring_rule_versions')->where('id', $version->id)->update(['status' => 'retired']);

        return (int) $version->id;
    }

    private function lock(int $id): object
    {
        // The row lock serializes concurrent decisions: the loser sees the winner's result.
        $req = DB::table('monitoring_rule_change_requests')->where('id', $id)->lockForUpdate()->first();
        if ($req === null) {
            throw RuleChangeException::notFound($id);
        }

        return $req;
    }

    private function assertCanDecide(object $req, string $checker): void
    {
        if ($req->status !== 'pending') {
            throw RuleChangeException::alreadyDecided((int) $req->id, $req->status);
        }
        if ($req->maker === $checker) {
            throw RuleChangeException::selfApproval((int) $req->id);
        }
    }

    private function validateDefinition(string $type, array $parameters): void
    {
        try {
            $this->registry->for($type)->validate($parameters);
        } catch (InvalidArgumentException $e) {
            throw RuleChangeException::invalidRule($e->getMessage());
        }
    }

    private function assertSameType(string $code, string $type): void
    {
        $existing = DB::table('monitoring_rules')->where('code', $code)->value('type');
        if ($existing !== null && $existing !== $type) {
            throw RuleChangeException::typeMismatch($code, $existing, $type);
        }
    }

    private function assertNoPending(string $code): void
    {
        if (DB::table('monitoring_rule_change_requests')->where('rule_code', $code)->where('status', 'pending')->exists()) {
            throw RuleChangeException::pendingExists($code);
        }
    }

    /** The unique index is the backstop for the check above (two proposals racing). */
    private function insert(array $row): int
    {
        try {
            // Nested transaction = savepoint, so a failed insert never poisons an outer transaction.
            return (int) DB::transaction(fn () => DB::table('monitoring_rule_change_requests')->insertGetId($row));
        } catch (UniqueConstraintViolationException) {
            throw RuleChangeException::pendingExists($row['rule_code']);
        }
    }

    private function actor(string $actor): string
    {
        $actor = strtolower(trim($actor));
        if ($actor === '' || strlen($actor) > 64) {
            throw RuleChangeException::invalid('actor must be 1 to 64 characters');
        }

        return $actor;
    }

    private function text(string $value, string $field, ?int $max = null): string
    {
        $value = trim($value);
        if ($value === '' || ($max !== null && strlen($value) > $max)) {
            throw RuleChangeException::invalid("$field is required" . ($max !== null ? " (max $max characters)" : ''));
        }

        return $value;
    }
}