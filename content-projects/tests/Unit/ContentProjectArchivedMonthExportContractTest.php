<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ContentProjectArchive;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectArchivedMonthExportService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectArchivedMonthlyWorkloadService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectMonthlyWorkloadService;
use Omnichannel\Addons\ContentProjects\Services\ContentProjectArchiveExportService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectArchivedMonthExportAssembler;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemDomainResolver;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ExcelSheetNameSanitizer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;

/**
 * Archived Projects: no project-level Domain column; Export month workbook;
 * Domain on detail rows from item.site_id only.
 */
final class ContentProjectArchivedMonthExportContractTest extends TestCase
{
    public function test_archived_table_removes_project_domain_column_keeps_actions(): void
    {
        $blade = LegacyAddonPath::read(
            'resources/views/filament/resources/seo-project-resource/pages/content-project-archive.blade.php',
        );

        self::assertStringContainsString('archive_col_total', $blade);
        self::assertStringContainsString('archive_col_index', $blade);
        self::assertStringContainsString('archive_col_archived_at', $blade);
        self::assertStringContainsString('archive_col_archived_by', $blade);
        self::assertStringContainsString('archive_export', $blade);
        self::assertStringContainsString('exportArchive', $blade);
        self::assertStringContainsString('restoreArchive', $blade);
        self::assertStringContainsString('archive_preview', $blade);

        self::assertStringNotContainsString('$archive->site?->domain', $blade);
        self::assertStringNotContainsString('colspan="9"', $blade);
        self::assertStringContainsString('colspan="8"', $blade);

        preg_match('/<thead\b[^>]*>.*?<\/thead>/s', $blade, $matches);
        self::assertNotEmpty($matches);
        $thead = $matches[0];
        self::assertStringContainsString('projects.owner', $thead);
        self::assertStringContainsString('projects.month', $thead);
        self::assertStringNotContainsString('article_list.domain', $thead);
    }

    public function test_export_month_action_and_service_exist(): void
    {
        $page = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectArchive::class))->getFileName(),
        );
        self::assertStringContainsString('function exportMonth', $page);
        self::assertStringContainsString('ContentProjectArchivedMonthExportService', $page);
        self::assertStringContainsString('exportArchive', $page);

        self::assertTrue(class_exists(ContentProjectArchivedMonthExportService::class));
        self::assertTrue(class_exists(ContentProjectArchivedMonthlyWorkloadService::class));
        self::assertTrue((new ReflectionClass(ContentProjectArchivedMonthExportService::class))->hasMethod('streamDownload'));
        self::assertTrue((new ReflectionClass(ContentProjectArchivedMonthlyWorkloadService::class))->hasMethod('summary'));
        self::assertTrue((new ReflectionClass(ContentProjectArchivedMonthlyWorkloadService::class))->hasMethod('itemRows'));
    }

    public function test_month_export_workbook_structure_summary_then_writer_sheets(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectArchivedMonthExportService::class))->getFileName(),
        );

        self::assertStringContainsString('archive_export_sheet_summary', $src);
        self::assertStringContainsString('writeWriterSheet', $src);
        self::assertStringContainsString('writer_sheets', $src);
        self::assertStringContainsString('Archived-', $src);
        self::assertStringContainsString('unresolved_site_id_count', $src);
        self::assertStringContainsString('ContentProjectArchivedMonthlyWorkloadService', $src);
        self::assertStringContainsString('ContentProjectArchivedMonthExportAssembler', $src);
        self::assertStringContainsString('userSheetHeaders', $src);
        self::assertStringContainsString('archive_export_col_index', $src);
        self::assertStringContainsString('archive_export_col_reviewed_at', $src);
        self::assertStringContainsString('chart_articles_by_domain', $src);
        self::assertStringContainsString('ExcelHyperlinkHelper', $src);
        self::assertStringContainsString('ExcelSheetColumnAutoSizer', $src);
    }

    public function test_summary_reuses_archived_monthly_workload_sql_aggregation(): void
    {
        $exportSrc = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectArchivedMonthExportService::class))->getFileName(),
        );
        $workloadSrc = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectArchivedMonthlyWorkloadService::class))->getFileName(),
        );
        $coreSrc = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectMonthlyWorkloadService::class))->getFileName(),
        );

        self::assertStringContainsString('->summary(', $exportSrc);
        self::assertStringContainsString('by_domain', $exportSrc);
        self::assertStringContainsString('by_writer', $exportSrc);

        self::assertStringContainsString('SCOPE_ARCHIVED', $workloadSrc);
        self::assertStringContainsString('item_count', $workloadSrc);
        self::assertStringContainsString('writer_name', $workloadSrc);
        self::assertStringContainsString('t.site_id', $workloadSrc);
        self::assertStringContainsString('archivedExecutionItemQuery', $workloadSrc);

        self::assertStringContainsString('t.site_id', $coreSrc);
        self::assertStringContainsString('archivedExecutionItemQuery', $coreSrc);
        self::assertStringNotContainsString('groupBy(\'p.site_id\')', $coreSrc);
        self::assertStringNotContainsString('p.site_id as site_id', $coreSrc);
    }

    public function test_assembler_groups_by_writer_and_resolves_item_domain(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectArchivedMonthExportAssembler::class))->getFileName(),
        );

        self::assertStringContainsString('ContentProjectItemDomainResolver', $src);
        self::assertStringContainsString('ExcelSheetNameSanitizer', $src);
        self::assertStringContainsString('writer_sheets', $src);
        self::assertStringContainsString("'domain' => \$domain", $src);
        self::assertStringNotContainsString('project.site_id', $src);
        self::assertStringNotContainsString('project_site_id', $src);
    }

    public function test_assembler_summary_counts_match_spec_example(): void
    {
        $assembler = new ContentProjectArchivedMonthExportAssembler(new ContentProjectItemDomainResolver());

        $items = [
            $this->item(1, 'User 1', 'Project A', 1),
            $this->item(1, 'User 1', 'Project A', 1),
            $this->item(1, 'User 1', 'Project A', 2),
            $this->item(2, 'User 2', 'Project B', 2),
            $this->item(2, 'User 2', 'Project B', 3),
        ];

        $payload = $assembler->assemble('2026-07', '07/2026', $items, [
            1 => 'site1.example',
            2 => 'site2.example',
            3 => 'site3.example',
        ]);

        $domainCounts = [];
        foreach ($payload['by_domain'] as $row) {
            $domainCounts[(int) $row['site_id']] = (int) $row['item_count'];
        }
        self::assertSame(2, $domainCounts[1]);
        self::assertSame(2, $domainCounts[2]);
        self::assertSame(1, $domainCounts[3]);

        $writerCounts = [];
        foreach ($payload['by_writer'] as $row) {
            $writerCounts[(int) $row['user_id']] = (int) $row['item_count'];
        }
        self::assertSame(3, $writerCounts[1]);
        self::assertSame(2, $writerCounts[2]);
        self::assertCount(2, $payload['writer_sheets']);
    }

    public function test_multi_domain_project_flattens_to_item_rows_with_domains(): void
    {
        $assembler = new ContentProjectArchivedMonthExportAssembler(new ContentProjectItemDomainResolver());

        $items = [];
        for ($i = 0; $i < 4; $i++) {
            $items[] = $this->item(9, 'Writer A', 'Multi project', 10);
        }
        for ($i = 0; $i < 3; $i++) {
            $items[] = $this->item(9, 'Writer A', 'Multi project', 20);
        }
        for ($i = 0; $i < 3; $i++) {
            $items[] = $this->item(9, 'Writer A', 'Multi project', 30);
        }

        $payload = $assembler->assemble('2026-07', '07/2026', $items, [
            10 => 'domain-a.com',
            20 => 'domain-b.com',
            30 => 'domain-c.com',
        ]);

        self::assertSame(10, $payload['total_articles']);
        self::assertCount(1, $payload['writer_sheets']);
        self::assertCount(10, $payload['writer_sheets'][0]['rows']);

        $domainCounts = [];
        foreach ($payload['by_domain'] as $row) {
            $domainCounts[(string) $row['domain']] = (int) $row['item_count'];
        }
        self::assertSame(4, $domainCounts['domain-a.com']);
        self::assertSame(3, $domainCounts['domain-b.com']);
        self::assertSame(3, $domainCounts['domain-c.com']);

        foreach ($payload['writer_sheets'][0]['rows'] as $row) {
            self::assertNotSame('', $row['domain']);
            self::assertNotSame(ContentProjectItemDomainResolver::UNKNOWN, $row['domain']);
        }
    }

    public function test_same_writer_multiple_projects_one_sheet(): void
    {
        $assembler = new ContentProjectArchivedMonthExportAssembler(new ContentProjectItemDomainResolver());

        $items = [];
        for ($i = 0; $i < 12; $i++) {
            $items[] = $this->item(5, 'User A', 'Project 1', 1);
        }
        for ($i = 0; $i < 8; $i++) {
            $items[] = $this->item(5, 'User A', 'Project 2', 2);
        }

        $payload = $assembler->assemble('2026-07', '07/2026', $items, [
            1 => 'a.com',
            2 => 'b.com',
        ]);

        self::assertCount(1, $payload['writer_sheets']);
        self::assertSame('User A', $payload['writer_sheets'][0]['writer_name']);
        self::assertCount(20, $payload['writer_sheets'][0]['rows']);
        self::assertSame(20, $payload['by_writer'][0]['item_count']);
    }

    public function test_unresolved_item_site_id_exports_unknown_not_project_domain(): void
    {
        $assembler = new ContentProjectArchivedMonthExportAssembler(new ContentProjectItemDomainResolver());

        $payload = $assembler->assemble('2026-07', '07/2026', [
            $this->item(1, 'User 1', 'Project A', 0),
        ], []);

        self::assertSame(1, $payload['unresolved_site_id_count']);
        self::assertSame([], $payload['by_domain']);
        self::assertSame(ContentProjectItemDomainResolver::UNKNOWN, $payload['writer_sheets'][0]['rows'][0]['domain']);
    }

    public function test_item_domain_resolver_never_falls_back_to_project_site(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectItemDomainResolver::class))->getFileName(),
        );

        self::assertStringContainsString('Never falls back to project.site_id', $src);
        self::assertStringContainsString('site_id', $src);
        self::assertStringContainsString('UNKNOWN', $src);
        self::assertStringContainsString('SeoProjectTask', $src);
        self::assertStringNotContainsString('project->site_id', $src);
        self::assertStringNotContainsString('archive->site_id', $src);
    }

    public function test_individual_export_detail_rows_use_item_domain(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectArchiveExportService::class))->getFileName(),
        );

        self::assertStringContainsString('ContentProjectItemDomainResolver', $src);
        self::assertStringContainsString('labelForItem', $src);
        self::assertStringContainsString('resolveItemDomainsOverview', $src);
        self::assertStringContainsString('unresolved_item_site_id_count', $src);
        self::assertStringContainsString('linksGroupedByArticle', $src);
        self::assertStringNotContainsString('$archive->site?->domain', $src);
    }

    public function test_month_export_scopes_archived_execution_month_not_archived_at(): void
    {
        $coreSrc = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectMonthlyWorkloadService::class))->getFileName(),
        );

        self::assertStringContainsString('archivedExecutionItemQuery', $coreSrc);
        self::assertStringContainsString("whereDate('p.month', \$monthDate)", $coreSrc);
        self::assertStringContainsString('SCOPE_ARCHIVED', $coreSrc);
        self::assertStringContainsString('never archived_at month', $coreSrc);
        self::assertStringNotContainsString("whereDate('archived_at'", $coreSrc);
    }

    public function test_sheet_name_sanitizer_handles_duplicates_and_invalid_chars(): void
    {
        $used = [];
        $first = ExcelSheetNameSanitizer::unique('Nguyễn Văn A', $used);
        $second = ExcelSheetNameSanitizer::unique('Nguyễn Văn A', $used);

        self::assertSame('Nguyễn Văn A', $first);
        self::assertSame('Nguyễn Văn A (2)', $second);

        $dirty = ExcelSheetNameSanitizer::sanitize('Name/With?Bad*Chars[x]');
        self::assertStringNotContainsString('/', $dirty);
        self::assertStringNotContainsString('?', $dirty);
        self::assertStringNotContainsString('*', $dirty);
        self::assertStringNotContainsString('[', $dirty);
        self::assertLessThanOrEqual(31, mb_strlen($dirty));
    }

    public function test_charts_and_export_share_archived_monthly_workload_service(): void
    {
        $page = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectArchive::class))->getFileName(),
        );

        self::assertStringContainsString('ContentProjectMonthChartPresenter', $page);
        self::assertStringContainsString('ContentProjectMonthlyWorkloadService', $page);
        self::assertStringContainsString('SCOPE_ARCHIVED', $page);
        self::assertStringContainsString('ContentProjectArchivedMonthExportService', $page);
    }

    public function test_index_and_export_locale_keys_exist_in_en_and_vi(): void
    {
        $keys = [
            'indexed',
            'not_indexed',
            'post_type_post',
            'post_type_product',
            'post_type_category',
            'post_type_product_category',
            'archive_export_col_index',
            'archive_export_col_reviewed_at',
            'archive_export_sheet_summary',
            'archive_export_col_project',
            'archive_export_plan_create',
            'chart_articles_by_domain',
            'chart_articles_by_writer',
        ];

        foreach (['en', 'vi'] as $locale) {
            $path = LegacyAddonPath::resolve("lang/{$locale}/filament.php");
            $src = (string) file_get_contents($path);
            foreach ($keys as $key) {
                self::assertMatchesRegularExpression(
                    "/['\"]".preg_quote($key, '/')."['\"]\\s*=>/",
                    $src,
                    "Missing projects.{$key} in {$locale}",
                );
            }
        }

        $workloadSrc = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectArchivedMonthlyWorkloadService::class))->getFileName(),
        );
        self::assertStringContainsString('filament.projects.indexed', $workloadSrc);
        self::assertStringContainsString('filament.projects.not_indexed', $workloadSrc);
        self::assertStringContainsString('archive_export_plan_', $workloadSrc);
        self::assertStringContainsString('post_type_product', $workloadSrc);
        self::assertStringContainsString('resolveArticleExportFields', $workloadSrc);
        self::assertStringContainsString('wordpress_url', $workloadSrc);
        self::assertStringContainsString('ContentProjectExportReviewedAtResolver', $workloadSrc);
        self::assertStringContainsString("'reviewed_at'", $workloadSrc);
        self::assertStringNotContainsString('p.archived_at as archived_at', $workloadSrc);
        self::assertStringNotContainsString("'title' => trim((string) (\$row->title", $workloadSrc);
    }

    public function test_item_rows_use_article_not_task_planning_fields(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectArchivedMonthlyWorkloadService::class))->getFileName(),
        );

        self::assertStringContainsString('resolveArticleExportFields', $src);
        self::assertStringContainsString('$article->title', $src);
        self::assertStringContainsString('seo_focus_keyword', $src);
        self::assertStringContainsString('Final published article fields only', $src);
    }

    public function test_hyperlink_helper_builds_formula_and_preserves_escape(): void
    {
        $formula = \Omnichannel\Addons\ContentProjects\Support\ContentProject\ExcelHyperlinkHelper::formula(
            'https://example.com/post',
            'My "Title"',
        );

        self::assertSame(
            '=HYPERLINK("https://example.com/post","My ""Title""")',
            $formula,
        );

        $escaped = \Omnichannel\Addons\ContentProjects\Support\ContentProject\ExcelHyperlinkHelper::escapeRowPreservingFormulas([
            $formula,
            '=1+1',
            'plain',
        ]);

        self::assertSame($formula, $escaped[0]);
        self::assertSame('=1+1', $escaped[1]);
        self::assertSame('plain', $escaped[2]);
    }

    public function test_column_auto_sizer_tracks_hyperlink_label_length(): void
    {
        $sizer = new \Omnichannel\Addons\ContentProjects\Support\ContentProject\ExcelSheetColumnAutoSizer();
        $sizer->trackRow([
            \Omnichannel\Addons\ContentProjects\Support\ContentProject\ExcelHyperlinkHelper::formula(
                'https://example.com/a-very-long-url-that-should-not-drive-width',
                'Short title',
            ),
        ]);

        $reflection = new ReflectionClass($sizer);
        $property = $reflection->getProperty('maxWidths');
        $property->setAccessible(true);
        $widths = $property->getValue($sizer);

        self::assertArrayHasKey(0, $widths);
        self::assertLessThan(25.0, $widths[0]);
    }

    public function test_assembler_passes_wordpress_url_to_sheet_rows(): void
    {
        $assembler = new ContentProjectArchivedMonthExportAssembler(new ContentProjectItemDomainResolver());

        $item = $this->item(1, 'User 1', 'Project A', 1);
        $item['wordpress_url'] = 'https://example.com/article';

        $payload = $assembler->assemble('2026-07', '07/2026', [$item], [
            1 => 'site1.example',
        ]);

        self::assertSame('https://example.com/article', $payload['writer_sheets'][0]['rows'][0]['wordpress_url']);
    }

    /**
     * @return array{
     *     writer_id: int,
     *     writer_name: string,
     *     project_name: string,
     *     site_id: int|null,
     *     article_id: int,
     *     title: string,
     *     keyword: string,
     *     wordpress_url?: string,
     *     post_type: string,
     *     plan: string,
     *     index_status: string,
     *     reviewed_at: string,
     *     archived_by: string
     * }
     */
    private function item(int $writerId, string $writerName, string $project, int $siteId): array
    {
        return [
            'writer_id' => $writerId,
            'writer_name' => $writerName,
            'project_name' => $project,
            'site_id' => $siteId,
            'article_id' => $siteId,
            'title' => 'Article '.$siteId,
            'keyword' => 'kw',
            'wordpress_url' => '',
            'post_type' => 'Post',
            'plan' => 'Create',
            'index_status' => 'Indexed',
            'reviewed_at' => '31/08/2026',
            'archived_by' => 'Admin',
        ];
    }
}
