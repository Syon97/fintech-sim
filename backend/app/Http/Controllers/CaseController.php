<?php

namespace App\Http\Controllers;

use App\Api\CaseReadModel;
use App\Application\CaseActions;
use App\Ledger\Exceptions\LedgerException;
use App\Models\User;
use App\Monitoring\CaseException;
use App\Monitoring\CaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CaseController extends Controller
{
    public function index(Request $request, CaseReadModel $model): JsonResponse
    {
        $f = $request->validate([
            'status' => ['nullable', Rule::in(['open', 'closed', 'all'])],
            'owner' => ['nullable', Rule::in(['me'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        return response()->json($model->list($f, $request->user()->username));
    }

    public function show(Request $request, int $case, CaseReadModel $model): JsonResponse
    {
        $detail = $model->detail($case, $request->user());

        return $detail === null ? $this->fail(CaseException::notFound($case)) : response()->json($detail);
    }

    public function assignees(CaseReadModel $model): JsonResponse
    {
        return response()->json(['data' => $model->assignees()]);
    }

    public function open(Request $request, int $group, CaseService $cases, CaseReadModel $model): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        $id = null;
        $error = $this->attempt(function () use (&$id, $cases, $group, $request, $data) {
            $id = $cases->open($group, $request->user()->username, $data['note'] ?? null);
        });

        return $error ?? response()->json($model->detail($id, $request->user()), 201);
    }

    public function note(Request $request, int $case, CaseService $cases, CaseReadModel $model): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:4000']]);

        return $this->attempt(fn () => $cases->addNote($case, $request->user()->username, $data['note']))
            ?? response()->json($model->detail($case, $request->user()));
    }

    public function assign(Request $request, int $case, CaseService $cases, CaseReadModel $model): JsonResponse
    {
        $data = $request->validate([
            'to' => ['required', 'string', 'max:64'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $to = strtolower(trim($data['to']));

        $target = User::where('username', $to)->where('active', true)->first();
        if ($target === null || ! Gate::forUser($target)->allows('cases.manage')) {
            return response()->json([
                'reason' => 'INVALID_ASSIGNEE',
                'message' => "That person can't own cases. Choose an active supervisor.",
            ], 422);
        }

        return $this->attempt(fn () => $cases->assign($case, $request->user()->username, $to, $data['note'] ?? null))
            ?? response()->json($model->detail($case, $request->user()));
    }

    public function freeze(Request $request, int $case, CaseActions $actions, CaseReadModel $model): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return $this->attempt(fn () => $actions->freeze($case, $request->user()->username, $data['reason']))
            ?? response()->json($model->detail($case, $request->user()));
    }

    public function unfreeze(Request $request, int $case, CaseActions $actions, CaseReadModel $model): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return $this->attempt(fn () => $actions->unfreeze($case, $request->user()->username, $data['reason']))
            ?? response()->json($model->detail($case, $request->user()));
    }

    public function close(Request $request, int $case, CaseService $cases, CaseReadModel $model): JsonResponse
    {
        $data = $request->validate([
            'outcome' => ['required', Rule::in(CaseService::OUTCOMES)],
            'note' => ['required', 'string', 'max:2000'],
        ]);

        return $this->attempt(fn () => $cases->close($case, $request->user()->username, $data['outcome'], $data['note']))
            ?? response()->json($model->detail($case, $request->user()));
    }

    /** Runs the action. Returns an error response, or null when it worked. */
    private function attempt(callable $do): ?JsonResponse
    {
        try {
            $do();
        } catch (CaseException|LedgerException $e) {
            return $this->fail($e);
        }

        return null;
    }

    private function fail(CaseException|LedgerException $e): JsonResponse
    {
        $status = [
            'CASE_NOT_FOUND' => 404, 'GROUP_NOT_FOUND' => 404, 'CASE_EXISTS' => 409, 'CASE_CLOSED' => 409,
            'NOT_OWNER' => 403, 'ALERTS_UNRESOLVED' => 409, 'OUTCOME_CONFLICT' => 409,
            'ALREADY_FROZEN' => 409, 'NOT_FROZEN' => 409, 'ACCOUNT_CLOSED' => 409, 'UNKNOWN_ACCOUNT' => 404,
        ][$e->reason] ?? 422;

        $message = [
            'ALREADY_FROZEN' => 'This account is already frozen.',
            'NOT_FROZEN' => "This account isn't frozen, so there is nothing to lift.",
            'ACCOUNT_CLOSED' => "This account is closed and can't be frozen or unfrozen.",
            'UNKNOWN_ACCOUNT' => "We couldn't find that account.",
        ][$e->reason] ?? $e->getMessage();

        return response()->json([
            'reason' => $e->reason,
            'message' => $message,
            'context' => $e instanceof CaseException ? $e->context : [],
        ], $status);
    }
}