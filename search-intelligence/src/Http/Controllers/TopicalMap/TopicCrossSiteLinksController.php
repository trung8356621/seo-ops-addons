<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Http\Controllers\TopicalMap;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\KeywordExternalRelationshipReadModel;
use Omnichannel\Addons\SearchIntelligence\Support\TopicalMapAccess;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;

/**
 * Keyword-level cross-site rows for one source topic.
 * Uses KeywordExternalRelationshipReadModel — not a second graph.
 */
final class TopicCrossSiteLinksController extends Controller
{
    public function __construct(
        private readonly TopicalMapAccess $access,
        private readonly KeywordExternalRelationshipReadModel $readModel,
    ) {}

    public function __invoke(Request $request, int $topic): JsonResponse
    {
        $siteId = (int) $request->query('site_id', 0);
        $this->access->assertCanAccessSite($siteId);

        if ($topic <= 0) {
            return response()->json([
                'ok' => false,
                'message' => 'topic is required.',
            ], 422);
        }

        $result = $this->readModel->forTopic($siteId, $topic, 40);

        return response()->json([
            'ok' => true,
            'schema' => 'seo.topic_cross_site_links.v1',
            'topic_id' => $topic,
            'site_id' => $siteId,
            'available' => $result['available'],
            'items' => $this->withAccessibleSiteDomains($result['items']),
        ]);
    }

    /**
     * Domains only for sites the current account can access.
     * Missing domain stays blank so the UI can show an unresolved site label.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function withAccessibleSiteDomains(array $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            foreach (['target_site_id', 'source_site_id'] as $key) {
                $id = (int) ($item[$key] ?? 0);
                if ($id > 0) {
                    $ids[$id] = true;
                }
            }
        }

        $domains = [];
        if ($ids !== []) {
            $domains = SeoAccessControl::accessibleSitesQuery()
                ->whereIn('id', array_keys($ids))
                ->pluck('domain', 'id')
                ->all();
        }

        foreach ($items as $index => $item) {
            $targetId = (int) ($item['target_site_id'] ?? 0);
            $sourceId = (int) ($item['source_site_id'] ?? 0);
            $items[$index]['target_site_domain'] = $targetId > 0
                ? trim((string) ($domains[$targetId] ?? $domains[(string) $targetId] ?? ''))
                : '';
            $items[$index]['source_site_domain'] = $sourceId > 0
                ? trim((string) ($domains[$sourceId] ?? $domains[(string) $sourceId] ?? ''))
                : '';
        }

        return $items;
    }
}
