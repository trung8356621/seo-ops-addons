<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Topic;

use Omnichannel\Addons\SearchIntelligence\Filament\Pages\KeywordRelationshipAppPage;
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
        self::assertStringContainsString('KeywordRelationshipAppPage::appUrl', $listSrc);
        self::assertStringContainsString('relationship_action', $listSrc);
        self::assertStringContainsString('openUrlInNewTab', $listSrc);
    }

    public function test_legacy_page_redirects_without_direct_queries(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(KeywordRelationshipView::class))->getFileName(),
        );
        self::assertStringContainsString('KeywordRelationshipAppPage::appUrl', $src);
        self::assertStringContainsString('redirect', $src);
        self::assertStringNotContainsString('SeoTopicKeyword::', $src);
        self::assertStringNotContainsString('SeoLinkMap::', $src);
        self::assertStringNotContainsString('SeoGscQueryMapping::', $src);
        self::assertStringNotContainsString('KeywordRelationshipGraphPresenter', $src);
    }

    public function test_legacy_blade_and_js_retired_from_production(): void
    {
        $blade = dirname(__DIR__, 4).'/seo-content-ai-compat/resources/views/filament/resources/keywords/pages/keyword-relationship.blade.php';
        if (! is_readable($blade)) {
            $blade = dirname((string) (new ReflectionClass(KeywordRelationshipView::class))->getFileName(), 6)
                .'/../seo-content-ai-compat/resources/views/filament/resources/keywords/pages/keyword-relationship.blade.php';
        }
        self::assertFileExists($blade);
        $bladeSrc = (string) file_get_contents($blade);
        self::assertStringNotContainsString('keyword-relationship-chart.js', $bladeSrc);
        self::assertStringNotContainsString('@vite', $bladeSrc);

        $js = dirname(__DIR__, 3).'/resources/js/keyword-relationship-chart.js';
        self::assertFileExists($js);
        $jsSrc = (string) file_get_contents($js);
        self::assertStringContainsString('retired', strtolower($jsSrc));
        self::assertStringNotContainsString('echarts.init', $jsSrc);
        self::assertStringNotContainsString('cytoscape', $jsSrc);
    }

    public function test_client_vite_does_not_register_relationship_entry(): void
    {
        $candidates = [
            dirname(__DIR__, 5).'/omnichannel-client/vite.config.js',
            dirname(__DIR__, 4).'/../omnichannel-client/vite.config.js',
            'D:/work/omnichannel-client/vite.config.js',
        ];
        $vite = null;
        foreach ($candidates as $path) {
            if (is_readable($path)) {
                $vite = $path;
                break;
            }
        }
        if ($vite === null) {
            self::markTestSkipped('omnichannel-client vite.config.js not reachable from addon test tree.');
        }

        $src = (string) file_get_contents($vite);
        self::assertStringNotContainsString(
            'addons/search-intelligence/resources/js/keyword-relationship-chart.js',
            $src,
        );
    }

    public function test_site_topical_map_unchanged_as_separate_page(): void
    {
        self::assertTrue(class_exists(KeywordTopicalMap::class));
        $pages = KeywordResource::getPages();
        self::assertArrayHasKey('topical-map', $pages);
        self::assertArrayHasKey('relationships', $pages);
        self::assertNotSame($pages['topical-map'], $pages['relationships']);
        self::assertTrue(class_exists(KeywordRelationshipAppPage::class));
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
