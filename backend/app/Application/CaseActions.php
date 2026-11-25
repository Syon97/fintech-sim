<?php

namespace App\Application;

use App\Ledger\AccountService;
use App\Monitoring\CaseService;
use Illuminate\Support\Facades\DB;

/**
 * Where a monitoring decision becomes a ledger action. Monitoring never calls the ledger and the
 * ledger never knows monitoring exists; this layer connects them. The freeze and the case record
 * commit together or not at all.
 */
final class CaseActions
{
    public function __construct(
        private readonly CaseService $cases,
        private readonly AccountService $accounts,
    ) {}

    public function freeze(int $caseId, string $actor, string $reason): void
    {
        $actor = strtolower(trim($actor));

        DB::transaction(function () use ($caseId, $actor, $reason) {
            $case = $this->cases->lockOwnedOpen($caseId, $actor);
            $this->accounts->freeze((int) $case->account_id, $actor, $reason);
            $this->cases->recordAccountAction($caseId, $actor, 'account_frozen', $reason);
        });
    }

    public function unfreeze(int $caseId, string $actor, string $reason): void
    {
        $actor = strtolower(trim($actor));

        DB::transaction(function () use ($caseId, $actor, $reason) {
            $case = $this->cases->lockOwnedOpen($caseId, $actor);
            $this->accounts->unfreeze((int) $case->account_id, $actor, $reason);
            $this->cases->recordAccountAction($caseId, $actor, 'account_unfrozen', $reason);
        });
    }
}