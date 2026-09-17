<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Topic;

use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicMembershipMatcher;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Support\TopicPhraseResolver;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordCanonicalizer;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordNormalizer;
use PHPUnit\Framework\TestCase;

final class TopicMembershipMatcherTest extends TestCase
{
    private function matcher(): TopicMembershipMatcher
    {
        return new TopicMembershipMatcher(
            new TopicPhraseResolver(new KeywordNormalizer, new KeywordCanonicalizer),
        );
    }

    public function test_containing_phrases_match_topic_core(): void
    {
        $matcher = $this->matcher();
        $topic = 'Balo thời trang';

        self::assertTrue($matcher->matches('Balo thời trang', $topic));
        self::assertTrue($matcher->matches('100 mẫu balo thời trang sản xuất', $topic));
        self::assertTrue($matcher->matches('Balo thời trang camel mountain màu đỏ', $topic));
        self::assertTrue($matcher->matches('Balo thời trang canvas đen', $topic));
    }

    public function test_unrelated_phrase_does_not_match(): void
    {
        $matcher = $this->matcher();

        self::assertFalse($matcher->matches('cách giặt áo', 'Balo thời trang'));
        self::assertFalse($matcher->matches('túi đựng mỹ phẩm', 'Balo thời trang'));
    }

    public function test_longer_seed_phrase_is_not_duplicate_of_shorter_topic(): void
    {
        $matcher = $this->matcher();

        // "May balo thời trang" as Topic identity is distinct; matcher is containment of topic IN keyword.
        // Keyword "may balo thời trang" CONTAINS topic "balo thời trang" → match for membership.
        // Duplicate Topic detection must NOT use this matcher — exact seed/name only.
        self::assertTrue($matcher->matches('May balo thời trang', 'Balo thời trang'));
        self::assertFalse($matcher->matches('Balo thời trang', 'May balo thời trang'));
    }
}
