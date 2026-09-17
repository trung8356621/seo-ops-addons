<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicKeywordSource;
use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicStatus;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopic;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicKeyword;

/**
 * Split selected memberships into a new Topic on the same site.
 */
final class TopicSplitService
{
    public function __construct(
        private readonly TopicDnaService $dna,
    ) {}

    /**
     * @param  list<int>  $keywordIds
     * @return array{ok: bool, error: ?string, new_topic_id: int|null}
     */
    public function split(int $siteId, int $fromTopicId, array $keywordIds, string $newName): array
    {
        $keywordIds = array_values(array_unique(array_filter(
            array_map('intval', $keywordIds),
            static fn (int $id): bool => $id > 0,
        )));
        $newName = TopicNaming::canonicalName($newName);
        if ($siteId <= 0 || $fromTopicId <= 0 || $keywordIds === [] || $newName === '') {
            return ['ok' => false, 'error' => 'invalid_args', 'new_topic_id' => null];
        }

        $from = SeoTopic::query()->where('site_id', $siteId)->where('id', $fromTopicId)->first();
        if (! $from instanceof SeoTopic) {
            return ['ok' => false, 'error' => 'source_topic_not_found', 'new_topic_id' => null];
        }
        if ($from->is_locked) {
            return ['ok' => false, 'error' => 'source_topic_locked', 'new_topic_id' => null];
        }

        return DB::connection('omi_seo_ai')->transaction(function () use ($siteId, $fromTopicId, $keywordIds, $newName, $from): array {
            $newTopic = SeoTopic::query()->create([
                'site_id' => $siteId,
                'name' => $newName,
                'status' => TopicStatus::ACTIVE,
                'is_locked' => false,
            ]);
            $newTopicId = (int) $newTopic->id;

            foreach ($keywordIds as $keywordId) {
                $membership = SeoTopicKeyword::query()
                    ->where('site_id', $siteId)
                    ->where('topic_id', $fromTopicId)
                    ->where('keyword_id', $keywordId)
                    ->first();
                if (! $membership instanceof SeoTopicKeyword || $membership->is_locked) {
                    continue;
                }
                $membership->update([
                    'topic_id' => $newTopicId,
                    'source' => TopicKeywordSource::MANUAL,
                    'is_locked' => true,
                ]);
            }

            $this->rebuild($siteId, $fromTopicId, (string) $from->name);
            $this->rebuild($siteId, $newTopicId, $newName);

            return ['ok' => true, 'error' => null, 'new_topic_id' => $newTopicId];
        });
    }

    private function rebuild(int $siteId, int $topicId, string $name): void
    {
        $ids = SeoTopicKeyword::query()
            ->where('site_id', $siteId)
            ->where('topic_id', $topicId)
            ->pluck('keyword_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        $this->dna->rebuildForTopic($siteId, $topicId, $name, $ids);
    }
}
