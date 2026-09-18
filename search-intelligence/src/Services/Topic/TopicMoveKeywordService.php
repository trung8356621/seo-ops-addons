<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicKeywordSource;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopic;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicKeyword;

/**
 * Move keyword membership between Topics on the same site.
 * Semantic membership edit promotes auto Topics → manual (source and/or target).
 */
final class TopicMoveKeywordService
{
    public function __construct(
        private readonly TopicDnaService $dna,
    ) {}

    /**
     * @return array{ok: bool, error: ?string}
     */
    public function move(int $siteId, int $keywordId, int $toTopicId): array
    {
        if ($siteId <= 0 || $keywordId <= 0 || $toTopicId <= 0) {
            return ['ok' => false, 'error' => 'invalid_args'];
        }

        $toTopic = SeoTopic::query()
            ->where('site_id', $siteId)
            ->where('id', $toTopicId)
            ->first();
        if (! $toTopic instanceof SeoTopic) {
            return ['ok' => false, 'error' => 'target_topic_not_found'];
        }

        $membership = SeoTopicKeyword::query()
            ->where('site_id', $siteId)
            ->where('keyword_id', $keywordId)
            ->first();

        $fromTopicId = $membership instanceof SeoTopicKeyword ? (int) $membership->topic_id : null;
        if ($membership instanceof SeoTopicKeyword && $membership->is_locked) {
            return ['ok' => false, 'error' => 'membership_locked'];
        }
        $fromTopic = null;
        if ($fromTopicId !== null) {
            $fromTopic = SeoTopic::query()
                ->where('site_id', $siteId)
                ->where('id', $fromTopicId)
                ->first();
            if ($fromTopic instanceof SeoTopic && $fromTopic->is_locked) {
                return ['ok' => false, 'error' => 'source_topic_locked'];
            }
        }

        // Semantic membership mutation → manual ownership (lock flags unchanged).
        TopicManualOwnership::promoteIfAuto($fromTopic);
        TopicManualOwnership::promoteIfAuto($toTopic);

        SeoTopicKeyword::query()->updateOrCreate(
            ['site_id' => $siteId, 'keyword_id' => $keywordId],
            [
                'topic_id' => $toTopicId,
                'source' => TopicKeywordSource::MANUAL,
                'is_seed' => false,
                'is_locked' => true,
                'confidence' => null,
            ],
        );

        if ($fromTopicId !== null && $fromTopicId !== $toTopicId) {
            $this->rebuildTopicDna($siteId, $fromTopicId);
        }
        $this->rebuildTopicDna($siteId, $toTopicId);

        return ['ok' => true, 'error' => null];
    }

    private function rebuildTopicDna(int $siteId, int $topicId): void
    {
        $topic = SeoTopic::query()->where('site_id', $siteId)->where('id', $topicId)->first();
        if (! $topic instanceof SeoTopic) {
            return;
        }
        $keywordIds = SeoTopicKeyword::query()
            ->where('site_id', $siteId)
            ->where('topic_id', $topicId)
            ->pluck('keyword_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        $this->dna->rebuildForTopic($siteId, $topicId, (string) $topic->name, $keywordIds);
    }
}
