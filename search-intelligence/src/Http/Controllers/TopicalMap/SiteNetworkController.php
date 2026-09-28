<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Http\Controllers\TopicalMap;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\SiteNetworkReadModel;
use Omnichannel\Addons\SearchIntelligence\Support\TopicalMapAccess;

/**
 * Site Network API — directional cross-site managed-site graph.
 *
 * GET /seo/topical-map/api/site-network
 *   → overview: {sites, edges} with directional aggregates
 *
 * GET /seo/topical-map/api/site-network/topics?source_site={id}&target_site={id}
 *   → topics participating in the given site pair
 *
 * Direction is PRESERVED (A→B ≠ B→A).
 * CTA/social/contact links do NOT appear as edges.
 * Agent Runtime global retrieval stays UNSUPPORTED.
 */
final class SiteNetworkController extends Controller
{
    public function __construct(
        private readonly TopicalMapAccess $access,
        private readonly SiteNetworkReadModel $readModel,
    ) {}

    public function overview(Request $request): JsonResponse
    {
        $this->access->assertCanAccessGlobal();

        $overview = $this->readModel->overview();

        return response()->json([
            'ok' => true,
            'schema' => 'seo.site_network.v1',
            'sites' => $overview['sites'],
            'edges' => $overview['edges'],
            'accessible_site_count' => (int) ($overview['accessible_site_count'] ?? 0),
        ]);
    }

    public function topics(Request $request): JsonResponse
    {
        $this->access->assertCanAccessGlobal();

        $sourceSiteId = (int) $request->query('source_site', 0);
        $targetSiteId = (int) $request->query('target_site', 0);

        if ($sourceSiteId <= 0 || $targetSiteId <= 0) {
            return response()->json([
                'ok' => false,
                'message' => 'source_site and target_site query parameters are required.',
            ], 422);
        }

        $result = $this->readModel->topicsForSitePair($sourceSiteId, $targetSiteId);

        if ($result === null) {
            return response()->json([
                'ok' => false,
                'message' => 'Site pair not found or sites are not both managed.',
            ], 404);
        }

        return response()->json([
            'ok' => true,
            'schema' => 'seo.site_network.topics.v1',
            ...$result,
        ]);
    }
}
