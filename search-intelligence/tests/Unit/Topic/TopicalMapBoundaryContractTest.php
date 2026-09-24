<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Topic;

use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\KeywordTopicalMap;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Dto\TopicalMapOverview;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Dto\TopicalMapTopicChildren;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicalMapReadModel;
use Omnichannel\Addons\Seo\Services\KeywordLandscape\KeywordLandscapeGateway;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class TopicalMapBoundaryContractTest extends TestCase
{
    public function test_read_model_depends_on_keyword_landscape_gateway(): void
    {
        $ctor = (new ReflectionClass(TopicalMapReadModel::class))->getConstructor();
        self::assertNotNull($ctor);
        $params = $ctor->getParameters();
        self::assertGreaterThanOrEqual(1, count($params));
        self::assertSame(KeywordLandscapeGateway::class, $params[0]->getType()?->getName());

        $src = (string) file_get_contents((string) (new ReflectionClass(TopicalMapReadModel::class))->getFileName());
        self::assertStringContainsString('landscape->forSite', $src);
        self::assertStringContainsString('landscape->findTopic', $src);
        self::assertStringContainsString('MAX_TOPIC_NODES', $src);
        self::assertStringContainsString('mcpEligibleKeywordIdsForTopic', $src);
        self::assertSame(500, TopicalMapReadModel::MAX_TOPIC_NODES);
        self::assertSame(100, TopicalMapTopicChildren::MAX_CHILDREN);
    }

    public function test_gateway_exposes_topical_map_methods_as_consumer_three(): void
    {
        self::assertTrue(method_exists(KeywordLandscapeGateway::class, 'topicalMapOverview'));
        self::assertTrue(method_exists(KeywordLandscapeGateway::class, 'topicalMapTopicChildren'));

        $overview = new ReflectionMethod(KeywordLandscapeGateway::class, 'topicalMapOverview');
        self::assertSame(TopicalMapOverview::class, $overview->getReturnType()?->getName());

        $src = (string) file_get_contents((string) (new ReflectionClass(KeywordLandscapeGateway::class))->getFileName());
        self::assertStringContainsString('Keywords / Topical Map', $src);
        self::assertStringContainsString('Approved consumers only', $src);
        self::assertStringNotContainsString('consumer #4', strtolower($src));
    }

    public function test_network_is_membership_only_not_invented_semantics(): void
    {
        $src = (string) file_get_contents((string) (new ReflectionClass(TopicalMapReadModel::class))->getFileName());
        self::assertStringContainsString('membershipNeighborhood', $src);
        self::assertStringContainsString('Topic membership only', $src);
        self::assertStringNotContainsString('embedding', strtolower($src));
        self::assertStringNotContainsString('semantic relationship', strtolower($src));
        self::assertStringNotContainsString('internal link', strtolower($src));
    }

    public function test_keywords_resource_registers_topical_map_page(): void
    {
        $src = (string) file_get_contents((string) (new ReflectionClass(KeywordResource::class))->getFileName());
        self::assertStringContainsString("'topical-map' => Pages\\KeywordTopicalMap::route('/topical-map')", $src);

        $nav = (string) file_get_contents(
            dirname(__DIR__, 3).'/src/Filament/Resources/KeywordResource/Pages/Concerns/HasKeywordWorkspaceNavigation.php',
        );
        self::assertStringContainsString("'key' => 'topical-map'", $nav);
        self::assertStringContainsString('TopicalMapAppPage::appUrl', $nav);
        self::assertStringContainsString("'target' => '_blank'", $nav);
        self::assertTrue(class_exists(KeywordTopicalMap::class));
    }

    public function test_ui_defaults_to_tree_and_exposes_three_renderers(): void
    {
        $pageSrc = (string) file_get_contents((string) (new ReflectionClass(KeywordTopicalMap::class))->getFileName());
        self::assertStringContainsString('TopicalMapAppPage::appUrl', $pageSrc);
        self::assertStringContainsString('redirect', $pageSrc);

        $app = (string) file_get_contents(dirname(__DIR__, 3).'/resources/js/topical-map/App.jsx');
        self::assertStringContainsString("readFilterQuery()", $app);
        self::assertStringContainsString("'tree', 'network', 'sunburst'", (string) file_get_contents(
            dirname(__DIR__, 3).'/resources/js/topical-map/components/FilterBar.jsx',
        ));

        $options = (string) file_get_contents(dirname(__DIR__, 3).'/resources/js/topical-map/charts/options.js');
        self::assertStringContainsString("type: 'tree'", $options);
        self::assertStringContainsString("type: 'graph'", $options);
        self::assertStringContainsString("type: 'sunburst'", $options);
        self::assertStringContainsString('Topic membership', $options);
        self::assertStringNotContainsString('cytoscape', strtolower($options));
        self::assertStringNotContainsString('reactflow', strtolower($options));

        $js = (string) file_get_contents(dirname(__DIR__, 3).'/resources/js/topical-map-chart.js');
        self::assertStringContainsString('retired', strtolower($js));
        self::assertStringNotContainsString("from 'echarts/core'", $js);
    }

    public function test_overview_dto_shape(): void
    {
        $dto = new TopicalMapOverview(
            siteId: 9,
            topics: [[
                'id' => 1,
                'name' => 'Balo',
                'mcp' => 64.2,
                'dna_count' => 8,
                'article_count' => 12,
                'keyword_count' => 23,
                'coverage' => 'partial',
                'status' => 'active',
                'has_children' => true,
                'tags' => [['id' => 1, 'name' => 'B2B']],
            ]],
            topicCount: 1,
            totalArticles: 12,
            totalKeywords: 23,
            sourceUpdatedAt: '2026-09-01T00:00:00+00:00',
            tagFacets: [['id' => 1, 'name' => 'B2B', 'topic_count' => 1]],
            untaggedCount: 0,
        );
        $arr = $dto->toArray();
        self::assertSame(9, $arr['site_id']);
        self::assertSame(1, $arr['summary']['topic_count']);
        self::assertSame(12, $arr['summary']['total_articles']);
        self::assertSame(23, $arr['summary']['total_keywords']);
        self::assertSame(0, $arr['summary']['untagged_count']);
        self::assertSame('Balo', $arr['topics'][0]['name']);
        self::assertTrue($arr['topics'][0]['has_children']);
        self::assertSame('B2B', $arr['topics'][0]['tags'][0]['name']);
        self::assertSame('B2B', $arr['tag_facets'][0]['name']);
    }

    public function test_filter_topics_by_tags_uses_or_and_untagged(): void
    {
        $model = (new ReflectionClass(TopicalMapReadModel::class))->newInstanceWithoutConstructor();
        $topics = [
            ['id' => 1, 'name' => 'A', 'tags' => [['id' => 10, 'name' => 'B2B']]],
            ['id' => 2, 'name' => 'B', 'tags' => [['id' => 11, 'name' => 'OEM']]],
            ['id' => 3, 'name' => 'C', 'tags' => []],
        ];

        $all = $model->filterTopicsByTags($topics, [], false);
        self::assertCount(3, $all);

        $or = $model->filterTopicsByTags($topics, [10, 11], false);
        self::assertSame([1, 2], array_column($or, 'id'));

        $untagged = $model->filterTopicsByTags($topics, [], true);
        self::assertSame([3], array_column($untagged, 'id'));

        $mixed = $model->filterTopicsByTags($topics, [10], true);
        self::assertSame([1, 3], array_column($mixed, 'id'));
    }

    public function test_children_dto_reports_truncation(): void
    {
        $dto = new TopicalMapTopicChildren(
            siteId: 1,
            topicId: 5,
            topicName: 'Bags',
            children: [
                ['type' => 'keyword', 'id' => 99, 'name' => 'balo tre em', 'article_count' => 0],
            ],
            total: 150,
            truncated: true,
        );
        $arr = $dto->toArray();
        self::assertSame(5, $arr['topic_id']);
        self::assertSame(150, $arr['total']);
        self::assertSame(1, $arr['showing']);
        self::assertTrue($arr['truncated']);
    }
}
