<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Http\Controllers\TopicalMap;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicalMapReadModel;
use Omnichannel\Addons\SearchIntelligence\Support\TopicalMapAccess;

final class TopicalMapChildrenController extends Controller
{
    public function __construct(
        private readonly TopicalMapAccess $access,
        private readonly TopicalMapReadModel $readModel,
    ) {}

    public function __invoke(Request $request, int $topic): JsonResponse
    {
        $siteId = (int) $request->query('site_id', 0);
        $this->access->assertCanAccessSite($siteId);

        if ($topic <= 0) {
            return response()->json(['ok' => false, 'error' => 'invalid_args', 'children' => []], 422);
        }

        $children = $this->readModel->topicChildren($siteId, $topic);
        if ($children === null) {
            return response()->json(['ok' => false, 'error' => 'topic_not_found', 'children' => []], 404);
        }

        return response()->json(['ok' => true, 'error' => null] + $children->toArray());
    }
}
