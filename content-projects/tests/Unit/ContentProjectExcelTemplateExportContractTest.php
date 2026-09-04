<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ContentProjectArchive;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectArchivedMonthExportService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectExcelRawTemplateDownloadService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ExcelTemplate\ArchivedMonthExcelStatsBuilder;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ExcelTemplate\ArchivedMonthExcelStatsDocument;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ExcelTemplate\ArchivedMonthExcelTemplateVariableFactory;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ExcelTemplate\ExcelDataLayoutMode;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ExcelTemplate\ExcelDetailColumnRegistry;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ExcelTemplate\ExcelDetailColumnScanner;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ExcelTemplate\ExcelRawTemplateGenerator;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ExcelTemplate\ExcelRawTemplateStatsSchema;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ExcelTemplate\ExcelTemplateHelpSheetBuilder;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ExcelTemplate\ExcelTemplateManagedSheetWriter;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Tests\Support\LegacyAddonPath;

final class ContentProjectExcelTemplateExportContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = Container::getInstance();
        if (! $app instanceof Container) {
            $app = new Container();
            Container::setInstance($app);
        }

        $app->instance('translator', new class
        {
            public function get(mixed $key, array $replace = [], mixed $locale = null): string
            {
                $key = is_string($key) ? $key : '';
                $map = [
                    'seo-content-ai::filament.projects.indexed' => 'Indexed',
                    'seo-content-ai::filament.projects.not_indexed' => 'Not indexed',
                    'seo-content-ai::filament.projects.archive_export_plan_create' => 'Create',
                    'seo-content-ai::filament.projects.archive_export_plan_rewrite' => 'Rewrite',
                    'seo-content-ai::filament.projects.archive_export_plan_improve' => 'Improve',
                    'seo-content-ai::filament.projects.chart_articles_by_domain' => 'By domain',
                    'seo-content-ai::filament.projects.chart_articles_by_writer' => 'By writer',
                    'seo-content-ai::filament.projects.excel_tpl_table_by_type_label' => 'By type',
                    'seo-content-ai::filament.projects.excel_tpl_table_by_status_label' => 'By status',
                    'seo-content-ai::filament.projects.excel_tpl_table_by_month_label' => 'By month',
                    'seo-content-ai::filament.projects.excel_tpl_table_by_week_label' => 'By week',
                    'seo-content-ai::filament.projects.excel_tpl_table_expands_note' => 'expands',
                    'seo-content-ai::filament.projects.excel_tpl_code_row_note' => 'Do not change codes',
                    'seo-content-ai::filament.projects.archive_export_summary_writer' => 'Nhân viên',
                    'seo-content-ai::filament.projects.archive_export_col_project' => 'Dự án',
                    'seo-content-ai::filament.projects.archive_export_col_domain' => 'Domain',
                    'seo-content-ai::filament.projects.archive_export_col_article' => 'Bài viết',
                    'seo-content-ai::filament.projects.archive_export_col_keyword' => 'Từ khóa',
                    'seo-content-ai::filament.projects.archive_export_col_post_type' => 'Loại bài',
                    'seo-content-ai::filament.projects.archive_export_col_plan' => 'Kế hoạch',
                    'seo-content-ai::filament.projects.archive_export_col_index' => 'Index',
                    'seo-content-ai::filament.projects.archive_export_col_reviewed_at' => 'Reviewed at',
                    'seo-content-ai::filament.projects.archive_export_col_archived_by' => 'Lưu trữ bởi',
                ];

                return $map[$key] ?? (str_contains($key, 'excel_tpl_var_') ? $key : $key);
            }

            public function choice(mixed $key, mixed $number, array $replace = [], mixed $locale = null): string
            {
                return $this->get($key, $replace, $locale);
            }
        });
    }

    /** @return array<string, mixed> */
    private function livePayload(): array
    {
        return [
            'month' => '2026-07',
            'month_label' => '07/2026',
            'total_articles' => 2,
            'unresolved_site_id_count' => 0,
            'by_domain' => [],
            'by_writer' => [],
            'writer_sheets' => [
                [
                    'writer_name' => 'Alice',
                    'sheet_name' => 'Alice',
                    'rows' => [
                        [
                            'project' => 'P1',
                            'domain' => 'a.com',
                            'title' => 'Live Title A',
                            'keyword' => 'kw',
                            'post_type' => 'Post',
                            'plan' => 'Create',
                            'index_status' => 'Indexed',
                            'reviewed_at' => '2026-07-10',
                            'archived_by' => 'Admin',
                        ],
                    ],
                ],
                [
                    'writer_name' => 'Bob',
                    'sheet_name' => 'Bob',
                    'rows' => [
                        [
                            'project' => 'P2',
                            'domain' => 'b.com',
                            'title' => 'Live Title B',
                            'keyword' => 'kw2',
                            'post_type' => 'Post',
                            'plan' => 'Rewrite',
                            'index_status' => 'Not indexed',
                            'reviewed_at' => '2026-07-12',
                            'archived_by' => 'Admin',
                        ],
                    ],
                ],
            ],
        ];
    }

    public function test_raw_by_writer_has_dual_headers_and_zero_article_rows(): void
    {
        $book = (new ExcelRawTemplateGenerator())->build(ExcelDataLayoutMode::ByWriterSheet);
        $titles = array_map(static fn ($s) => $s->getTitle(), $book->getAllSheets());
        self::assertSame([
            ExcelTemplateHelpSheetBuilder::SHEET_NAME,
            ArchivedMonthExcelStatsDocument::SHEET_NAME,
            ExcelRawTemplateGenerator::WRITER_TEMPLATE_SHEET,
        ], $titles);

        $tpl = $book->getSheetByName(ExcelRawTemplateGenerator::WRITER_TEMPLATE_SHEET);
        self::assertNotNull($tpl);
        self::assertSame('Dự án', $tpl->getCell([1, 1])->getValue());
        self::assertSame('project_name', $tpl->getCell([1, 2])->getValue());
        self::assertSame('domain', $tpl->getCell([2, 2])->getValue());
        self::assertSame('article_title', $tpl->getCell([3, 2])->getValue());
        self::assertNull($tpl->getCell([1, 3])->getValue());
        self::assertNull($tpl->getCell([2, 3])->getValue());
        self::assertNull($tpl->getCell([3, 3])->getValue());

        $help = $book->getSheetByName(ExcelTemplateHelpSheetBuilder::SHEET_NAME);
        self::assertNotNull($help);
        $helpFlat = '';
        foreach ($help->toArray() as $row) {
            $helpFlat .= implode('|', array_map(static fn ($c) => (string) $c, $row));
        }
        self::assertStringContainsString('HEADING VÀ MÃ CỘT', $helpFlat);
        self::assertStringContainsString('project_name', $helpFlat);
    }

    public function test_raw_single_data_dual_headers_only(): void
    {
        $book = (new ExcelRawTemplateGenerator())->build(ExcelDataLayoutMode::SingleDataSheet);
        $data = $book->getSheetByName('DATA');
        self::assertNotNull($data);
        self::assertSame('Nhân viên', $data->getCell([1, 1])->getValue());
        self::assertSame('writer_name', $data->getCell([1, 2])->getValue());
        self::assertSame('Dự án', $data->getCell([2, 1])->getValue());
        self::assertSame('project_name', $data->getCell([2, 2])->getValue());
        self::assertNull($data->getCell([1, 3])->getValue());
        self::assertNull($data->getCell([2, 3])->getValue());
    }

    public function test_raw_stats_schema_is_placeholders_only(): void
    {
        $flat = '';
        foreach (ExcelRawTemplateStatsSchema::rows() as $row) {
            $flat .= implode(' ', array_map(static fn ($c) => (string) $c, $row));
        }
        self::assertStringContainsString('{{articles.indexed}}', $flat);
        self::assertStringContainsString('{{table.articles_by_domain}}', $flat);
        self::assertStringNotContainsString('Live Title', $flat);
    }

    public function test_raw_generator_source_does_not_call_workload_or_payload(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ExcelRawTemplateGenerator::class))->getFileName(),
        );
        self::assertStringNotContainsString('buildPayload', $src);
        self::assertStringNotContainsString('itemRows', $src);
        self::assertStringNotContainsString('Workload', $src);
        self::assertStringNotContainsString('writer_sheets', $src);

        $dl = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectExcelRawTemplateDownloadService::class))->getFileName(),
        );
        self::assertStringNotContainsString('buildPayload', $dl);
        self::assertStringNotContainsString('itemRows', $dl);
    }

    public function test_production_by_writer_fills_from_row_3_using_codes(): void
    {
        $raw = (new ExcelRawTemplateGenerator())->build(ExcelDataLayoutMode::ByWriterSheet);
        $stats = (new ArchivedMonthExcelStatsBuilder(null))->build($this->livePayload(), false);
        $factory = new ArchivedMonthExcelTemplateVariableFactory();

        (new ExcelTemplateManagedSheetWriter())->fillProductionWorkbook(
            $raw,
            $this->livePayload(),
            ExcelDataLayoutMode::ByWriterSheet,
            $stats,
            $factory->buildScalarRegistry(),
            $factory->buildTableRegistry(),
        );

        $titles = array_map(static fn ($s) => $s->getTitle(), $raw->getAllSheets());
        self::assertNotContains(ExcelTemplateHelpSheetBuilder::SHEET_NAME, $titles);
        self::assertNotContains(ExcelRawTemplateGenerator::WRITER_TEMPLATE_SHEET, $titles);
        self::assertContains('STATS', $titles);
        self::assertContains('Alice', $titles);

        $alice = $raw->getSheetByName('Alice');
        self::assertNotNull($alice);
        self::assertSame('project_name', $alice->getCell([1, 2])->getValue());
        self::assertSame('Live Title A', $alice->getCell([3, 3])->getValue());
        self::assertSame('P1', $alice->getCell([1, 3])->getValue());
    }

    public function test_production_respects_reordered_columns(): void
    {
        $raw = (new ExcelRawTemplateGenerator())->build(ExcelDataLayoutMode::ByWriterSheet);
        $tpl = $raw->getSheetByName(ExcelRawTemplateGenerator::WRITER_TEMPLATE_SHEET);
        self::assertNotNull($tpl);

        // Reorder: domain | article_title | project_name in A/B/C; clear rest of managed headers.
        $tpl->setCellValue([1, 1], 'Domain');
        $tpl->setCellValue([1, 2], 'domain');
        $tpl->setCellValue([2, 1], 'Tiêu đề SEO');
        $tpl->setCellValue([2, 2], 'article_title');
        $tpl->setCellValue([3, 1], 'Dự án');
        $tpl->setCellValue([3, 2], 'project_name');
        for ($col = 4; $col <= 12; $col++) {
            $tpl->setCellValue([$col, 1], null);
            $tpl->setCellValue([$col, 2], null);
        }

        $stats = (new ArchivedMonthExcelStatsBuilder(null))->build($this->livePayload(), false);
        $factory = new ArchivedMonthExcelTemplateVariableFactory();
        (new ExcelTemplateManagedSheetWriter())->fillProductionWorkbook(
            $raw,
            $this->livePayload(),
            ExcelDataLayoutMode::ByWriterSheet,
            $stats,
            $factory->buildScalarRegistry(),
            $factory->buildTableRegistry(),
        );

        $alice = $raw->getSheetByName('Alice');
        self::assertNotNull($alice);
        self::assertSame('a.com', $alice->getCell([1, 3])->getValue());
        self::assertSame('Live Title A', $alice->getCell([2, 3])->getValue());
        self::assertSame('P1', $alice->getCell([3, 3])->getValue());
        self::assertSame('Tiêu đề SEO', $alice->getCell([2, 1])->getValue());
    }

    public function test_duplicate_system_code_throws(): void
    {
        $raw = (new ExcelRawTemplateGenerator())->build(ExcelDataLayoutMode::ByWriterSheet);
        $tpl = $raw->getSheetByName(ExcelRawTemplateGenerator::WRITER_TEMPLATE_SHEET);
        self::assertNotNull($tpl);
        $tpl->setCellValue([6, 2], 'domain');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Column code 'domain' appears more than once.");

        $scanner = new ExcelDetailColumnScanner();
        $scanner->scan($tpl);
    }

    public function test_production_single_data_fills_from_row_3(): void
    {
        $raw = (new ExcelRawTemplateGenerator())->build(ExcelDataLayoutMode::SingleDataSheet);
        $stats = (new ArchivedMonthExcelStatsBuilder(null))->build($this->livePayload(), false);
        $factory = new ArchivedMonthExcelTemplateVariableFactory();

        (new ExcelTemplateManagedSheetWriter())->fillProductionWorkbook(
            $raw,
            $this->livePayload(),
            ExcelDataLayoutMode::SingleDataSheet,
            $stats,
            $factory->buildScalarRegistry(),
            $factory->buildTableRegistry(),
        );

        $data = $raw->getSheetByName('DATA');
        self::assertNotNull($data);
        self::assertSame('writer_name', $data->getCell([1, 2])->getValue());
        self::assertSame('Alice', $data->getCell([1, 3])->getValue());
        self::assertSame('Live Title A', $data->getCell([4, 3])->getValue());
        self::assertSame('Bob', $data->getCell([1, 4])->getValue());
    }

    public function test_column_registry_codes_are_stable(): void
    {
        $registry = new ExcelDetailColumnRegistry();
        self::assertSame([
            'project_name',
            'domain',
            'article_title',
            'keyword',
            'article_type',
            'plan_type',
            'index_status',
            'reviewer_name',
            'archived_by',
        ], $registry->writerSheetCodes());
        self::assertSame('writer_name', $registry->dataSheetCodes()[0]);
    }

    public function test_ui_uses_raw_template_download_not_sample_export(): void
    {
        $blade = LegacyAddonPath::read(
            'resources/views/filament/resources/seo-project-resource/pages/content-project-archive.blade.php',
        );
        self::assertStringContainsString('downloadRawTemplate', $blade);
        self::assertStringContainsString('excel_tpl_download_raw', $blade);
        self::assertStringNotContainsString('exportSampleLayout', $blade);
        self::assertStringNotContainsString('getExcelTemplateDictionary', $blade);

        $page = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectArchive::class))->getFileName(),
        );
        self::assertStringContainsString('downloadRawTemplate', $page);
        self::assertStringContainsString('ContentProjectExcelRawTemplateDownloadService', $page);
        self::assertStringNotContainsString('SampleExportService', $page);
        self::assertStringNotContainsString('exportSampleLayout', $page);
    }

    public function test_export_service_still_shares_writer_row_values(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectArchivedMonthExportService::class))->getFileName(),
        );
        self::assertStringContainsString('function writerRowValues', $src);
        self::assertStringContainsString('writeCombinedDataSheet', $src);
        self::assertStringContainsString('writeStatsSheet', $src);
        self::assertStringContainsString('writeDualHeaderRows', $src);
        self::assertStringContainsString('ExcelDetailColumnRegistry', $src);
    }
}
