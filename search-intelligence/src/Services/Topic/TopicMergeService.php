<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicKeywordSource;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopic;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicKeyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicKeywordDna;

/**
 * Merge source Topic into surviving Topic on the same site.
 */
final class TopicMergeService
{
    public function __construct(
        private readonly TopicDnaService $dna,
    ) {}

    /**
     * @return array{ok: bool, error: ?string, survivor_topic_id: int|null}
     */
    public function merge(int $siteId, int $survivorTopicId, int $sourceTopicId): array
    {
        if ($siteId <= 0 || $survivorTopicId <= 0 || $sourceTopicId <= 0 || $survivorTopicId === $sourceTopicId) {
            return ['ok' => false, 'error' => 'invalid_args', 'survivor_topic_id' => null];
        }

        $survivor = SeoTopic::query()->where('site_id', $siteId)->where('id', $survivorTopicId)->first();
        $source = SeoTopic::query()->where('site_id', $siteId)->where('id', $sourceTopicId)->first();
        if (! $survivor instanceof SeoTopic || ! $source instanceof SeoTopic) {
            return ['ok' => false, 'error' => 'topic_not_found', 'survivor_topic_id' => null];
        }
        if ($source->is_locked) {
            return ['ok' => false, 'error' => 'source_topic_locked', 'survivor_topic_id' => null];
        }

        return DB::connection('omi_seo_ai')->transaction(function () use ($siteId, $survivor, $source, $survivorTopicId, $sourceTopicId): array {
            $sourceMembers = SeoTopicKeyword::query()
                ->where('site_id', $siteId)
                ->where('topic_id', $sourceTopicId)
                ->get();

            foreach ($sourceMembers as $member) {
                if ($member->is_locked) {
                    continue;
                }
                // UNIQUE(site_id, keyword_id) — move or skip if already on survivor.
                $existing = SeoTopicKeyword::query()
                    ->where('site_id', $siteId)
                    ->where('keyword_id', (int) $member->keyword_id)
                    ->where('topic_id', $survivorTopicId)
                    ->exists();
                if ($existing) {
                    $member->delete();
                    continue;
                }
                $member->update([
                    'topic_id' => $survivorTopicId,
                    'source' => TopicKeywordSource::MANUAL,
                ]);
            }

            SeoTopicKeywordDna::query()
                ->where('site_id', $siteId)
                ->where('topic_id', $sourceTopicId)
                ->delete();
            SeoTopicKeyword::query()
                ->where('site_id', $siteId)
                ->where('topic_id', $sourceTopicId)
                ->where('is_locked', false)
                ->delete();
            // If source still has locked leftovers, keep the topic; else delete.
            $remaining = SeoTopicKeyword::query()
                ->where('site_id', $siteId)
                ->where('topic_id', $sourceTopicId)
                ->count();
            if ($remaining === 0) {
                SeoTopic::query()->where('site_id', $siteId)->where('id', $sourceTopicId)->delete();
            }

            $ids = SeoTopicKeyword::query()
                ->where('site_id', $siteId)
                ->where('topic_id', $survivorTopicId)
                ->pluck('keyword_id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
            $this->dna->rebuildForTopic($siteId, $survivorTopicId, (string) $survivor->name, $ids);

            return ['ok' => true, 'error' => null, 'survivor_topic_id' => $survivorTopicId];
        });
    }
}
