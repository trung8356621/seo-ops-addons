<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Http\Controllers\TopicalMap;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicalMapAuditStatusService;
use Omnichannel\Addons\SearchIntelligence\Support\TopicalMapAccess;

final class TopicalMapAuditStatusController extends Controller
{
    public function __construct(
        private readonly TopicalMapAccess $access,
        private readonly TopicalMapAuditStatusService $status,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $siteId = (int) $request->query('site_id', 0);
        $this->access->assertCanAccessSite($siteId);

        $domain = $this->access->siteDomain($siteId);
        $snapshot = $this->status->snapshot($siteId, $domain);
        $canMutate = $this->access->canMutateSite($siteId);
        $snapshot['can_run'] = (bool) ($snapshot['can_run'] ?? false) && $canMutate;

        return response()->json([
            'ok' => true,
            'status' => $snapshot,
        ]);
    }
}
