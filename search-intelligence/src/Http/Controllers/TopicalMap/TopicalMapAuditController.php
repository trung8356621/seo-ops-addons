<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Http\Controllers\TopicalMap;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\TopicalMapAuditHistoryLinker;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicalMapAuditService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicalMapAuditStatusService;
use Omnichannel\Addons\SearchIntelligence\Support\TopicalMapAccess;
use Throwable;

/**
 * Manual AI Audit & Tags only — never called implicitly by overview/network/recluster.
 */
final class TopicalMapAuditController extends Controller
{
    public function __construct(
        private readonly TopicalMapAccess $access,
        private readonly TopicalMapAuditService $auditService,
        private readonly TopicalMapAuditStatusService $status,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $siteId = (int) $request->input('site_id', $request->query('site_id', 0));
        $this->access->assertCanMutateSite($siteId);

        $domain = $this->access->siteDomain($siteId);
        $snapshot = $this->status->snapshot($siteId, $domain);
        if (! ($snapshot['can_run'] ?? false)) {
            return response()->json([
                'ok' => false,
                'message' => 'AI Audit & Tags is unavailable for this site.',
                'status' => $snapshot,
            ], 422);
        }

        $actorId = auth()->id();
        $actor = is_numeric($actorId) ? (int) $actorId : null;

        try {
            $result = $this->auditService->audit($siteId, $actor);

            try {
                app(TopicalMapAuditHistoryLinker::class)->linkFromAuditResult($siteId, $actor, $result);
            } catch (Throwable $linkError) {
                report($linkError);
            }

            $fresh = $this->status->snapshot($siteId, $domain);
            $fresh['can_run'] = (bool) ($fresh['can_run'] ?? false) && $this->access->canMutateSite($siteId);

            return response()->json([
                'ok' => (bool) ($result['ok'] ?? false),
                'message' => (string) ($result['message'] ?? ''),
                'payload' => is_array($result['payload'] ?? null) ? $result['payload'] : null,
                'tag_apply' => is_array($result['tag_apply'] ?? null) ? $result['tag_apply'] : null,
                'prompt_result_id' => $result['prompt_result_id'] ?? null,
                'status' => $fresh,
            ], ($result['ok'] ?? false) ? 200 : 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
