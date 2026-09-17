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
     * @return array{ok: bool, error: ?string, deleted_memberships: int, deleted_dna: int}
     */
    public function dissolve(int $siteId, int $topicId): array
    {
        if ($siteId <= 0 || $topicId <= 0) {
            return ['ok' => false, 'error' => 'site_and_topic_required', 'deleted_memberships' => 0, 'deleted_dna' => 0];
        }

        $topic = SeoTopic::query()
            ->where('site_id', $siteId)
            ->where('id', $topicId)
            ->first();
        if (! $topic instanceof SeoTopic) {
            return ['ok' => false, 'error' => 'topic_not_found', 'deleted_memberships' => 0, 'deleted_dna' => 0];
        }
        if ($topic->is_locked) {
            return ['ok' => false, 'error' => 'topic_locked', 'deleted_memberships' => 0, 'deleted_dna' => 0];
        }

        return DB::connection('omi_seo_ai')->transaction(function () use ($siteId, $topicId): array {
            $deletedDna = SeoTopicKeywordDna::query()
                ->where('site_id', $siteId)
                ->where('topic_id', $topicId)
                ->delete();
            $deletedMemberships = SeoTopicKeyword::query()
                ->where('site_id', $siteId)
                ->where('topic_id', $topicId)
                ->delete();
            SeoTopic::query()
                ->where('site_id', $siteId)
                ->where('id', $topicId)
                ->delete();

            return [
                'ok' => true,
                'error' => null,
                'deleted_memberships' => $deletedMemberships,
                'deleted_dna' => $deletedDna,
            ];
        });
    }
}
