<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaManager;

use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Throwable;

/**
 * Read-only global Idea warehouse across authorized per-site workspaces.
 *
 * Never merges multi-site note_items into a single workspace write.
 * Each Idea is stamped with owner_site_id / owner_domain for identity + save routing.
 */
final class IdeaWorkspaceWarehouseReadService
{
    public function __construct(
        private readonly IdeaWorkspacePersistenceService $workspaces,
    ) {}

    /**
     * @return array{
     *   status: string,
     *   note_items: list<array<string, mixed>>,
     *   item_count: int,
     *   sites: array<string, array{
     *     site_id: int,
     *     domain: string,
     *     revision: int,
     *     content_hash: string,
     *     updated_at: string|null,
     *     item_count: int
     *   }>,
     *   revision: int,
     *   content_hash: string,
     *   updated_at: string|null,
     *   site_id: int,
     *   domain: string
     * }
     */
    public function loadAuthorized(?int $actorUserId = null): array
    {
        abort_unless(SeoAccessControl::canManageContentProjectWorkflow(), 403);

        $sites = SeoAccessControl::accessibleSitesQuery()
            ->orderBy('domain')
            ->get(['id', 'domain']);

        $items = [];
        $siteMeta = [];
        $latestUpdated = null;

        foreach ($sites as $site) {
            $siteId = (int) $site->getKey();
            if ($siteId <= 0) {
                continue;
            }
            $domain = (string) ($site->domain ?? '');
            try {
                $snap = $this->workspaces->load($siteId, $actorUserId);
            } catch (Throwable) {
                continue;
            }

            $siteItems = is_array($snap['note_items'] ?? null) ? $snap['note_items'] : [];
            $siteMeta[(string) $siteId] = [
                'site_id' => $siteId,
                'domain' => $domain,
                'revision' => (int) ($snap['revision'] ?? 0),
                'content_hash' => (string) ($snap['content_hash'] ?? ''),
                'updated_at' => $snap['updated_at'] ?? null,
                'item_count' => count($siteItems),
            ];

            $updatedAt = is_string($snap['updated_at'] ?? null) ? (string) $snap['updated_at'] : null;
            if ($updatedAt !== null && ($latestUpdated === null || strcmp($updatedAt, $latestUpdated) > 0)) {
                $latestUpdated = $updatedAt;
            }

            foreach ($siteItems as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $ownerSiteId = (int) ($item['source_site_id'] ?? $item['owner_site_id'] ?? $siteId);
                if ($ownerSiteId <= 0) {
                    $ownerSiteId = $siteId;
                }
                // Owner workspace is the row we loaded — provenance may differ but saves go to row site.
                $item['owner_site_id'] = $siteId;
                $item['owner_domain'] = $domain;
                if (! isset($item['source_site_id'])) {
                    $item['source_site_id'] = $ownerSiteId > 0 ? $ownerSiteId : $siteId;
                }
                if (! isset($item['source_domain']) && $domain !== '') {
                    $item['source_domain'] = $domain;
                }
                // Stable UI identity across sites (cluster_ref alone can collide).
                $ref = (string) ($item['cluster_ref'] ?? '');
                $item['warehouse_key'] = $siteId.':'.($ref !== '' ? $ref : sha1(json_encode($item) ?: uniqid('im', true)));
                $items[] = $item;
            }
        }

        return [
            'status' => IdeaWorkspacePersistenceService::STATUS_OK,
            'note_items' => array_values($items),
            'item_count' => count($items),
            'sites' => $siteMeta,
            // Compat fields for Alpine bootstrap that still reads revision/site_id.
            'revision' => 0,
            'content_hash' => '',
            'updated_at' => $latestUpdated,
            'site_id' => 0,
            'domain' => '',
            'global' => true,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<int, list<array<string, mixed>>>
     */
    public static function partitionByOwnerSite(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $owner = (int) ($item['owner_site_id'] ?? $item['source_site_id'] ?? 0);
            if ($owner <= 0) {
                continue;
            }
            // Strip warehouse-only keys before persistence.
            $clean = $item;
            unset($clean['owner_site_id'], $clean['owner_domain'], $clean['warehouse_key']);
            $out[$owner][] = $clean;
        }

        return $out;
    }
}
