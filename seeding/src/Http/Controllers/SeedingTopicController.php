<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Omnichannel\Addons\Seeding\Http\Requests\SeedingTopicStoreRequest;
use Omnichannel\Addons\Seeding\Http\Requests\SeedingTopicUpdateRequest;
use Omnichannel\Addons\Seeding\Services\SeedingTopicService;
use Omnichannel\Addons\Seeding\Support\SeedingAccess;
use Omnichannel\Addons\Seeding\Support\SeedingTopicPresenter;
use Throwable;

final class SeedingTopicController extends Controller
{
    public function __construct(
        private readonly SeedingTopicService $topics,
        private readonly SeedingAccess $access,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->access->assertCanAccess();

        $siteId = (int) $request->query('site_id', 0);
        $this->access->assertCanAccessSite($siteId);

        $archived = filter_var($request->query('archived', false), FILTER_VALIDATE_BOOL);

        $items = $this->topics->listForSite($siteId, $archived);

        return response()->json([
            'ok' => true,
            'topics' => SeedingTopicPresenter::collection($items->all()),
            'archived_count' => $this->topics->archivedCountForSite($siteId),
        ]);
    }

    public function show(Request $request, int $topic): JsonResponse
    {
        $this->access->assertCanAccess();

        $siteId = (int) $request->query('site_id', 0);
        $this->access->assertCanAccessSite($siteId);

        $model = $this->topics->findForSite($siteId, $topic);
        abort_if($model === null, 404);

        return response()->json([
            'ok' => true,
            'topic' => SeedingTopicPresenter::topic($model),
        ]);
    }

    public function store(SeedingTopicStoreRequest $request): JsonResponse
    {
        $this->access->assertCanMutate();

        $siteId = (int) $request->validated('site_id');
        $this->access->assertCanAccessSite($siteId);

        try {
            $topic = $this->topics->create([
                'site_id' => $siteId,
                'created_by' => $request->user() instanceof User ? (int) $request->user()->id : null,
                'full_text' => (string) ($request->validated('full_text') ?? ''),
                'source_html' => $request->validated('source_html'),
                'social_url' => $request->validated('social_url'),
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'message' => 'Could not create topic'], 500);
        }

        return response()->json([
            'ok' => true,
            'topic' => SeedingTopicPresenter::topic($topic),
            'archived_count' => $this->topics->archivedCountForSite($siteId),
        ], 201);
    }

    public function update(SeedingTopicUpdateRequest $request, int $topic): JsonResponse
    {
        $this->access->assertCanMutate();

        $siteId = (int) $request->input('site_id', $request->query('site_id', 0));
        $this->access->assertCanAccessSite($siteId);

        $model = $this->topics->findForSite($siteId, $topic);
        abort_if($model === null, 404);

        $payload = [];
        $validated = $request->validated();
        foreach (['full_text', 'source_html', 'social_url', 'archived'] as $key) {
            if (array_key_exists($key, $validated)) {
                $payload[$key] = $validated[$key];
            }
        }

        try {
            $updated = $this->topics->update($model, $payload);
        } catch (InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'message' => 'Could not update topic'], 500);
        }

        return response()->json([
            'ok' => true,
            'topic' => SeedingTopicPresenter::topic($updated),
            'archived_count' => $this->topics->archivedCountForSite($siteId),
        ]);
    }

    public function destroy(Request $request, int $topic): JsonResponse
    {
        $this->access->assertCanMutate();

        $siteId = (int) $request->query('site_id', $request->input('site_id', 0));
        $this->access->assertCanAccessSite($siteId);

        $model = $this->topics->findForSite($siteId, $topic);
        abort_if($model === null, 404);

        try {
            $this->topics->deleteDraft($model);
        } catch (InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            'archived_count' => $this->topics->archivedCountForSite($siteId),
        ]);
    }
}
