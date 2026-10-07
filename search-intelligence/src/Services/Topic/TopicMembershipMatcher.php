<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

use Omnichannel\Addons\SearchIntelligence\Services\Topic\Support\TopicPhraseResolver;

/**
 * Shared Topic membership phrase matcher.
 *
 * Used by the legacy grouping provider (targeted scan) and the legacy cluster engine.
 * Does not persist Topic memberships.
 */
final class TopicMembershipMatcher
{
    public function __construct(
        private readonly TopicPhraseResolver $phrases,
    ) {}

    public function withRules(array $industryRules, array $globalRules): self
    {
        return new self($this->phrases->withRules($industryRules, $globalRules));
    }

    public function matches(string $keywordPhrase, string $topicName): bool
    {
        if ($this->phrases->containsCanonicalCore($keywordPhrase, $topicName)) {
            return true;
        }

        return $this->phrases->containsCanonicalCoreForTopic($keywordPhrase, $topicName);
    }
}
