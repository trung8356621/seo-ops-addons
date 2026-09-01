<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations\SiteHealthCardPresenter;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordClassificationVisibility;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordTagResolver;
use Omnichannel\Addons\SiteSync\Services\LinkAnalysis\DomainLinkInventoryReadModel;
use Omnichannel\Addons\SiteSync\Services\V3\SiteSyncV3BulkImporter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class SiteSyncV3LinkInventoryHardeningTest extends TestCase
{
    public function test_reconciler_persists_link_when_anchor_empty_or_url(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(SiteSyncV3BulkImporter::class))->getFileName()
        );
        $method = $this->methodBody($src, 'reconcileAnalysisLinks');

        self::assertStringContainsString("'keyword_id' => \$keywordId", $method);
        self::assertStringContainsString('empty_anchor_href_not_promoted', $method);
        self::assertStringContainsString('url_shaped_anchor_not_promoted', $method);
        self::assertStringNotContainsString('if (! $keyword instanceof Keyword) {', $method);
        self::assertTrue(substr_count($method, 'SeoLinkMap::query()->create') >= 1);
    }

    public function test_upsert_persists_slug_and_permalink_when_present(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(SiteSyncV3BulkImporter::class))->getFileName()
        );
        $method = $this->methodBody($src, 'upsertIdentity');

        self::assertStringContainsString("\$attrs['slug'] = \$slugFromWp", $method);
        self::assertStringContainsString("'meta_key' => 'wp_permalink'", $method);
        self::assertStringContainsString("\$permalink !== ''", $method);
    }

    public function test_domain_health_uses_link_maps_inventory(): void
    {
        $presenter = (string) file_get_contents(
            (new ReflectionClass(SiteHealthCardPresenter::class))->getFileName()
        );
        self::assertStringContainsString('DomainLinkInventoryReadModel', $presenter);
        self::assertStringContainsString('inventory_state', $presenter);
        self::assertStringContainsString('remote_health_state', $presenter);
        self::assertStringNotContainsString("jsonMeta(\$site, 'seo_link_analysis_snapshot')", $presenter);

        $readModel = (string) file_get_contents(
            (new ReflectionClass(DomainLinkInventoryReadModel::class))->getFileName()
        );
        self::assertStringContainsString('seo_link_maps', $readModel);
        self::assertStringContainsString('countOrphanPages', $readModel);
        self::assertStringContainsString('keywordless_links', $readModel);
    }

    public function test_focus_chip_uses_main_articles_not_unclassified_default(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(KeywordClassificationVisibility::class))->getFileName()
        );
        self::assertStringContainsString("whereHas('mainArticles')", $src);
        self::assertStringContainsString('focusWithMainArticle', $src);

        $resolver = (string) file_get_contents(
            (new ReflectionClass(KeywordTagResolver::class))->getFileName()
        );
        $method = $this->methodBody($resolver, 'countPrimaryTags');
        self::assertStringNotContainsString('max(0, $unclassified)', $method);
    }

    public function test_migration_makes_keyword_id_nullable(): void
    {
        $path = dirname(__DIR__, 3)
            .DIRECTORY_SEPARATOR.'search-foundation'
            .DIRECTORY_SEPARATOR.'database'
            .DIRECTORY_SEPARATOR.'migrations'
            .DIRECTORY_SEPARATOR.'2026_08_31_120000_make_seo_link_maps_keyword_id_nullable.php';
        self::assertFileExists($path);
        $src = (string) file_get_contents($path);
        self::assertStringContainsString('MODIFY keyword_id BIGINT UNSIGNED NULL', $src);
        self::assertStringContainsString('nullOnDelete', $src);
    }

    private function methodBody(string $src, string $method): string
    {
        $class = match ($method) {
            'countPrimaryTags' => KeywordTagResolver::class,
            default => SiteSyncV3BulkImporter::class,
        };
        $ref = new ReflectionMethod($class, $method);
        $start = $ref->getStartLine();
        $end = $ref->getEndLine();
        $lines = explode("\n", $src);

        return implode("\n", array_slice($lines, $start - 1, $end - $start + 1));
    }
}
