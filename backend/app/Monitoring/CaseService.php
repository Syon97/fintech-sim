<?php

namespace App\Monitoring;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The case lifecycle. It knows nothing about the ledger: acting on an account is done by the
 * application layer, which then records the result here with recordAccountAction().
 */
final class CaseService
{
    public const OUTCOMES = ['no_further_action', 'escalated_to_compliance'];

    public function open(int $groupId, string $actor, ?string $note = null): int
    {
        $actor = $this->person($actor);
        $note = $this->optional($note);

        return DB::transaction(function () use ($groupId, $actor, $note) {
            $group = DB::table('monitoring_alert_groups')->where('id', $groupId)->first();
            if ($group === null) {
                throw CaseException::groupNotFound($groupId);
            }

            $existing = DB::table('monitoring_cases')->where('group_id', $groupId)->value('id');
            if ($existing !== null) {
                throw CaseException::caseExists((int) $existing);
            }

            try {
                // Nested transaction = savepoint, so losing a race does not poison the outer one.
                $id = (int) DB::transaction(fn () => DB::table('monitoring_cases')->insertGetId([
                    'group_id' => $groupId,
                    'account_id' => $group->account_id,
                    'account_number' => $group->account_number,
                    'owner' => $actor,
                    'opened_by' => $actor,
                ]));
            } catch (UniqueConstraintViolationException) {
                throw CaseException::caseExists((int) DB::table('monitoring_cases')->where('group_id', $groupId)->value('id'));
            }

            $this->event($id, 'opened', $actor, $note);

            return $id;
        });
    }

    /** Any supervisor may add a note to an open case. */
    public function addNote(int $caseId, string $actor, string $note): void
    {
        $actor = $this->person($actor);
        $note = $this->required($note, 'note');

        DB::transaction(function () use ($caseId, $actor, $note) {
            $this->lockOpen($caseId);
            $this->event($caseId, 'note', $actor, $note);
        });
    }

    /** Hand the case to another supervisor. Anyone who manages cases may do this, so an absent owner never blocks work. */
    public function assign(int $caseId, string $actor, string $to, ?string $note = null): void
    {
        $actor = $this->person($actor);
        $to = $this->person($to);
        $note = $this->optional($note);

        DB::transaction(function () use ($caseId, $actor, $to, $note) {
            $case = $this->lockOpen($caseId);
            if ($case->owner === $to) {
                throw CaseException::invalid('That person already owns this case.');
            }

            DB::table('monitoring_cases')->where('id', $caseId)->update(['owner' => $to]);
            $this->event($caseId, 'assigned', $actor, $note, $to);
        });
    }

    public function close(int $caseId, string $actor, string $outcome, string $note): void
    {
        $actor = $this->person($actor);
        if (! in_array($outcome, self::OUTCOMES, true)) {
            throw CaseException::invalid('outcome must be one of: ' . implode(', ', self::OUTCOMES));
        }
        $note = $this->required($note, 'note');

        DB::transaction(function () use ($caseId, $actor, $outcome, $note) {
            $peek = DB::table('monitoring_cases')->where('id', $caseId)->first();
            if ($peek === null) {
                throw CaseException::notFound($caseId);
            }

            // Lock order: this account's alert lock first, then the case row. While we hold it,
            // the consumer cannot add an alert to this group, so closing really does seal it.
            DB::select('SELECT pg_advisory_xact_lock(?::int, ?::int)', [MonitoringConsumer::LOCK_NAMESPACE, (int) $peek->account_id]);

            $case = $this->lockOwnedOpen($caseId, $actor);

            $unresolved = (int) DB::table('monitoring_alerts')
                ->where('group_id', $case->group_id)->where('status', '<>', 'closed')->count();
            if ($unresolved > 0) {
                throw CaseException::alertsUnresolved($unresolved);
            }

            $suspicious = DB::table('monitoring_alerts')
                ->where('group_id', $case->group_id)->where('disposition', 'confirmed_suspicious')->exists();
            if ($suspicious && $outcome === 'no_further_action') {
                throw CaseException::outcomeConflict();
            }

            DB::table('monitoring_cases')->where('id', $caseId)->update([
                'status' => 'closed',
                'outcome' => $outcome,
                'closing_note' => $note,
                'closed_by' => $actor,
                'closed_at' => DB::raw('now()'),
            ]);
            $this->event($caseId, 'closed', $actor, $note);
        });
    }

    /** For the application layer, inside its own transaction: the case is open and the actor owns it. */
    public function lockOwnedOpen(int $caseId, string $actor): object
    {
        $case = $this->lockOpen($caseId);
        if ($case->owner !== $actor) {
            throw CaseException::notOwner($caseId);
        }

        return $case;
    }

    /** Called by the application layer after it has acted on the account. */
    public function recordAccountAction(int $caseId, string $actor, string $event, string $reason): void
    {
        if (! in_array($event, ['account_frozen', 'account_unfrozen'], true)) {
            throw CaseException::invalid('Unknown account action');
        }

        $this->event($caseId, $event, $this->person($actor), $this->required($reason, 'reason'));
    }

    public function lockOpen(int $caseId): object
    {
        $case = DB::table('monitoring_cases')->where('id', $caseId)->lockForUpdate()->first();
        if ($case === null) {
            throw CaseException::notFound($caseId);
        }
        if ($case->status !== 'open') {
            throw CaseException::closed($caseId);
        }

        return $case;
    }

    private function event(int $caseId, string $event, string $actor, ?string $note = null, ?string $assignedTo = null): void
    {
        DB::table('monitoring_case_events')->insert([
            'case_id' => $caseId,
            'event' => $event,
            'actor' => $actor,
            'note' => $note,
            'assigned_to' => $assignedTo,
        ]);
    }

    private function person(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '' || strlen($value) > 64) {
            throw CaseException::invalid('A person is required (1 to 64 characters).');
        }

        return $value;
    }

    private function required(string $value, string $field): string
    {
        $value = trim($value);
        if ($value === '') {
            throw CaseException::invalid("Please add a $field.");
        }

        return $value;
    }

    private function optional(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}