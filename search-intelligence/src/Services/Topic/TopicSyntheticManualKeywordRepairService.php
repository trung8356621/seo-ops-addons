<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchFoundation\Models\SeoLinkMap;
use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicKeywordSource;
use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicSource;
use Omnichannel\Addons\SearchIntelligence\Models\SeoSiteKeyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopic;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicKeyword;

/**
 * Safe repair for Topics that used synthetic manual Keyword seeds.
 *
 * Converts Topic provenance to seo_topics.source=manual, demotes fake seeds,
 * and deletes only Keywords proven unused (no URL / articles / links / other sites).
 */
final class TopicSyntheticManualKeywordRepairService
{
    /**
     * @return array{
     *     topics_marked_manual: int,
     *     seeds_demoted: int,
     *     site_attachments_removed: int,
     *     keywords_deleted: int,
     *     keywords_kept_with_evidence: int
     * }
     */
    public function repairSite(int $siteId): array
    {
        $metrics = [
            'topics_marked_manual' => 0,
            'seeds_demoted' => 0,
            'site_attachments_removed' => 0,
            'keywords_deleted' => 0,
            'keywords_kept_with_evidence' => 0,
        ];
        if ($siteId <= 0 || ! TopicReclusterService::tablesReady()) {
            return $metrics;
        }

        return DB::connection('omi_seo_ai')->transaction(function () use ($siteId, $metrics): array {
            $seeds = SeoTopicKeyword::query()
                ->where('site_id', $siteId)
                ->where('is_seed', true)
                ->where('source', TopicKeywordSource::MANUAL)
                ->get();

            /** @var array<int, true> $topicIds */
            $topicIds = [];
            foreach ($seeds as $seed) {
                $topicIds[(int) $seed->topic_id] = true;
            }

            if ($topicIds !== []) {
                $updated = SeoTopic::query()
                    ->where('site_id', $siteId)
                    ->whereIn('id', array_keys($topicIds))
                    ->where('source', '!=', TopicSource::MANUAL)
                    ->update(['source' => TopicSource::MANUAL]);
                $metrics['topics_marked_manual'] = (int) $updated;

                // Also mark already-manual for counting clarity when all were already set by migration.
                $already = SeoTopic::query()
                    ->where('site_id', $siteId)
                    ->whereIn('id', array_keys($topicIds))
                    ->where('source', TopicSource::MANUAL)
                    ->count();
                $metrics['topics_marked_manual'] = max($metrics['topics_marked_manual'], $already);
            }

            foreach ($seeds as $seed) {
                $keywordId = (int) $seed->keyword_id;
                $topicId = (int) $seed->topic_id;
                if ($keywordId <= 0 || $topicId <= 0) {
                    continue;
                }

                $keyword = Keyword::query()->find($keywordId);
                if (! $keyword instanceof Keyword) {
                    $seed->delete();
                    $metrics['seeds_demoted']++;

                    continue;
                }

                if ($this->hasRealEvidence($keyword, $keywordId)) {
                    $seed->is_seed = false;
                    $seed->source = TopicKeywordSource::RECLUSTER;
                    $seed->save();
                    $metrics['seeds_demoted']++;
                    $metrics['keywords_kept_with_evidence']++;

                    continue;
                }

                // Detach synthetic membership + site classification.
                $seed->delete();
                $metrics['seeds_demoted']++;

                $removed = SeoSiteKeyword::query()
                    ->where('site_id', $siteId)
                    ->where('keyword_id', $keywordId)
                    ->where('source', TopicKeywordSource::MANUAL)
                    ->delete();
                $metrics['site_attachments_removed'] += (int) $removed;

                if ($this->keywordUnusedEverywhere($keywordId)) {
                    $keyword->delete();
                    $metrics['keywords_deleted']++;
                }
            }

            return $metrics;
        });
    }

    private function hasRealEvidence(Keyword $keyword, int $keywordId): bool
    {
        if ($keyword->mainArticles()->exists()) {
            return true;
        }
        if (SeoLinkMap::query()->where('keyword_id', $keywordId)->exists()) {
            return true;
        }

        $metas = $keyword->relationLoaded('metas')
            ? $keyword->metas
            : $keyword->metas()->get();
        foreach ($metas as $meta) {
            $key = strtolower((string) ($meta->meta_key ?? ''));
            $value = trim((string) ($meta->meta_value ?? ''));
            if ($value === '') {
                continue;
            }
            if (str_contains($key, 'url') || str_contains($key, 'slug') || str_contains($key, 'target')) {
                return true;
            }
            if ($key === 'main_article_id' && ctype_digit($value) && (int) $value > 0) {
                return true;
            }
        }

        return false;
    }

    private function keywordUnusedEverywhere(int $keywordId): bool
    {
        if (SeoTopicKeyword::query()->where('keyword_id', $keywordId)->exists()) {
            return false;
        }
        if (SeoSiteKeyword::query()->where('keyword_id', $keywordId)->exists()) {
            return false;
        }
        if (SeoLinkMap::query()->where('keyword_id', $keywordId)->exists()) {
            return false;
        }
        $keyword = Keyword::query()->find($keywordId);
        if ($keyword instanceof Keyword && $keyword->mainArticles()->exists()) {
            return false;
        }

        return true;
    }
}
