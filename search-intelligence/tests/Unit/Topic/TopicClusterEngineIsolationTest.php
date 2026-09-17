<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Topic;

use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicKeywordSource;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicClusterEngine;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicMembershipMatcher;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Support\TopicPhraseResolver;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordCanonicalizer;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordNormalizer;
use PHPUnit\Framework\TestCase;

final class TopicClusterEngineIsolationTest extends TestCase
{
    public function test_same_keyword_can_seed_independent_topic_shapes_per_run(): void
    {
        $phrases = new TopicPhraseResolver(new KeywordNormalizer, new KeywordCanonicalizer);
        $engine = new TopicClusterEngine(
            new TopicMembershipMatcher($phrases),
            new KeywordNormalizer,
        );

        $seedsA = [[
            'keyword_id' => 100,
            'phrase' => 'Túi đựng mỹ phẩm',
            'source' => TopicKeywordSource::LINK_LIST,
            'is_seed' => true,
            'confidence' => 1.0,
        ]];
        $seedsB = [[
            'keyword_id' => 100,
            'phrase' => 'Balo học sinh',
            'source' => TopicKeywordSource::PRODUCT_CAT,
            'is_seed' => true,
            'confidence' => 1.0,
        ]];

        $topicsA = $engine->cluster($seedsA, []);
        $topicsB = $engine->cluster($seedsB, []);

        self::assertCount(1, $topicsA);
        self::assertCount(1, $topicsB);
        self::assertSame(100, $topicsA[0]['members'][0]['keyword_id']);
        self::assertSame(100, $topicsB[0]['members'][0]['keyword_id']);
        self::assertNotSame($topicsA[0]['name'], $topicsB[0]['name']);
    }

    public function test_membership_unique_constraint_shape_is_site_plus_keyword(): void
    {
        $migration = dirname(__DIR__, 3).'/database/migrations/2026_09_17_120000_create_seo_topic_core_tables.php';
        $src = (string) file_get_contents($migration);
        self::assertStringContainsString("unique(['site_id', 'keyword_id']", $src);
        self::assertStringNotContainsString("unique(['keyword_id']", $src);
    }

    public function test_focus_is_not_a_seed_source(): void
    {
        $sources = TopicKeywordSource::values();
        self::assertContains(TopicKeywordSource::LINK_LIST, $sources);
        self::assertContains(TopicKeywordSource::PRODUCT_CAT, $sources);
        self::assertNotContains('focus', $sources);
        self::assertNotContains('focus_article', $sources);
        self::assertNotContains('focus_keyword', $sources);
    }
}
