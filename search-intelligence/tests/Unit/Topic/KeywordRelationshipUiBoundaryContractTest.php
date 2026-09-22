<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Topic;

use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\KeywordRelationshipView;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\KeywordTopicalMap;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\ListKeywords;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\KeywordRelationshipReadModel;
use Omnichannel\Addons\Seo\Services\KeywordRelationship\KeywordRelationshipGateway;
use Omnichannel\Addons\Seo\Services\KeywordRelationship\KeywordRelationshipGraphPresenter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class KeywordRelationshipUiBoundaryContractTest extends TestCase
{
    public function test_keywords_exposes_relationships_route_and_action(): void
    {
        $pages = KeywordResource::getPages();
        self::assertArrayHasKey('relationships', $pages);

        $listSrc = (string) file_get_contents(
            (string) (new ReflectionClass(ListKeywords::class))->getFileName(),
        );
        self::assertStringContainsString("Action::make('item_relationships')", $listSrc);
        self::assertStringContainsString("getUrl('relationships'", $listSrc);
        self::assertStringContainsString('relationship_action', $listSrc);
    }

    public function test_relationship_page_uses_gateway_and_graph_presenter(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(KeywordRelationshipView::class))->getFileName(),
        );
        self::assertStringContainsString('KeywordRelationshipGateway', $src);
        self::assertStringContainsString('KeywordRelationshipGraphPresenter', $src);
        self::assertStringNotContainsString('SeoTopicKeyword::', $src);
        self::assertStringNotContainsString('SeoLinkMap::', $src);
        self::assertStringNotContainsString('SeoGscQueryMapping::', $src);
    }

    public function test_blade_and_js_use_echarts_graph_with_side_panel(): void
    {
        $blade = dirname(__DIR__, 4).'/seo-content-ai-compat/resources/views/filament/resources/keywords/pages/keyword-relationship.blade.php';
        if (! is_readable($blade)) {
            $blade = dirname((string) (new ReflectionClass(KeywordRelationshipView::class))->getFileName(), 6)
                .'/../seo-content-ai-compat/resources/views/filament/resources/keywords/pages/keyword-relationship.blade.php';
        }
        self::assertFileExists($blade);
        $bladeSrc = (string) file_get_contents($blade);
        self::assertStringContainsString('keyword-relationship-chart.js', $bladeSrc);
        self::assertStringContainsString('data-keyword-relationship-root', $bladeSrc);
        self::assertStringContainsString('data-keyword-relationship-side', $bladeSrc);
        self::assertStringContainsString('toggleCategory', $bladeSrc);

        $js = dirname(__DIR__, 3).'/resources/js/keyword-relationship-chart.js';
        self::assertFileExists($js);
        $jsSrc = (string) file_get_contents($js);
        self::assertStringContainsString("from 'echarts/charts'", $jsSrc);
        self::assertStringContainsString('GraphChart', $jsSrc);
        self::assertStringNotContainsString('cytoscape', $jsSrc);
        self::assertStringNotContainsString('d3', strtolower($jsSrc));
    }

    public function test_site_topical_map_unchanged_as_separate_page(): void
    {
        self::assertTrue(class_exists(KeywordTopicalMap::class));
        $pages = KeywordResource::getPages();
        self::assertArrayHasKey('topical-map', $pages);
        self::assertArrayHasKey('relationships', $pages);
        self::assertNotSame($pages['topical-map'], $pages['relationships']);
    }

    public function test_read_model_constructor_wiring(): void
    {
        $ctor = (new ReflectionClass(KeywordRelationshipReadModel::class))->getConstructor();
        self::assertNotNull($ctor);
        $types = array_map(
            static fn ($p) => $p->getType()?->getName(),
            $ctor->getParameters(),
        );
        self::assertContains(
            \Omnichannel\Addons\Seo\Services\KeywordLandscape\KeywordLandscapeGateway::class,
            $types,
        );

        $gatewayCtor = (new ReflectionClass(KeywordRelationshipGateway::class))->getConstructor();
        self::assertNotNull($gatewayCtor);
        self::assertSame(
            KeywordRelationshipReadModel::class,
            $gatewayCtor->getParameters()[0]->getType()?->getName(),
        );
        self::assertTrue(class_exists(KeywordRelationshipGraphPresenter::class));
        self::assertTrue((new ReflectionMethod(KeywordRelationshipGateway::class, 'execute'))->isPublic());
    }
}
