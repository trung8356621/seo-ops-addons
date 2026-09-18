<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopic;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicKeyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicKeywordDna;

/**
 * Dissolve Topic: delete DNA + memberships + topic row.
 * Preserves seo_site_keywords, keywords, articles, links/catalog.
 */
final class TopicDissolveService
{
    /**
     * @return array{
     *     ok: bool,
     *     error: ?string,
     *     topic_id: int|null,
     *     topic_name: string|null,
     *     deleted_memberships: int,
     *     deleted_dna: int
     * }
     */
    public function dissolve(int $siteId, int $topicId): array
    {
        if ($siteId <= 0 || $topicId <= 0) {
            return [
                'ok' => false,
                'error' => 'site_and_topic_required',
                'topic_id' => null,
                'topic_name' => null,
                'deleted_memberships' => 0,
                'deleted_dna' => 0,
            ];
        }

        $topic = SeoTopic::query()
            ->where('site_id', $siteId)
            ->where('id', $topicId)
            ->first();
        if (! $topic instanceof SeoTopic) {
            return [
                'ok' => false,
                'error' => 'topic_not_found',
                'topic_id' => null,
                'topic_name' => null,
                'deleted_memberships' => 0,
                'deleted_dna' => 0,
            ];
        }
        if ($topic->is_locked) {
            return [
                'ok' => false,
                'error' => 'topic_locked',
                'topic_id' => $topicId,
                'topic_name' => (string) $topic->name,
                'deleted_memberships' => 0,
                'deleted_dna' => 0,
            ];
        }

        $topicName = (string) $topic->name;

        return DB::connection('omi_seo_ai')->transaction(function () use ($siteId, $topicId, $topicName): array {
            $deletedDna = SeoTopicKeywordDna::query()
                ->where('site_id', $siteId)
                ->where('topic_id', $topicId)
                ->delete();
            $deletedMemberships = SeoTopicKeyword::query()
                ->where('site_id', $siteId)
                ->where('topic_id', $topicId)
                ->delete();
            app(TopicUserTagService::class)->deleteForTopics([$topicId]);
            SeoTopic::query()
                ->where('site_id', $siteId)
                ->where('id', $topicId)
                ->delete();

            return [
                'ok' => true,
                'error' => null,
                'topic_id' => $topicId,
                'topic_name' => $topicName,
                'deleted_memberships' => $deletedMemberships,
                'deleted_dna' => $deletedDna,
            ];
        });
    }
}
