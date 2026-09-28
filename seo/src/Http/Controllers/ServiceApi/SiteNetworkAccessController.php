<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Http\Controllers\ServiceApi;

use App\Api\Middleware\ResolveTemporaryServiceAccess;
use App\Api\Services\ServiceApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\SiteNetworkReadModel;

/**
 * Global Site Network resource (read-only, no write, no Agent Runtime).
 *
 * This is the ONLY global cross-site resource added by this task.
 * It does NOT enable global Agent Runtime retrieval.
 *
 * Routes (site-bound token is NOT required — global scope):
 *   GET /api/v1/access/{globalToken}/site-network
 *   GET /api/v1/access/{globalToken}/site-network/topics?source_site={id}&target_site={id}
 *
 * A global access token must be minted without a site_id (see API docs).
 * Direction is preserved. A→B and B→A are separate edges.
 */
final class SiteNetworkAccessController
{
    public function __construct(
        private readonly SiteNetworkReadModel $readModel,
    ) {}

    public function overview(Request $request, string $token): JsonResponse
    {
        $overview = $this->readModel->overview();

        return $this->jsonData([
            'schema' => 'seo.site_network.v1',
            'sites' => $overview['sites'],
            'edges' => $overview['edges'],
            'note' => 'Direction preserved. A→B and B→A are separate edges.',
        ]);
    }

    public function topics(Request $request, string $token): JsonResponse
    {
        $sourceSiteId = (int) $request->query('source_site', 0);
        $targetSiteId = (int) $request->query('target_site', 0);

        if ($sourceSiteId <= 0 || $targetSiteId <= 0) {
            return ServiceApiError::validationFailed('source_site and target_site query parameters are required positive integers.');
        }

        $result = $this->readModel->topicsForSitePair($sourceSiteId, $targetSiteId);

        if ($result === null) {
            return ServiceApiError::notFound('Site pair not found or both sites must be managed.');
        }

        return $this->jsonData([
            'schema' => 'seo.site_network.topics.v1',
            ...$result,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function jsonData(array $data): JsonResponse
    {
        return response()->json(['data' => $data])->header('Cache-Control', 'no-store');
    }
}
