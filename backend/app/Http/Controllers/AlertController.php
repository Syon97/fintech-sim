<?php

namespace App\Http\Controllers;

use App\Api\AlertReadModel;
use App\Models\User;
use App\Monitoring\AlertException;
use App\Monitoring\AlertWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class AlertController extends Controller
{
    public function index(Request $request, AlertReadModel $model): JsonResponse
    {
        $f = $request->validate([
            'status' => ['nullable', Rule::in(['unresolved', 'open', 'investigating', 'closed', 'all'])],
            'assigned' => ['nullable', Rule::in(['me', 'unassigned'])],
            'min_score' => ['nullable', 'integer', 'between:0,100'],
            'account' => ['nullable', 'string', 'max:32'],
            'group' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        return response()->json($model->list($f, $request->user()->username));
    }

    public function show(Request $request, int $alert, AlertReadModel $model): JsonResponse
    {
        $detail = $model->detail($alert, $request->user());

        return $detail === null ? $this->fail(AlertException::notFound($alert)) : response()->json($detail);
    }

    public function groups(Request $request, AlertReadModel $model): JsonResponse
    {
        $f = $request->validate([
            'state' => ['nullable', Rule::in(['unresolved', 'all'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        return response()->json($model->groups($f['state'] ?? 'unresolved', (int) ($f['page'] ?? 1), (int) ($f['per_page'] ?? 25)));
    }

    public function assignees(AlertReadModel $model): JsonResponse
    {
        return response()->json(['data' => $model->assignees()]);
    }

    public function start(Request $request, int $alert, AlertWorkflow $wf, AlertReadModel $model): JsonResponse
    {
        return $this->act($request, $alert, $model, fn () => $wf->start($alert, $request->user()->username));
    }

    public function release(Request $request, int $alert, AlertWorkflow $wf, AlertReadModel $model): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        return $this->act($request, $alert, $model, fn () => $wf->release($alert, $request->user()->username, $data['note'] ?? null));
    }

    public function close(Request $request, int $alert, AlertWorkflow $wf, AlertReadModel $model): JsonResponse
    {
        $data = $request->validate([
            'disposition' => ['required', Rule::in(AlertWorkflow::DISPOSITIONS)],
            'note' => ['required', 'string', 'max:2000'],
        ]);

        return $this->act($request, $alert, $model, fn () => $wf->close($alert, $request->user()->username, $data['disposition'], $data['note']));
    }

    public function reassign(Request $request, int $alert, AlertWorkflow $wf, AlertReadModel $model): JsonResponse
    {
        $data = $request->validate([
            'to' => ['required', 'string', 'max:64'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $to = strtolower(trim($data['to']));

        $target = User::where('username', $to)->where('active', true)->first();
        if ($target === null || ! Gate::forUser($target)->allows('alerts.work')) {
            return response()->json([
                'reason' => 'INVALID_ASSIGNEE',
                'message' => "That person can't take alerts. Choose an active analyst or supervisor.",
            ], 422);
        }

        return $this->act($request, $alert, $model, fn () => $wf->reassign($alert, $request->user()->username, $to, $data['note'] ?? null));
    }

    /** Runs a workflow step, then returns the alert as it now stands so the screen can update. */
    private function act(Request $request, int $id, AlertReadModel $model, callable $do): JsonResponse
    {
        try {
            $do();
        } catch (AlertException $e) {
            return $this->fail($e);
        }

        return response()->json($model->detail($id, $request->user()));
    }

    private function fail(AlertException $e): JsonResponse
    {
        $status = ['ALERT_NOT_FOUND' => 404, 'ILLEGAL_TRANSITION' => 409, 'NOT_ASSIGNEE' => 403][$e->reason] ?? 422;
        $message = [
            'ALERT_NOT_FOUND' => "We couldn't find that alert.",
            'ILLEGAL_TRANSITION' => 'This alert has moved on since you opened it. Refresh to see where it stands.',
            'NOT_ASSIGNEE' => 'Only the person working this alert can do that. A supervisor can reassign it.',
        ][$e->reason] ?? $e->getMessage();

        return response()->json(['reason' => $e->reason, 'message' => $message], $status);
    }
}