<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Http\Controllers\TopicalMap;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicalMapReadModel;
use Omnichannel\Addons\SearchIntelligence\Support\TopicalMapAccess;

/**
 * Network API — single-state full Site → Topic → DNA graph.
 * topic_id is ignored (no drill / focus neighborhood).
 */
final class TopicalMapNetworkController extends Controller
{
    public function __construct(
        private readonly TopicalMapAccess $access,
        private readonly TopicalMapReadModel $readModel,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $siteId = (int) $request->query('site_id', 0);
        $this->access->assertCanAccessSite($siteId);

        $overview = $this->readModel->overview($siteId);
        $visibleIds = array_map(static fn (array $t): int => (int) $t['id'], $overview->topics);

        return response()->json([
            'ok' => true,
            'neighborhood' => $this->readModel->membershipNeighborhood($siteId, $visibleIds),
        ]);
    }
}
