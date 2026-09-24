<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Http\Controllers\TopicalMap;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicalMapReadModel;
use Omnichannel\Addons\SearchIntelligence\Support\TopicalMapAccess;

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

        $topicId = $request->query('topic_id');
        $focusId = is_numeric($topicId) ? (int) $topicId : null;

        $overview = $this->readModel->overview($siteId);
        $visibleIds = array_map(static fn (array $t): int => (int) $t['id'], $overview->topics);

        if ($focusId !== null && $focusId > 0) {
            if (! in_array($focusId, $visibleIds, true)) {
                return response()->json([
                    'ok' => true,
                    'neighborhood' => [
                        'nodes' => [],
                        'links' => [],
                        'truncated' => false,
                        'showing_topics' => 0,
                        'total_topics' => count($visibleIds),
                    ],
                ]);
            }
            $ids = [$focusId];
        } else {
            $ids = array_slice($visibleIds, 0, 40);
        }

        return response()->json([
            'ok' => true,
            'neighborhood' => $this->readModel->membershipNeighborhood($siteId, $ids),
        ]);
    }
}
