<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicSource;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopic;

/**
 * Semantic user edits promote auto Topics to manual ownership.
 * Does not set is_locked — lock semantics remain separate.
 */
final class TopicManualOwnership
{
    /**
     * @return bool true when source flipped auto → manual
     */
    public static function promoteIfAuto(?SeoTopic $topic): bool
    {
        if (! $topic instanceof SeoTopic) {
            return false;
        }
        if (TopicSource::normalize((string) $topic->source) === TopicSource::MANUAL) {
            return false;
        }

        $topic->update(['source' => TopicSource::MANUAL]);

        return true;
    }
}
