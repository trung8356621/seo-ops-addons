<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicKeywordDna;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Support\TopicDnaExtractor;

/**
 * Rebuild seo_topic_keyword_dna for a site Topic (or membership set).
 */
final class TopicDnaService
{
    public function __construct(
        private readonly TopicDnaExtractor $extractor,
    ) {}

    public static function tableReady(): bool
    {
        return Schema::connection('omi_seo_ai')->hasTable('seo_topic_keyword_dna');
    }

    /**
     * @param  list<int>  $keywordIds
     * @return int rows written
     */
    public function rebuildForTopic(int $siteId, int $topicId, string $topicName, array $keywordIds): int
    {
        if ($siteId <= 0 || $topicId <= 0 || ! self::tableReady()) {
            return 0;
        }

        SeoTopicKeywordDna::query()
            ->where('site_id', $siteId)
            ->where('topic_id', $topicId)
            ->delete();

        if ($keywordIds === []) {
            return 0;
        }

        $phrases = Keyword::query()
            ->whereIn('id', $keywordIds)
            ->pluck('phrase', 'id');

        $rows = [];
        $now = now();
        /** @var array<string, true> $seen */
        $seen = [];

        foreach ($keywordIds as $keywordId) {
            $phrase = trim((string) ($phrases[$keywordId] ?? ''));
            if ($phrase === '') {
                continue;
            }
            foreach ($this->extractor->extract($phrase, $topicName) as $dna) {
                $value = trim((string) ($dna['value'] ?? ''));
                if ($value === '') {
                    continue;
                }
                $dedupeKey = $keywordId.'|'.mb_strtolower($value).'|'.(string) ($dna['placement'] ?? '');
                if (isset($seen[$dedupeKey])) {
                    continue;
                }
                $seen[$dedupeKey] = true;
                $rows[] = [
                    'site_id' => $siteId,
                    'topic_id' => $topicId,
                    'keyword_id' => $keywordId,
                    'value' => mb_substr($value, 0, 120),
                    'facet_type' => $dna['facet_type'] ?? null,
                    'placement' => $dna['placement'] ?? null,
                    'confidence' => isset($dna['confidence']) ? (string) $dna['confidence'] : null,
                    'source' => (string) ($dna['source'] ?? 'deterministic'),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($rows === []) {
            return 0;
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::connection('omi_seo_ai')->table('seo_topic_keyword_dna')->insert($chunk);
        }

        return count($rows);
    }

    public function deleteForTopic(int $siteId, int $topicId): int
    {
        if ($siteId <= 0 || $topicId <= 0 || ! self::tableReady()) {
            return 0;
        }

        return SeoTopicKeywordDna::query()
            ->where('site_id', $siteId)
            ->where('topic_id', $topicId)
            ->delete();
    }
}
