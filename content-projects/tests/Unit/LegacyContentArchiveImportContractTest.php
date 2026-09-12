<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Console\ImportLegacyContentArchiveCommand;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ContentProjectArchivePreview;
use Omnichannel\Addons\ContentProjects\Services\ImportLegacyContentArchiveService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\CanonicalArchiveListDashboardBuilder;
use Omnichannel\Addons\Content\Services\ArticleCompletedArchiveQueryService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Staging contracts: Legacy import + Table/List preview (dual-view).
 */
final class LegacyContentArchiveImportContractTest extends TestCase
{
    public function test_import_service_does_not_call_strong_archive(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ImportLegacyContentArchiveService::class))->getFileName(),
        );

        self::assertStringContainsString('META_IMPORT_SOURCE', $source);
        self::assertStringContainsString('Legacy articles', $source);
        self::assertStringContainsString("'article_id' => null", $source);
        self::assertStringContainsString('STATUS_PENDING', $source);
        self::assertStringContainsString('resolveProjectMonth', $source);
        self::assertStringNotContainsString('use Omnichannel\\Addons\\ContentProjects\\Services\\ArchiveContentProjectService', $source);
        self::assertStringNotContainsString('destroyInTransaction', $source);
        self::assertStringNotContainsString('resetProjectTasksForFreshFlow', $source);
        self::assertDoesNotMatchRegularExpression('/ArchiveContentProjectService::archive\s*\(/', $source);
    }

    public function test_import_command_exposes_dry_run_apply_reconcile(): void
    {
        $command = new ImportLegacyContentArchiveCommand;
        $signature = (string) (new ReflectionClass($command))->getProperty('signature')->getDefaultValue();

        self::assertStringContainsString('content-project:import-legacy-archive', $signature);
        self::assertStringContainsString('dry-run', $signature);
        self::assertStringContainsString('apply', $signature);
        self::assertStringContainsString('reconcile', $signature);
    }

    public function test_preview_defaults_to_table_and_list_uses_canonical_builder(): void
    {
        $preview = (string) file_get_contents(
            (new ReflectionClass(ContentProjectArchivePreview::class))->getFileName(),
        );

        self::assertStringContainsString("articleViewMode = 'table'", $preview);
        self::assertStringContainsString("'as' => 'view'", $preview);
        self::assertStringContainsString('CanonicalArchiveListDashboardBuilder', $preview);
        self::assertStringContainsString('setArticleViewMode', $preview);
        self::assertStringContainsString('assertCanAccessArchivePreview', $preview);
        self::assertStringNotContainsString('ArticleCompletedArchiveQueryService', $preview);

        $listPath = dirname(__DIR__, 3)
            .DIRECTORY_SEPARATOR.'seo-content-ai-compat'
            .DIRECTORY_SEPARATOR.'resources'
            .DIRECTORY_SEPARATOR.'views'
            .DIRECTORY_SEPARATOR.'filament'
            .DIRECTORY_SEPARATOR.'resources'
            .DIRECTORY_SEPARATOR.'seo-project-resource'
            .DIRECTORY_SEPARATOR.'partials'
            .DIRECTORY_SEPARATOR.'archive-preview-list.blade.php';

        self::assertFileExists($listPath);
        $listSource = (string) file_get_contents($listPath);
        self::assertStringNotContainsString('ArticleCompletedArchiveQueryService', $listSource);
        self::assertStringNotContainsString('seo_content_archive_items', $listSource);
    }

    public function test_list_dashboard_builder_groups_by_historical_date_newest_first(): void
    {
        $builder = new CanonicalArchiveListDashboardBuilder;
        $dashboard = $builder->build([
            [
                'item_id' => 1,
                'article_id' => 10,
                'title' => 'A',
                'site_id' => 1,
                'domain' => 'a.test',
                'author' => 'Ann',
                'keyword' => 'kw',
                'completed_at_raw' => '2026-09-04T03:00:00+07:00',
            ],
            [
                'item_id' => 2,
                'article_id' => 11,
                'title' => 'B',
                'site_id' => 2,
                'domain' => 'b.test',
                'author' => 'Bob',
                'keyword' => 'kw2',
                'completed_at_raw' => '2026-09-04T05:00:00+07:00',
            ],
            [
                'item_id' => 3,
                'article_id' => 12,
                'title' => 'C',
                'site_id' => 1,
                'domain' => 'a.test',
                'author' => 'Ann',
                'keyword' => 'kw3',
                'archived_at_raw' => '2026-08-01T10:00:00+07:00',
            ],
        ]);

        self::assertCount(2, $dashboard['groups']);
        self::assertSame('2026-09-04', $dashboard['groups'][0]['date']);
        self::assertSame(2, $dashboard['groups'][0]['count']);
        self::assertSame('2026-08-01', $dashboard['groups'][1]['date']);
        self::assertCount(2, $dashboard['domain_options']);
    }

    public function test_legacy_tab_query_service_still_exists(): void
    {
        self::assertTrue(class_exists(ArticleCompletedArchiveQueryService::class));
        $src = (string) file_get_contents(
            (new ReflectionClass(ArticleCompletedArchiveQueryService::class))->getFileName(),
        );
        self::assertStringContainsString('seo_content_archive_items', $src);
    }
}
