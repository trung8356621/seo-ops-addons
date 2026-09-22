<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Topic;

use Omnichannel\Addons\SearchIntelligence\Services\Topic\Dto\KeywordLandscape;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Dto\KeywordLandscapeTopic;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\KeywordLandscapeReadModel;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicLinkedArticleCounter;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicTagMetricsResolver;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicTopicalShareCalculator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Contract + deterministic payload checks for Keyword Landscape SSOT.
 */
final class KeywordLandscapeReadModelContractTest extends TestCase
{
    public function test_read_model_is_site_scoped_topic_core_ssot(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(KeywordLandscapeReadModel::class))->getFileName(),
        );

        self::assertStringContainsString("where('site_id', \$siteId)", $src);
        self::assertStringContainsString('TopicLinkedArticleCounter', $src);
        self::assertStringContainsString('TopicTopicalShareCalculator', $src);
        self::assertStringContainsString('SeoTopicKeywordDna', $src);
        self::assertStringNotContainsString('cluster_key', $src);
        self::assertStringNotContainsString('EMPTY_LANDSCAPE', $src);
        self::assertStringNotContainsString('SiteMcpClusterTopicalProfileBuilder', $src);
    }

    public function test_mcp_payload_parts_are_deterministic(): void
    {
        $a = new KeywordLandscapeTopic(
            id: 2,
            name: 'Balo cho bé',
            mcp: 0.1,
            dnaCount: 1,
            articleCount: 0,
            hasFocusArticle: false,
            coverage: 'weak',
            status: 'active',
            dna: [['phrase' => 'balo', 'weight' => 2]],
            updatedAt: '2026-09-01T00:00:00+00:00',
        );
        $b = new KeywordLandscapeTopic(
            id: 1,
            name: 'Túi xách',
            mcp: 10.0,
            dnaCount: 0,
            articleCount: 3,
            hasFocusArticle: true,
            coverage: 'strong',
            status: 'active',
            dna: [],
            updatedAt: '2026-09-02T00:00:00+00:00',
        );

        $landscape = new KeywordLandscape(9, [$a, $b], '2026-09-02T00:00:00+00:00');
        $first = $landscape->toMcpPayloadParts();
        $second = $landscape->toMcpPayloadParts();

        self::assertSame($first, $second);
        self::assertSame(2, $first['metrics']['topic_count']);
        self::assertSame([], $first['summary']);
        self::assertSame(2, $first['context']['topics'][0]['id']);
        self::assertSame(0.1, $first['context']['topics'][0]['mcp']);
        self::assertSame([['phrase' => 'balo', 'weight' => 2]], $first['context']['topics'][0]['dna']);
        self::assertArrayNotHasKey('planned_history_count', $first['context']['topics'][0]);
        self::assertArrayNotHasKey('target_dna_count', $first['context']['topics'][0]);
    }

    public function test_find_by_id_and_empty_site(): void
    {
        $topic = new KeywordLandscapeTopic(
            id: 5,
            name: 'X',
            mcp: 1.0,
            dnaCount: 0,
            articleCount: 0,
            hasFocusArticle: false,
            coverage: 'unknown',
            status: 'active',
            dna: [],
            updatedAt: null,
        );
        $landscape = new KeywordLandscape(1, [$topic], null);
        self::assertSame(5, $landscape->findById(5)?->id);
        self::assertNull($landscape->findById(99));

        $empty = new KeywordLandscape(0, [], null);
        self::assertSame(0, $empty->topicCount());
    }

    public function test_constructor_dependencies_are_topic_core_only(): void
    {
        $ref = new ReflectionClass(KeywordLandscapeReadModel::class);
        $ctor = $ref->getConstructor();
        self::assertNotNull($ctor);
        $params = $ctor->getParameters();
        self::assertGreaterThanOrEqual(1, count($params));
        self::assertSame(TopicLinkedArticleCounter::class, $params[0]->getType()?->getName());
        self::assertTrue(class_exists(TopicTagMetricsResolver::class));
        self::assertTrue(class_exists(TopicTopicalShareCalculator::class));
    }
}
