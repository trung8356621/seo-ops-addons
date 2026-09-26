<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Services\Topic\Dto\KeywordLandscapeTopic;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicLinkedArticleCounter;
use Omnichannel\Addons\Seo\Services\Access\SeoAccessKeywordsComposer;
use Omnichannel\Addons\Seo\Services\KeywordLandscape\KeywordLandscapeGateway;
use Omnichannel\Addons\Seo\Services\KeywordRelationship\KeywordRelationshipGateway;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class SeoAccessKeywordsComposerTest extends TestCase
{
    public function test_source_contract_has_pagination_mcp_and_topic_detail(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(SeoAccessKeywordsComposer::class))->getFileName()
        );

        self::assertStringContainsString('seo.access.keywords.v2', $src);
        self::assertStringContainsString('seo.access.keywords.topic.v1', $src);
        self::assertStringContainsString('detail_href', $src);
        self::assertStringContainsString('mcp_percent', $src);
        self::assertStringContainsString('SORT_ALLOWLIST', $src);
        self::assertStringContainsString('MAX_PER_PAGE', $src);
        self::assertStringContainsString('topicDetail', $src);
        self::assertStringNotContainsString('LANDSCAPE_TOPIC_LIMIT', $src);
        self::assertStringNotContainsString('ContextListSlice::fromAll', $src);
    }

    public function test_sort_and_paginate_topics(): void
    {
        $composer = (new ReflectionClass(SeoAccessKeywordsComposer::class))
            ->newInstanceWithoutConstructor();

        $topics = [
            $this->topic(1, 'Alpha', 10.0, 2, 1, 'strong', 'active'),
            $this->topic(2, 'Beta', 0.0, 5, 0, 'weak', 'active'),
            $this->topic(3, 'Gamma', 50.0, 1, 4, 'strong', 'draft'),
        ];

        $sort = new ReflectionMethod(SeoAccessKeywordsComposer::class, 'sortTopics');
        $sort->setAccessible(true);
        /** @var list<KeywordLandscapeTopic> $asc */
        $asc = $sort->invoke($composer, $topics, 'mcp', 'asc');
        self::assertSame([2, 1, 3], array_map(static fn (KeywordLandscapeTopic $t): int => $t->id, $asc));

        /** @var list<KeywordLandscapeTopic> $desc */
        $desc = $sort->invoke($composer, $topics, 'mcp', 'desc');
        self::assertSame([3, 1, 2], array_map(static fn (KeywordLandscapeTopic $t): int => $t->id, $desc));

        /** @var list<KeywordLandscapeTopic> $byArticles */
        $byArticles = $sort->invoke($composer, $topics, 'article_count', 'desc');
        self::assertSame([3, 1, 2], array_map(static fn (KeywordLandscapeTopic $t): int => $t->id, $byArticles));

        $row = new ReflectionMethod(SeoAccessKeywordsComposer::class, 'landscapeRow');
        $row->setAccessible(true);
        /** @var array<string, mixed> $payload */
        $payload = $row->invoke($composer, $topics[2], '/api/v1/access/access_tmp_x');
        self::assertSame('topic:3', $payload['topic_ref']);
        self::assertSame(50.0, $payload['mcp']);
        self::assertSame(50, $payload['mcp_percent']);
        self::assertSame(1, $payload['dna_count']);
        self::assertTrue($payload['has_focus_article']);
        self::assertSame(
            '/api/v1/access/access_tmp_x/keywords/topics/topic:3',
            $payload['detail_href']
        );
        self::assertArrayNotHasKey('dna', $payload);
    }

    public function test_resolve_topic_id(): void
    {
        $composer = new SeoAccessKeywordsComposer(
            (new ReflectionClass(KeywordLandscapeGateway::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(KeywordRelationshipGateway::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(TopicLinkedArticleCounter::class))->newInstanceWithoutConstructor(),
        );

        self::assertSame(123, $composer->resolveTopicId('topic:123'));
        self::assertSame(9, $composer->resolveTopicId('9'));
        self::assertSame(0, $composer->resolveTopicId('nope'));
    }

    private function topic(
        int $id,
        string $name,
        float $mcp,
        int $dnaCount,
        int $articleCount,
        string $coverage,
        string $status,
    ): KeywordLandscapeTopic {
        return new KeywordLandscapeTopic(
            id: $id,
            name: $name,
            mcp: $mcp,
            dnaCount: $dnaCount,
            articleCount: $articleCount,
            hasFocusArticle: $articleCount > 0,
            coverage: $coverage,
            status: $status,
            dna: [],
            updatedAt: null,
        );
    }
}
