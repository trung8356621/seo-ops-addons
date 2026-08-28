<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicClusterMeta;
use Omnichannel\Addons\SearchIntelligence\Services\SiteMcp\SiteMcpTopicalProfileStaleState;
use RuntimeException;

/**
 * Cluster-level MCP skip / SEO exclusion (persisted on seo_topic_cluster_meta).
 */
final class ClusterExclusionService
{
    /**
     * @return array{cluster_key: string, mcp_excluded: bool, seo_excluded: bool}
     */
    public function skipMcp(int $siteId, string $clusterKey): array
    {
        $this->assertExclusionColumnsReady();
        $row = $this->requireMeta($siteId, $clusterKey);
        $row->mcp_excluded = true;
        $row->save();

        TopicClusterDirtyState::mark($siteId, 'cluster_mcp_skipped');
        SiteMcpTopicalProfileStaleState::mark($siteId, 'cluster_mcp_skipped');

        return $this->flags($row);
    }

    /**
     * @return array{cluster_key: string, mcp_excluded: bool, seo_excluded: bool}
     */
    public function restoreMcp(int $siteId, string $clusterKey): array
    {
        $this->assertExclusionColumnsReady();
        $row = $this->requireMeta($siteId, $clusterKey);
        if ((bool) ($row->seo_excluded ?? false)) {
            throw new RuntimeException('seo_excluded_implies_mcp');
        }
        $row->mcp_excluded = false;
        $row->save();

        TopicClusterDirtyState::mark($siteId, 'cluster_mcp_restored');
        SiteMcpTopicalProfileStaleState::mark($siteId, 'cluster_mcp_restored');

        return $this->flags($row);
    }

    /**
     * Loại khỏi SEO ⇒ implicitly MCP excluded.
     *
     * @return array{cluster_key: string, mcp_excluded: bool, seo_excluded: bool}
     */
    public function excludeFromSeo(int $siteId, string $clusterKey): array
    {
        $this->assertExclusionColumnsReady();
        $row = $this->requireMeta($siteId, $clusterKey);
        $row->seo_excluded = true;
        $row->mcp_excluded = true;
        $row->save();

        TopicClusterDirtyState::mark($siteId, 'cluster_seo_excluded');
        SiteMcpTopicalProfileStaleState::mark($siteId, 'cluster_seo_excluded');

        return $this->flags($row);
    }

    /**
     * @return array{cluster_key: string, mcp_excluded: bool, seo_excluded: bool}
     */
    public function restoreSeo(int $siteId, string $clusterKey): array
    {
        $this->assertExclusionColumnsReady();
        $row = $this->requireMeta($siteId, $clusterKey);
        $row->seo_excluded = false;
        $row->save();

        TopicClusterDirtyState::mark($siteId, 'cluster_seo_restored');
        SiteMcpTopicalProfileStaleState::mark($siteId, 'cluster_seo_restored');

        return $this->flags($row);
    }

    /**
     * @return array{mcp_excluded: bool, seo_excluded: bool}
     */
    public function flagsFor(int $siteId, string $clusterKey): array
    {
        if (! $this->exclusionColumnsReady()) {
            return ['mcp_excluded' => false, 'seo_excluded' => false];
        }

        $row = SeoTopicClusterMeta::query()
            ->where('site_id', $siteId)
            ->where('cluster_key', $clusterKey)
            ->first(['cluster_key', 'mcp_excluded', 'seo_excluded']);

        if (! $row instanceof SeoTopicClusterMeta) {
            return ['mcp_excluded' => false, 'seo_excluded' => false];
        }

        return [
            'mcp_excluded' => (bool) ($row->mcp_excluded ?? false),
            'seo_excluded' => (bool) ($row->seo_excluded ?? false),
        ];
    }

    /**
     * @return array<string, array{mcp_excluded: bool, seo_excluded: bool}>
     */
    public function flagsMapForSite(int $siteId): array
    {
        if ($siteId <= 0 || ! $this->exclusionColumnsReady()) {
            return [];
        }

        $out = [];
        $rows = SeoTopicClusterMeta::query()
            ->where('site_id', $siteId)
            ->get(['cluster_key', 'mcp_excluded', 'seo_excluded']);
        foreach ($rows as $row) {
            $key = trim((string) $row->cluster_key);
            if ($key === '') {
                continue;
            }
            $out[$key] = [
                'mcp_excluded' => (bool) ($row->mcp_excluded ?? false),
                'seo_excluded' => (bool) ($row->seo_excluded ?? false),
            ];
        }

        return $out;
    }

    public function exclusionColumnsReady(): bool
    {
        if (! $this->metaReady()) {
            return false;
        }

        $schema = Schema::connection('omi_seo_ai');

        return $schema->hasColumn('seo_topic_cluster_meta', 'mcp_excluded')
            && $schema->hasColumn('seo_topic_cluster_meta', 'seo_excluded');
    }

    private function assertExclusionColumnsReady(): void
    {
        if (! $this->exclusionColumnsReady()) {
            throw new RuntimeException('cluster_exclusion_columns_missing');
        }
    }

    private function requireMeta(int $siteId, string $clusterKey): SeoTopicClusterMeta
    {
        $clusterKey = trim($clusterKey);
        if ($siteId <= 0 || $clusterKey === '' || ! $this->metaReady()) {
            throw new RuntimeException('invalid_input');
        }

        $row = SeoTopicClusterMeta::query()
            ->where('site_id', $siteId)
            ->where('cluster_key', $clusterKey)
            ->first();
        if ($row instanceof SeoTopicClusterMeta) {
            return $row;
        }

        $label = app(KeywordClusterQuery::class)->displayLabel($clusterKey, '', $siteId);
        $normalized = mb_strtolower(trim($label !== '' ? $label : $clusterKey), 'UTF-8');

        $payload = [
            'site_id' => $siteId,
            'cluster_key' => $clusterKey,
            'canonical_phrase' => $label !== '' ? $label : $clusterKey,
            'normalized_canonical' => $normalized,
            'confidence' => 'high',
            'needs_review' => false,
            'canonical_source' => SeoTopicClusterMeta::SOURCE_AUTO,
        ];
        if ($this->exclusionColumnsReady()) {
            $payload['mcp_excluded'] = false;
            $payload['seo_excluded'] = false;
        }

        return SeoTopicClusterMeta::query()->create($payload);
    }

    private function metaReady(): bool
    {
        return Schema::connection('omi_seo_ai')->hasTable('seo_topic_cluster_meta');
    }

    /**
     * @return array{cluster_key: string, mcp_excluded: bool, seo_excluded: bool}
     */
    private function flags(SeoTopicClusterMeta $row): array
    {
        return [
            'cluster_key' => (string) $row->cluster_key,
            'mcp_excluded' => (bool) ($row->mcp_excluded ?? false),
            'seo_excluded' => (bool) ($row->seo_excluded ?? false),
        ];
    }
}
