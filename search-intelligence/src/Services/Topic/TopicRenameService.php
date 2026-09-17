<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

use Omnichannel\Addons\SearchIntelligence\Models\SeoTopic;

/**
 * Rename Topic — updates seo_topics.name only.
 */
final class TopicRenameService
{
    /**
     * @return array{ok: bool, error: ?string}
     */
    public function rename(int $siteId, int $topicId, string $name): array
    {
        $name = TopicNaming::canonicalName($name);
        if ($siteId <= 0 || $topicId <= 0 || $name === '') {
            return ['ok' => false, 'error' => 'invalid_args'];
        }

        $topic = SeoTopic::query()
            ->where('site_id', $siteId)
            ->where('id', $topicId)
            ->first();
        if (! $topic instanceof SeoTopic) {
            return ['ok' => false, 'error' => 'topic_not_found'];
        }

        $topic->update(['name' => $name]);

        return ['ok' => true, 'error' => null];
    }
}
