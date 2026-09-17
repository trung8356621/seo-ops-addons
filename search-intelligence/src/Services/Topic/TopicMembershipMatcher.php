<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

use Omnichannel\Addons\SearchIntelligence\Services\Topic\Support\TopicPhraseResolver;

/**
 * Shared Topic membership phrase matcher (manual reconcile + recluster engine).
 */
final class TopicMembershipMatcher
{
    public function __construct(
        private readonly TopicPhraseResolver $phrases,
    ) {}

    public function matches(string $keywordPhrase, string $topicName): bool
    {
        if ($this->phrases->containsCanonicalCore($keywordPhrase, $topicName)) {
            return true;
        }

        return $this->phrases->containsCanonicalCoreForTopic($keywordPhrase, $topicName);
    }
}
