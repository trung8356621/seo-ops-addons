<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Http\Controllers\ServiceApi\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Omnichannel\Addons\SocialAutomation\Models\SaTask;
use Omnichannel\Addons\SocialAutomation\Orchestration\JobOrchestrator;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationSchema;

/**
 * Admin-only manual dispatch for integration testing.
 * Not exposed to machine-rental operators as a self-serve runner UI.
 */
final class ManualDispatchController extends Controller
{
    public function __construct(
        private readonly JobOrchestrator $orchestrator,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        if (! SocialAutomationSchema::jobsReady()) {
            return response()->json(['ok' => false, 'errors' => ['schema_unavailable']], 503);
        }

        $taskUuid = trim((string) $request->input('task_uuid', ''));
        $task = SaTask::query()->where('task_uuid', $taskUuid)->first();
        if (! $task instanceof SaTask) {
            return response()->json(['ok' => false, 'errors' => ['task_not_found']], 404);
        }

        $target = $request->filled('target_count') ? (int) $request->input('target_count') : null;
        $result = $this->orchestrator->manualDispatch($task, $target);

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }
}
