<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

use Omnichannel\Addons\SearchIntelligence\Models\SeoTopic;

/**
 * Rename Topic — updates seo_topics.name; semantic edit promotes auto → manual.
 */
final class TopicRenameService
{
    /**
     * @return array{ok: bool, error: ?string, source: ?string, promoted_to_manual: bool}
     */
    public function rename(int $siteId, int $topicId, string $name): array
    {
        $name = TopicNaming::canonicalName($name);
        if ($siteId <= 0 || $topicId <= 0 || $name === '') {
            return ['ok' => false, 'error' => 'invalid_args', 'source' => null, 'promoted_to_manual' => false];
        }

        $topic = SeoTopic::query()
            ->where('site_id', $siteId)
            ->where('id', $topicId)
            ->first();
        if (! $topic instanceof SeoTopic) {
            return ['ok' => false, 'error' => 'topic_not_found', 'source' => null, 'promoted_to_manual' => false];
        }

        $promoted = TopicManualOwnership::promoteIfAuto($topic);
        if ($promoted) {
            $topic->refresh();
        }

        $topic->update(['name' => $name]);

        return [
            'ok' => true,
            'error' => null,
            'source' => (string) $topic->fresh()?->source,
            'promoted_to_manual' => $promoted,
        ];
    }
}
