<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopic;
use RuntimeException;

/**
 * Topic-level MCP quarantine — soft-exclude from site SEO intelligence.
 *
 * Storage: seo_topics.mcp_excluded (boolean, default false).
 * Does not dissolve Topic, delete memberships, mutate articles, or call AI.
 */
final class TopicMcpExclusionService
{
    public static function columnReady(): bool
    {
        return Schema::connection('omi_seo_ai')->hasTable('seo_topics')
            && Schema::connection('omi_seo_ai')->hasColumn('seo_topics', 'mcp_excluded');
    }

    public function isExcluded(int $siteId, int $topicId): bool
    {
        if ($siteId <= 0 || $topicId <= 0 || ! self::columnReady()) {
            return false;
        }

        return SeoTopic::query()
            ->where('site_id', $siteId)
            ->whereKey($topicId)
            ->where('mcp_excluded', true)
            ->exists();
    }

    /**
     * @param  list<int>  $topicIds
     * @return array<int, true>
     */
    public function excludedTopicIdMap(int $siteId, array $topicIds = []): array
    {
        if ($siteId <= 0 || ! self::columnReady()) {
            return [];
        }

        $query = SeoTopic::query()
            ->where('site_id', $siteId)
            ->where('mcp_excluded', true);

        $topicIds = array_values(array_unique(array_filter(
            array_map('intval', $topicIds),
            static fn (int $id): bool => $id > 0,
        )));
        if ($topicIds !== []) {
            $query->whereIn('id', $topicIds);
        }

        $out = [];
        foreach ($query->pluck('id') as $id) {
            $out[(int) $id] = true;
        }

        return $out;
    }

    /**
     * @return array{topic_id: int, site_id: int, name: string, excluded: bool}
     */
    public function exclude(int $siteId, int $topicId): array
    {
        $topic = $this->requireSiteTopic($siteId, $topicId);
        if (! self::columnReady()) {
            throw new RuntimeException('mcp_excluded_column_missing');
        }

        if (! (bool) $topic->mcp_excluded) {
            $topic->mcp_excluded = true;
            $topic->save();
        }

        return [
            'topic_id' => (int) $topic->id,
            'site_id' => $siteId,
            'name' => (string) $topic->name,
            'excluded' => true,
        ];
    }

    /**
     * @return array{topic_id: int, site_id: int, name: string, restored: bool}
     */
    public function restore(int $siteId, int $topicId): array
    {
        $topic = $this->requireSiteTopic($siteId, $topicId);
        if (! self::columnReady()) {
            throw new RuntimeException('mcp_excluded_column_missing');
        }

        if ((bool) $topic->mcp_excluded) {
            $topic->mcp_excluded = false;
            $topic->save();
        }

        return [
            'topic_id' => (int) $topic->id,
            'site_id' => $siteId,
            'name' => (string) $topic->name,
            'restored' => true,
        ];
    }

    private function requireSiteTopic(int $siteId, int $topicId): SeoTopic
    {
        if ($siteId <= 0 || $topicId <= 0) {
            throw new RuntimeException('invalid_args');
        }

        $topic = SeoTopic::query()
            ->where('site_id', $siteId)
            ->whereKey($topicId)
            ->first();

        if (! $topic instanceof SeoTopic) {
            throw new RuntimeException('topic_not_found');
        }

        return $topic;
    }
}
