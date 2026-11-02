<?php

namespace App\Monitoring;

use Illuminate\Support\Facades\DB;

final class AlertWorkflow
{
    public const DISPOSITIONS = ['false_positive', 'confirmed_suspicious'];

    public function start(int $alertId, string $actor): void
    {
        $this->assertActor($actor);
        $this->move($alertId, 'open', 'investigating', $actor, null, ['assigned_to' => $actor], false);
    }

    public function release(int $alertId, string $actor, ?string $note = null): void
    {
        $this->assertActor($actor);
        $this->move($alertId, 'investigating', 'open', $actor, $note, ['assigned_to' => null], true);
    }

    public function close(int $alertId, string $actor, string $disposition, string $note): void
    {
        $this->assertActor($actor);
        if (! in_array($disposition, self::DISPOSITIONS, true)) {
            throw AlertException::invalidInput('disposition must be one of: ' . implode(', ', self::DISPOSITIONS));
        }
        if (trim($note) === '') {
            throw AlertException::invalidInput('A note explaining the decision is required to close an alert');
        }

        $this->move($alertId, 'investigating', 'closed', $actor, $note,
            ['disposition' => $disposition, 'closed_at' => now()], true);
    }

    private function move(int $alertId, string $from, string $to, string $actor, ?string $note, array $changes, bool $requireAssignee): void
    {
        DB::transaction(function () use ($alertId, $from, $to, $actor, $note, $changes, $requireAssignee) {
            $alert = DB::table('monitoring_alerts')->where('id', $alertId)->lockForUpdate()->first();

            if ($alert === null) {
                throw AlertException::notFound($alertId);
            }
            if ($alert->status !== $from) {
                throw AlertException::illegalTransition($alertId, $alert->status, $to);
            }
            if ($requireAssignee && $alert->assigned_to !== $actor) {
                throw AlertException::notAssignee($alertId, $actor);
            }

            DB::table('monitoring_alerts')->where('id', $alertId)
                ->update($changes + ['status' => $to, 'updated_at' => now()]);

            DB::table('monitoring_alert_events')->insert([
                'alert_id' => $alertId,
                'from_status' => $from,
                'to_status' => $to,
                'actor' => $actor,
                'note' => $note,
            ]);
        });
    }

    private function assertActor(string $actor): void
    {
        if (trim($actor) === '' || strlen($actor) > 64) {
            throw AlertException::invalidInput('actor must be 1 to 64 characters');
        }
    }
}