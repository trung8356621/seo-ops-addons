<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\Seeding\Http\Requests\SeedingTopicStoreRequest;
use Omnichannel\Addons\Seeding\Http\Requests\SeedingTopicUpdateRequest;
use Omnichannel\Addons\Seeding\Services\SeedingTopicService;
use Omnichannel\Addons\Seeding\Support\SeedingTopicPresenter;
use Throwable;

final class SeedingTopicController extends Controller
{
    public function __construct(
        private readonly SeedingTopicService $topics,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->assertPlanner();

        $siteId = (int) $request->query('site_id', 0);
        abort_unless($siteId > 0 && SeoAccessControl::canAccessSite($siteId), 403);

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
        $this->assertPlanner();

        $siteId = (int) $request->query('site_id', 0);
        abort_unless($siteId > 0 && SeoAccessControl::canAccessSite($siteId), 403);

        $model = $this->topics->findForSite($siteId, $topic);
        abort_if($model === null, 404);

        return response()->json([
            'ok' => true,
            'topic' => SeedingTopicPresenter::topic($model),
        ]);
    }

    public function store(SeedingTopicStoreRequest $request): JsonResponse
    {
        $this->assertPlanner();
        abort_unless(SeoAccessControl::canMutateInSeoPanel(), 403);

        $siteId = (int) $request->validated('site_id');
        abort_unless(SeoAccessControl::canAccessSite($siteId), 403);

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
        $this->assertPlanner();
        abort_unless(SeoAccessControl::canMutateInSeoPanel(), 403);

        $siteId = (int) $request->input('site_id', $request->query('site_id', 0));
        abort_unless($siteId > 0 && SeoAccessControl::canAccessSite($siteId), 403);

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
        $this->assertPlanner();
        abort_unless(SeoAccessControl::canMutateInSeoPanel(), 403);

        $siteId = (int) $request->query('site_id', $request->input('site_id', 0));
        abort_unless($siteId > 0 && SeoAccessControl::canAccessSite($siteId), 403);

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

    private function assertPlanner(): void
    {
        abort_unless(SeoAccessControl::canAccessPlannerFeatures(), 403);
        abort_unless(request()->user() instanceof User, 403);
    }
}
