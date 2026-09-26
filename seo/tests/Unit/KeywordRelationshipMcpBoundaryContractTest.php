<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Services\Topic\Dto\KeywordRelationship;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Dto\RelationshipListSlice;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\KeywordRelationshipReadModel;
use Omnichannel\Addons\Seo\Services\KeywordLandscape\KeywordLandscapeGateway;
use Omnichannel\Addons\Seo\Services\KeywordRelationship\KeywordRelationshipGateway;
use Omnichannel\Addons\Seo\Services\KeywordRelationship\KeywordRelationshipGraphPresenter;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\ContentProjectAgentGateway;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\ContentProjectAgentPolicy;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Mcp\ContentProjectMcpToolCatalog;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class KeywordRelationshipMcpBoundaryContractTest extends TestCase
{
    public function test_schema_is_relationship_v1_not_landscape_v2(): void
    {
        self::assertSame('keyword.relationship.v1', KeywordRelationship::SCHEMA);
        self::assertSame('keyword.relationship.v1', KeywordRelationshipGateway::SCHEMA);
        self::assertNotSame('keywords.mcp.v2', KeywordRelationship::SCHEMA);
        self::assertSame('keywords.mcp.v2', KeywordLandscapeGateway::SCHEMA);
    }

    public function test_limits_are_explicit(): void
    {
        self::assertSame(50, KeywordRelationship::RELATED_LIMIT);
        self::assertSame(50, KeywordRelationship::LINK_LIMIT);
        self::assertSame(50, KeywordRelationship::GSC_LIMIT);
        self::assertSame(20, KeywordRelationship::PLANNING_LIMIT);
        self::assertSame(30, KeywordRelationship::DNA_LIMIT);
    }

    public function test_list_slice_reports_truncation(): void
    {
        $items = [];
        for ($i = 1; $i <= 60; $i++) {
            $items[] = ['id' => $i];
        }
        $slice = RelationshipListSlice::fromAll($items, 50);
        self::assertSame(60, $slice->total);
        self::assertSame(50, $slice->returned);
        self::assertTrue($slice->truncated);
        $arr = $slice->toArray();
        self::assertArrayHasKey('total', $arr);
        self::assertArrayHasKey('returned', $arr);
        self::assertArrayHasKey('truncated', $arr);
        self::assertArrayHasKey('items', $arr);
        self::assertCount(50, $arr['items']);
    }

    public function test_gateway_resolves_keyword_ref_and_id(): void
    {
        $gateway = (new ReflectionClass(KeywordRelationshipGateway::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(KeywordRelationshipGateway::class, 'resolveKeywordId');
        $method->setAccessible(true);

        self::assertSame(981, $method->invoke($gateway, ['keyword_id' => 981]));
        self::assertSame(981, $method->invoke($gateway, ['keyword_ref' => 'keyword:981']));
        self::assertSame(0, $method->invoke($gateway, ['keyword_ref' => 'phrase-lookup']));
        self::assertSame(0, $method->invoke($gateway, []));
    }

    public function test_capability_registered_in_gateway_catalog_policy(): void
    {
        self::assertContains('keyword.relationship', ContentProjectAgentGateway::READ_CAPABILITIES);

        $gatewaySrc = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectAgentGateway::class))->getFileName(),
        );
        self::assertStringContainsString('keyword.relationship', $gatewaySrc);
        self::assertStringContainsString('KeywordRelationshipGateway', $gatewaySrc);
        self::assertStringContainsString('mapKeywordRelationship', $gatewaySrc);
        self::assertStringNotContainsString('keyword_intelligence.', $gatewaySrc);

        $catalogSrc = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectMcpToolCatalog::class))->getFileName(),
        );
        self::assertStringContainsString("'keyword.relationship'", $catalogSrc);
        self::assertStringContainsString('keyword.relationship.v1', $catalogSrc);
        self::assertStringContainsString('Does not write seo_mcp_source_snapshots', $catalogSrc);
        self::assertStringNotContainsString('SeoMcpSourceSnapshot', $catalogSrc);
        self::assertStringNotContainsString('Services\\MonthlyMcp', $catalogSrc);
        self::assertStringNotContainsString('keyword_intelligence.', $catalogSrc);

        $policySrc = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectAgentPolicy::class))->getFileName(),
        );
        self::assertStringContainsString("capability === 'keyword.relationship'", $policySrc);
    }

    public function test_read_model_does_not_write_snapshots_or_invent_semantics(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(KeywordRelationshipReadModel::class))->getFileName(),
        );
        self::assertStringContainsString('never writes seo_mcp_source_snapshots', $src);
        self::assertStringNotContainsString('McpSourceKey', $src);
        self::assertStringNotContainsString('::create(', $src);
        self::assertStringNotContainsString('->insert(', $src);
        self::assertStringNotContainsString('semantic_neighbor', $src);
        self::assertStringContainsString("relation_type' => 'same_topic'", $src);
        self::assertStringContainsString('GSC_CANONICAL_TYPES', $src);
        self::assertStringContainsString('focus_article_without_topic', $src);
        self::assertStringContainsString('KeywordLandscapeGateway', $src);
        $gscBody = strtolower($this->methodBody($src, 'loadGsc'));
        self::assertStringNotContainsString('nearkeyword', $gscBody);
        self::assertStringNotContainsString('mappingtype::near', $gscBody);
    }

    public function test_lock_axes_are_explicit_and_not_conflated(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(KeywordRelationshipReadModel::class))->getFileName(),
        );
        $coreBody = $this->methodBody($src, 'buildKeywordCore');
        self::assertStringContainsString("'source_locked'", $coreBody);
        self::assertStringContainsString('source_locked', $coreBody);
        self::assertStringContainsString("'membership_locked'", $coreBody);
        self::assertStringContainsString('is_locked', $coreBody);
        self::assertStringNotContainsString("'locked'", $coreBody);
        self::assertStringContainsString('loadTopicMembership', $src);
    }

    public function test_internal_links_are_focus_article_neighborhood_only(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(KeywordRelationshipReadModel::class))->getFileName(),
        );
        $body = $this->methodBody($src, 'loadInternalLinks');
        self::assertStringContainsString('SeoLinkMapType::Internal', $body);
        self::assertStringContainsString("target_article_id', \$focusId", $body);
        self::assertStringContainsString("source_article_id', \$focusId", $body);
        self::assertStringContainsString('whereHas(\'sourceArticle\'', $body);
        self::assertStringContainsString('whereHas(\'targetArticle\'', $body);
        self::assertStringNotContainsString("where('keyword_id'", $body);
        self::assertStringNotContainsString('target_external_url', $body);
        self::assertStringNotContainsString('WikiTrust', $body);
        self::assertStringNotContainsString('External', $body);
    }

    public function test_gateway_documents_no_snapshot_and_two_consumers(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(KeywordRelationshipGateway::class))->getFileName(),
        );
        self::assertStringContainsString('seo_mcp_source_snapshots', $src);
        self::assertStringContainsString('keyword.relationship', $src);
        self::assertStringContainsString('Keywords Relationship UI', $src);
        self::assertStringContainsString('never writes', strtolower($src));
    }

    public function test_graph_presenter_keeps_echarts_out_of_mcp_dto(): void
    {
        $dto = new KeywordRelationship(
            siteId: 1,
            keyword: ['ref' => 'keyword:1', 'id' => 1, 'phrase' => 'alpha'],
            topics: [['id' => 9, 'name' => 'T', 'topic_ref' => 'topic:9']],
            focusArticles: [['article_id' => 3, 'title' => 'Art', 'article_ref' => 'article:3']],
            dna: ['topic_dna' => [['phrase' => 'dna1', 'weight' => 2]]],
            relatedKeywords: RelationshipListSlice::fromAll([
                ['keyword_ref' => 'keyword:2', 'id' => 2, 'phrase' => 'beta', 'relation_type' => 'same_topic'],
            ], 50),
            internalLinks: [
                'available' => true,
                'inbound' => RelationshipListSlice::fromAll([], 50)->toArray(),
                'outbound' => RelationshipListSlice::fromAll([], 50)->toArray(),
            ],
            gsc: [
                'available' => true,
                'query_mappings' => RelationshipListSlice::fromAll([], 50)->toArray(),
            ],
            planning: [
                'available' => false,
                'items' => RelationshipListSlice::fromAll([], 20)->toArray(),
            ],
            relationIssues: [],
            availableSections: ['core', 'topics'],
            generatedAt: '2026-01-01T00:00:00+00:00',
            sourceUpdatedAt: null,
        );

        $mcp = $dto->toArray();
        self::assertSame('keyword.relationship.v1', $mcp['schema']);
        self::assertArrayNotHasKey('nodes', $mcp);
        self::assertArrayNotHasKey('edges', $mcp);
        self::assertArrayNotHasKey('categories', $mcp);

        $graph = (new KeywordRelationshipGraphPresenter)->present($dto);
        self::assertSame('keyword:1', $graph['center']);
        $kinds = array_column($graph['nodes'], 'kind');
        self::assertContains('keyword', $kinds);
        self::assertContains('topic', $kinds);
        self::assertContains('article', $kinds);
        self::assertContains('dna', $kinds);
        self::assertContains('related_keyword', $kinds);
        self::assertNotContains('gsc', $kinds);

        $edgeLabels = array_map(
            static fn (array $e): string => (string) ($e['label']['formatter'] ?? ''),
            $graph['edges'],
        );
        self::assertContains('member_of', $edgeLabels);
        self::assertContains('focus_article', $edgeLabels);
        self::assertContains('has_dna', $edgeLabels);
        self::assertContains('same_topic', $edgeLabels);
        self::assertNotContains('semantic_neighbor', $edgeLabels);
    }

    public function test_graph_presenter_internal_link_edges_are_focus_article_centric(): void
    {
        $dto = new KeywordRelationship(
            siteId: 1,
            keyword: ['ref' => 'keyword:1', 'id' => 1, 'phrase' => 'alpha'],
            topics: [],
            focusArticles: [['article_id' => 10, 'title' => 'Focus', 'article_ref' => 'article:10']],
            dna: ['topic_dna' => []],
            relatedKeywords: RelationshipListSlice::fromAll([], 50),
            internalLinks: [
                'available' => true,
                'inbound' => RelationshipListSlice::fromAll([
                    [
                        'link_map_id' => 101,
                        'source_article_id' => 20,
                        'target_article_id' => 10,
                        'anchor_text' => 'in',
                        'link_type' => 'internal',
                        'direction' => 'inbound',
                    ],
                ], 50)->toArray(),
                'outbound' => RelationshipListSlice::fromAll([
                    [
                        'link_map_id' => 102,
                        'source_article_id' => 10,
                        'target_article_id' => 30,
                        'anchor_text' => 'out',
                        'link_type' => 'internal',
                        'direction' => 'outbound',
                    ],
                    [
                        'link_map_id' => 999,
                        'link_type' => 'external',
                        'anchor_text' => 'skip-me',
                        'direction' => 'outbound',
                    ],
                ], 50)->toArray(),
            ],
            gsc: [
                'available' => false,
                'query_mappings' => RelationshipListSlice::fromAll([], 50)->toArray(),
            ],
            planning: [
                'available' => false,
                'items' => RelationshipListSlice::fromAll([], 20)->toArray(),
            ],
            relationIssues: [],
            availableSections: ['core', 'focus_articles', 'internal_links'],
            generatedAt: '2026-01-01T00:00:00+00:00',
            sourceUpdatedAt: null,
        );

        $graph = (new KeywordRelationshipGraphPresenter)->present($dto, [
            'topic' => false,
            'article' => true,
            'dna' => false,
            'related_keyword' => false,
            'gsc' => false,
            'internal_link' => true,
            'planning' => false,
        ]);

        $nodeIds = array_column($graph['nodes'], 'id');
        self::assertContains('article:10', $nodeIds);
        self::assertContains('link:101', $nodeIds);
        self::assertContains('link:102', $nodeIds);
        self::assertNotContains('link:999', $nodeIds);

        $byFormatter = [];
        foreach ($graph['edges'] as $edge) {
            $byFormatter[(string) ($edge['label']['formatter'] ?? '')] = $edge;
        }
        self::assertSame('link:101', $byFormatter['internal_link_inbound']['source'] ?? null);
        self::assertSame('article:10', $byFormatter['internal_link_inbound']['target'] ?? null);
        self::assertSame('article:10', $byFormatter['internal_link_outbound']['source'] ?? null);
        self::assertSame('link:102', $byFormatter['internal_link_outbound']['target'] ?? null);
    }

    public function test_dto_to_array_exposes_meta_limits_and_issues(): void
    {
        $dto = new KeywordRelationship(
            siteId: 7,
            keyword: ['id' => 1, 'phrase' => 'x'],
            topics: [],
            focusArticles: [['article_id' => 1]],
            dna: ['topic_dna' => []],
            relatedKeywords: RelationshipListSlice::fromAll([], 50),
            internalLinks: [
                'available' => false,
                'inbound' => RelationshipListSlice::fromAll([], 50)->toArray(),
                'outbound' => RelationshipListSlice::fromAll([], 50)->toArray(),
            ],
            gsc: [
                'available' => false,
                'query_mappings' => RelationshipListSlice::fromAll([], 50)->toArray(),
            ],
            planning: [
                'available' => false,
                'items' => RelationshipListSlice::fromAll([], 20)->toArray(),
            ],
            relationIssues: ['focus_article_without_topic'],
            availableSections: ['core'],
            generatedAt: 'now',
            sourceUpdatedAt: null,
        );

        $arr = $dto->toArray();
        self::assertSame(['focus_article_without_topic'], $arr['meta']['relation_issues']);
        self::assertSame(50, $arr['meta']['limits']['related_keywords']);
        self::assertSame(7, $arr['site_id']);
    }

    private function methodBody(string $src, string $method): string
    {
        $pattern = '/function\s+'.preg_quote($method, '/').'\s*\([^)]*\)\s*(?::\s*[^{]+)?\{/';
        if (! preg_match($pattern, $src, $m, PREG_OFFSET_CAPTURE)) {
            self::fail('Method '.$method.' not found');
        }
        $start = (int) $m[0][1] + strlen($m[0][0]);
        $depth = 1;
        $len = strlen($src);
        for ($i = $start; $i < $len; $i++) {
            $ch = $src[$i];
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($src, $start, $i - $start);
                }
            }
        }

        self::fail('Unclosed method '.$method);
    }
}
