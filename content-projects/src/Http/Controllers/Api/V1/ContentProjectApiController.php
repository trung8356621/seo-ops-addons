<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Http\Controllers\Api\V1;

use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\AddContentProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ApproveProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ArchiveContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\AutoScheduleProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\CancelProjectItemPublishingCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\CreateContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\GenerateProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\PublishProjectItemsNowCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\RestoreContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\RetryProjectItemPublishingCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ScheduleProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\SkipProjectItemPublishingCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\StartReviewCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\UpdateContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\UpdateContentProjectItemCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBus;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectPublicRef;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectReadModelService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use RuntimeException;

/**
 * REST API v1 Content Project — chỉ gọi Application Command / ReadModel.
 * Không expose Run History / internal execution model.
 */
final class ContentProjectApiController extends Controller
{
    public function __construct(
        private readonly ContentProjectCommandBus $bus,
        private readonly ContentProjectReadModelService $reads,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $query = SeoProject::query()->orderByDesc('id')->limit(100);
        if ($actor->siteId !== null && $actor->siteId > 0) {
            $query->where('site_id', $actor->siteId);
        }

        $rows = [];
        foreach ($query->get() as $project) {
            try {
                $rows[] = $this->reads->project($project, $actor)->toArray();
            } catch (RuntimeException) {
                continue;
            }
        }

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->respond($this->bus->dispatch(
            new CreateContentProjectCommand($request->all()),
            $this->actor($request),
        ), 201);
    }

    public function show(Request $request, string $projectRef): JsonResponse
    {
        $actor = $this->actor($request);
        $project = $this->findProject($projectRef);

        return response()->json([
            'success' => true,
            'data' => $this->reads->project($project, $actor)->toArray(),
        ]);
    }

    public function update(Request $request, string $projectRef): JsonResponse
    {
        return $this->respond($this->bus->dispatch(
            new UpdateContentProjectCommand($projectRef, $request->all()),
            $this->actor($request),
        ));
    }

    public function addItems(Request $request, string $projectRef): JsonResponse
    {
        $items = $request->input('items', []);
        if (! is_array($items)) {
            $items = [];
        }

        return $this->respond($this->bus->dispatch(
            new AddContentProjectItemsCommand($projectRef, $items),
            $this->actor($request),
        ), 201);
    }

    public function items(Request $request, string $projectRef): JsonResponse
    {
        $actor = $this->actor($request);
        $project = $this->findProject($projectRef);
        $data = array_map(
            static fn ($dto) => $dto->toArray(),
            $this->reads->items($project, $actor),
        );

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function updateItem(Request $request, string $itemRef): JsonResponse
    {
        return $this->respond($this->bus->dispatch(
            new UpdateContentProjectItemCommand($itemRef, $request->all()),
            $this->actor($request),
        ));
    }

    public function generate(Request $request, string $projectRef): JsonResponse
    {
        return $this->respond($this->bus->dispatch(
            new GenerateProjectItemsCommand(
                $projectRef,
                $this->refs($request, 'item_refs'),
                (string) $request->input('mode', 'full'),
            ),
            $this->actor($request),
        ));
    }

    public function review(Request $request, string $projectRef): JsonResponse
    {
        return $this->respond($this->bus->dispatch(
            new StartReviewCommand($projectRef, $this->refs($request, 'item_refs')),
            $this->actor($request),
        ));
    }

    public function approve(Request $request, string $projectRef): JsonResponse
    {
        return $this->respond($this->bus->dispatch(
            new ApproveProjectItemsCommand($projectRef, $this->refs($request, 'item_refs')),
            $this->actor($request),
        ));
    }

    public function schedule(Request $request, string $projectRef): JsonResponse
    {
        $at = Carbon::parse((string) $request->input('scheduled_at', now()->addHour()->toIso8601String()));

        return $this->respond($this->bus->dispatch(
            new ScheduleProjectItemsCommand(
                $projectRef,
                $this->refs($request, 'item_refs'),
                $at,
                (bool) $request->boolean('dry_run'),
            ),
            $this->actor($request),
        ));
    }

    public function autoSchedule(Request $request, string $projectRef): JsonResponse
    {
        return $this->respond($this->bus->dispatch(
            new AutoScheduleProjectItemsCommand(
                $projectRef,
                $this->refs($request, 'item_refs'),
                is_array($request->input('options')) ? $request->input('options') : $request->except(['item_refs', 'dry_run', 'idempotency_key']),
                (bool) $request->boolean('dry_run'),
            ),
            $this->actor($request),
        ));
    }

    public function publishNow(Request $request, string $projectRef): JsonResponse
    {
        return $this->respond($this->bus->dispatch(
            new PublishProjectItemsNowCommand(
                $projectRef,
                $this->refs($request, 'item_refs'),
                (bool) $request->boolean('dry_run'),
                $request->input('confirmation_token'),
            ),
            $this->actor($request),
        ));
    }

    public function retryPublish(Request $request, string $itemRef): JsonResponse
    {
        [$projectRef, $itemIds] = $this->resolveItemCommand($itemRef);

        return $this->respond($this->bus->dispatch(
            new RetryProjectItemPublishingCommand($projectRef, $itemIds),
            $this->actor($request),
        ));
    }

    public function skipPublish(Request $request, string $itemRef): JsonResponse
    {
        [$projectRef, $itemIds] = $this->resolveItemCommand($itemRef);

        return $this->respond($this->bus->dispatch(
            new SkipProjectItemPublishingCommand($projectRef, $itemIds),
            $this->actor($request),
        ));
    }

    public function cancelPublish(Request $request, string $itemRef): JsonResponse
    {
        [$projectRef, $itemIds] = $this->resolveItemCommand($itemRef);

        return $this->respond($this->bus->dispatch(
            new CancelProjectItemPublishingCommand(
                $projectRef,
                $itemIds,
                (bool) $request->boolean('dry_run'),
                $request->input('confirmation_token'),
            ),
            $this->actor($request),
        ));
    }

    public function archive(Request $request, string $projectRef): JsonResponse
    {
        return $this->respond($this->bus->dispatch(
            new ArchiveContentProjectCommand(
                $projectRef,
                $request->input('note'),
                (bool) $request->boolean('confirm_waiting_publish'),
                (bool) $request->boolean('dry_run'),
                $request->input('confirmation_token'),
                (bool) $request->boolean('confirm_hidden_stale_runs'),
            ),
            $this->actor($request),
        ));
    }

    public function restore(Request $request, string $projectRef): JsonResponse
    {
        return $this->respond($this->bus->dispatch(
            new RestoreContentProjectCommand(
                $projectRef,
                (bool) $request->boolean('dry_run'),
                $request->input('confirmation_token'),
            ),
            $this->actor($request),
        ));
    }

    public function runtime(Request $request, string $projectRef): JsonResponse
    {
        $actor = $this->actor($request);
        $project = $this->findProject($projectRef);

        return response()->json([
            'success' => true,
            'data' => $this->reads->runtime($project, $actor)->toArray(),
        ]);
    }

    public function timeline(Request $request, string $projectRef): JsonResponse
    {
        $actor = $this->actor($request);
        $project = $this->findProject($projectRef);
        $data = array_map(
            static fn ($dto) => $dto->toArray(),
            $this->reads->timeline($project, $actor),
        );

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function publishingQueue(Request $request, string $projectRef): JsonResponse
    {
        $actor = $this->actor($request);
        $project = $this->findProject($projectRef);
        $data = array_map(
            static fn ($dto) => $dto->toArray(),
            $this->reads->publishingQueue($project, $actor),
        );

        return response()->json(['success' => true, 'data' => $data]);
    }

    private function actor(Request $request): ActorContext
    {
        $user = $request->user();
        $siteId = (int) $request->input('site_id', 0);
        $idem = $request->header('Idempotency-Key')
            ?? $request->input('idempotency_key');

        return ActorContext::api(
            $user !== null ? (int) $user->id : null,
            $siteId > 0 ? $siteId : null,
            is_string($idem) && $idem !== '' ? $idem : null,
        );
    }

    private function findProject(string $projectRef): SeoProject
    {
        try {
            $id = ContentProjectPublicRef::resolveProjectIdStrict($projectRef);
        } catch (\InvalidArgumentException) {
            abort(404, 'Project not found.');
        }

        $project = SeoProject::query()->find($id);
        if (! $project instanceof SeoProject) {
            abort(404, 'Project not found.');
        }

        return $project;
    }

    /**
     * @return list<int|string>
     */
    private function refs(Request $request, string $key): array
    {
        $raw = $request->input($key, []);
        if (! is_array($raw)) {
            return [];
        }

        return array_values($raw);
    }

    /**
     * @return array{0: int, 1: list<int>}
     */
    private function resolveItemCommand(string $itemRef): array
    {
        try {
            $itemId = ContentProjectPublicRef::resolveItemIdStrict($itemRef);
        } catch (\InvalidArgumentException) {
            abort(404, 'Item not found.');
        }

        $task = SeoProjectTask::query()->find($itemId);
        if (! $task instanceof SeoProjectTask) {
            abort(404, 'Item not found.');
        }

        return [(int) $task->project_id, [$itemId]];
    }

    private function respond(ContentProjectActionResult $result, int $successStatus = 200): JsonResponse
    {
        if ($result->success) {
            $status = $this->isAsyncSuccessCode($result->code) ? 202 : $successStatus;
        } else {
            $status = $this->httpStatusFor($result);
        }

        return response()->json($result->toApiArray(), $status);
    }

    private function isAsyncSuccessCode(string $code): bool
    {
        return in_array($code, [
            ContentProjectActionCodes::PROCESSING,
            ContentProjectActionCodes::ITEMS_GENERATE_REQUESTED,
            ContentProjectActionCodes::ITEMS_PUBLISH_QUEUED,
            ContentProjectActionCodes::IDEMPOTENT_REPLAY,
        ], true);
    }

    private function httpStatusFor(ContentProjectActionResult $result): int
    {
        return match ($result->code) {
            ContentProjectActionCodes::FORBIDDEN => 403,
            ContentProjectActionCodes::PROJECT_NOT_FOUND, ContentProjectActionCodes::ITEMS_NOT_FOUND => 404,
            ContentProjectActionCodes::CONFIRMATION_REQUIRED, ContentProjectActionCodes::PREVIEW_READY => 409,
            ContentProjectActionCodes::LOCK_BUSY,
            ContentProjectActionCodes::OPERATION_LOCKED,
            ContentProjectActionCodes::OPERATION_ALREADY_PROCESSING,
            ContentProjectActionCodes::PUBLISHING_ALREADY_PROCESSING,
            ContentProjectActionCodes::PROCESSING => 409,
            ContentProjectActionCodes::WORDPRESS_UNAVAILABLE => 503,
            ContentProjectActionCodes::VALIDATION_FAILED, ContentProjectActionCodes::LIFECYCLE_INVALID => 422,
            ContentProjectActionCodes::QUOTA_DENIED => 429,
            default => 400,
        };
    }
}
