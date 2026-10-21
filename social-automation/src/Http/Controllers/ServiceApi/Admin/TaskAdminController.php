<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Http\Controllers\ServiceApi\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Omnichannel\Addons\SocialAutomation\Models\SaSchedule;
use Omnichannel\Addons\SocialAutomation\Models\SaTask;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationSchema;

final class TaskAdminController extends Controller
{
    public function index(): JsonResponse
    {
        if (! SocialAutomationSchema::jobsReady()) {
            return response()->json(['ok' => true, 'tasks' => [], 'pending' => 'PENDING DB MIGRATION']);
        }

        $tasks = SaTask::query()->orderByDesc('id')->limit(100)->get()->map(static fn (SaTask $t): array => [
            'task_uuid' => $t->task_uuid,
            'name' => $t->name,
            'status' => $t->status,
            'package_key' => $t->package_key,
            'package_version' => $t->package_version,
            'package_kind' => $t->package_kind,
            'target_account_count' => $t->target_account_count,
        ])->all();

        return response()->json(['ok' => true, 'tasks' => $tasks]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! SocialAutomationSchema::jobsReady()) {
            return response()->json(['ok' => false, 'errors' => ['schema_unavailable'], 'pending' => 'PENDING DB MIGRATION'], 503);
        }

        $name = trim((string) $request->input('name', ''));
        $packageKey = trim((string) $request->input('package_key', ''));
        $version = trim((string) $request->input('package_version', ''));
        $kind = strtolower(trim((string) $request->input('package_kind', 'widget')));
        if ($name === '' || $packageKey === '' || $version === '' || ! in_array($kind, ['widget', 'workflow'], true)) {
            return response()->json(['ok' => false, 'errors' => ['validation_failed']], 422);
        }

        $task = SaTask::query()->create([
            'task_uuid' => (string) Str::uuid(),
            'name' => $name,
            'status' => (string) ($request->input('status', 'active')),
            'package_key' => $packageKey,
            'package_version' => $version,
            'package_kind' => $kind,
            'content_digest' => $request->input('content_digest'),
            'inputs' => is_array($request->input('inputs')) ? $request->input('inputs') : [],
            'target_account_count' => max(1, (int) $request->input('target_account_count', 1)),
            'eligibility' => is_array($request->input('eligibility')) ? $request->input('eligibility') : [],
            'execution_policy' => is_array($request->input('execution_policy')) ? $request->input('execution_policy') : [],
        ]);

        return response()->json(['ok' => true, 'task' => ['task_uuid' => $task->task_uuid]], 201);
    }

    public function storeSchedule(Request $request): JsonResponse
    {
        if (! SocialAutomationSchema::schedulesReady()) {
            return response()->json(['ok' => false, 'errors' => ['schema_unavailable'], 'pending' => 'PENDING DB MIGRATION'], 503);
        }

        $task = SaTask::query()->where('task_uuid', (string) $request->input('task_uuid'))->first();
        if (! $task instanceof SaTask) {
            return response()->json(['ok' => false, 'errors' => ['task_not_found']], 404);
        }

        $cron = trim((string) $request->input('cron_expression', ''));
        if ($cron === '') {
            return response()->json(['ok' => false, 'errors' => ['cron_required']], 422);
        }

        $schedule = SaSchedule::query()->create([
            'schedule_uuid' => (string) Str::uuid(),
            'task_id' => $task->id,
            'cron_expression' => $cron,
            'timezone' => (string) ($request->input('timezone', 'UTC')),
            'enabled' => (bool) $request->input('enabled', true),
            'target_account_count' => $request->filled('target_account_count')
                ? (int) $request->input('target_account_count') : null,
            'execution_policy' => is_array($request->input('execution_policy')) ? $request->input('execution_policy') : null,
            'input_source' => is_array($request->input('input_source')) ? $request->input('input_source') : null,
            'package_version_pin' => $request->input('package_version_pin'),
        ]);

        return response()->json(['ok' => true, 'schedule' => ['schedule_uuid' => $schedule->schedule_uuid]], 201);
    }
}
