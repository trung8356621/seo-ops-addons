<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Http\Controllers\TopicalMap;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicalMapReadModel;
use Omnichannel\Addons\SearchIntelligence\Support\TopicalMapAccess;

final class TopicalMapTagsController extends Controller
{
    public function __construct(
        private readonly TopicalMapAccess $access,
        private readonly TopicalMapReadModel $readModel,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $siteId = (int) $request->query('site_id', 0);
        $this->access->assertCanAccessSite($siteId);

        $overview = $this->readModel->overview($siteId)->toArray();

        return response()->json([
            'ok' => true,
            'tag_facets' => $overview['tag_facets'] ?? [],
            'untagged_count' => (int) ($overview['summary']['untagged_count'] ?? 0),
        ]);
    }
}
