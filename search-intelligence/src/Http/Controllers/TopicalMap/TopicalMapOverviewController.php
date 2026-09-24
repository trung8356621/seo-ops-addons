<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Http\Controllers\TopicalMap;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicalMapReadModel;
use Omnichannel\Addons\SearchIntelligence\Support\TopicalMapAccess;

final class TopicalMapOverviewController extends Controller
{
    public function __construct(
        private readonly TopicalMapAccess $access,
        private readonly TopicalMapReadModel $readModel,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $siteId = (int) $request->query('site_id', 0);
        $this->access->assertCanAccessSite($siteId);

        $payload = $this->readModel->overview($siteId)->toArray();
        $payload['empty'] = ($payload['topics'] ?? []) === []
            && (int) ($payload['summary']['topic_count'] ?? 0) === 0;

        return response()->json([
            'ok' => true,
            'overview' => $payload,
        ]);
    }
}
