<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectArchivedMonthExportAssembler;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectArchiveSocialExportRowExpander;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ExcelHyperlinkHelper;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ExcelSheetColumnAutoSizer;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ExcelTemplate\ArchivedMonthExcelStatsBuilder;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ExcelTemplate\ArchivedMonthExcelStatsDocument;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ExcelTemplate\ExcelDataLayoutMode;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ExcelTemplate\ExcelDetailColumnRegistry;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ExcelTemplate\ExcelDetailRowValueResolver;
use Omnichannel\Addons\Social\Services\ArticleSocialLinkService;
use Omnichannel\Addons\Seo\Support\ExcelFormulaEscaper;
use App\Support\RuntimeLogger;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Month workbook: STATS + writers|DATA (or template prefix + managed sheets).
 * Detail Domain always from item.site_id.
 */
final class ContentProjectArchivedMonthExportService
{
    public function __construct(
        private readonly ContentProjectArchivedMonthlyWorkloadService $workload,
        private readonly ContentProjectArchivedMonthExportAssembler $assembler,
        private readonly ArticleSocialLinkService $socialLinks,
        private readonly ContentProjectExcelTemplateSettingsService $templateSettings,
        private readonly ContentProjectArchivedMonthTemplateExportService $templateExport,
        private readonly ContentProjectArchiveSocialExportRowExpander $socialRowExpander = new ContentProjectArchiveSocialExportRowExpander(),
    ) {}

    /**
     * Display labels for writer-sheet columns (registry order). Prefer system codes for mapping.
     *
     * @return list<string>
     */
    public function userSheetHeaders(): array
    {
        return array_map(
            static fn ($col): string => $col->label,
            (new ExcelDetailColumnRegistry())->writerSheetColumns(),
        );
    }

    /**
     * Default-order values for writer sheets (registry codes). Prefer code-based writers.
     *
     * @param  array<string, mixed>  $row
     * @return list<mixed>
     */
    public function writerRowValues(array $row): array
    {
        return (new ExcelDetailRowValueResolver())->defaultWriterSheetValues($row);
    }

    public function streamDownload(string $month): StreamedResponse
    {
        $payload = $this->buildPayload($month);
        $filename = $this->buildFilename($payload['month']);

        return new StreamedResponse(
            function () use ($payload): void {
                $this->writeWorkbookToOutput($payload);
            },
            200,
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
                'Cache-Control' => 'max-age=0',
            ],
        );
    }

    /**
     * @return array{
     *     month: string,
     *     month_label: string,
     *     total_articles: int,
     *     unresolved_site_id_count: int,
     *     by_domain: list<array{site_id: int, domain: string, item_count: int}>,
     *     by_writer: list<array{user_id: int, writer_name: string, item_count: int}>,
     *     writer_sheets: list<array{user_id: int, writer_name: string, sheet_name: string, rows: list<array<string, string>>}>
     * }
     */
    public function buildPayload(string $month): array
    {
        $normalized = ContentProjectMonthContext::normalize($month);
        $monthLabel = ContentProjectMonthContext::display($normalized);
        $items = $this->workload->itemRows($normalized);
        $domainsBySiteId = [];
        foreach ($items as $item) {
            $siteId = (int) ($item['site_id'] ?? 0);
            if ($siteId > 0) {
                $domainsBySiteId[$siteId] = $domainsBySiteId[$siteId] ?? '';
            }
        }
        $domainsBySiteId = $this->workload->domainLabels(array_keys($domainsBySiteId));

        $summarySheetName = (string) __('seo-content-ai::filament.projects.archive_export_sheet_summary');
        $assembled = $this->assembler->assemble(
            $normalized,
            $monthLabel,
            $items,
            $domainsBySiteId,
            $summarySheetName !== '' ? $summarySheetName : 'Summary',
        );
        $assembled = $this->appendSocialEvidenceRows($assembled);
        $sqlSummary = $this->workload->summary($normalized);

        // Summary sheet uses the same SQL aggregation as the Archived Projects UI.
        $assembled['by_domain'] = $sqlSummary['by_domain'];
        $assembled['by_writer'] = $sqlSummary['by_writer'];
        $assembled['month'] = $sqlSummary['month'] !== '' ? substr($sqlSummary['month'], 0, 7) : $normalized;
        $assembled['month_label'] = $sqlSummary['month_label'];

        return $assembled;
    }

    /**
     * @param  array<string, mixed>  $assembled
     * @return array<string, mixed>
     */
    private function appendSocialEvidenceRows(array $assembled): array
    {
        $articleIds = [];

        foreach ($assembled['writer_sheets'] ?? [] as $sheet) {
            if (! is_array($sheet)) {
                continue;
            }

            foreach ($sheet['rows'] ?? [] as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $articleId = (int) ($row['article_id'] ?? 0);
                if ($articleId > 0) {
                    $articleIds[$articleId] = $articleId;
                }
            }
        }

        if ($articleIds === []) {
            return $assembled;
        }

        $linksByArticle = $this->socialLinks->linksGroupedByArticle(array_values($articleIds));

        foreach ($assembled['writer_sheets'] as $index => $sheet) {
            if (! is_array($sheet)) {
                continue;
            }

            $rows = is_array($sheet['rows'] ?? null) ? $sheet['rows'] : [];
            $assembled['writer_sheets'][$index]['rows'] = $this->socialRowExpander->expandWriterSheetRows(
                $rows,
                $linksByArticle,
            );
        }

        return $assembled;
    }

    public function buildFilename(string $month): string
    {
        $normalized = ContentProjectMonthContext::normalize($month);

        return 'Archived-'.$normalized.'.xlsx';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function writeToFile(array $payload, string $path): void
    {
        $this->writeWorkbook($payload, $path);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeWorkbookToOutput(array $payload): void
    {
        $this->writeWorkbook($payload, 'php://output');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeWorkbook(array $payload, string $path): void
    {
        $layoutMode = $this->templateSettings->dataLayoutMode();

        if ($this->templateExport->canExportWithTemplate()) {
            $this->templateExport->writeWorkbook($payload, $path, $layoutMode);

            return;
        }

        RuntimeLogger::info('content_project_archived_month_exported', [
            'month' => $payload['month'] ?? '',
            'total_articles' => (int) ($payload['total_articles'] ?? 0),
            'writer_sheets' => count($payload['writer_sheets'] ?? []),
            'unresolved_site_id_count' => (int) ($payload['unresolved_site_id_count'] ?? 0),
            'data_layout_mode' => $layoutMode->value,
            'user_id' => auth()->id(),
        ]);

        $options = new Options();
        $this->tryApplyOptionsFreeze($options);

        $writer = new Writer($options);
        $writer->openToFile($path);

        $headerStyle = (new Style())->setFontBold();
        $stats = (new ArchivedMonthExcelStatsBuilder($this->workload))->build($payload, true);
        $this->writeStatsSheet($writer, $stats, $headerStyle);

        if ($layoutMode === ExcelDataLayoutMode::SingleDataSheet) {
            $this->writeCombinedDataSheet($writer, $payload, $headerStyle);
        } else {
            foreach ($payload['writer_sheets'] ?? [] as $sheet) {
                if (! is_array($sheet)) {
                    continue;
                }
                $this->writeWriterSheet($writer, $sheet, $headerStyle);
            }
        }

        $writer->close();
    }

    private function writeStatsSheet(Writer $writer, ArchivedMonthExcelStatsDocument $stats, Style $headerStyle): void
    {
        $sheet = $writer->getCurrentSheet();
        $sheet->setName(ArchivedMonthExcelStatsDocument::SHEET_NAME);
        $this->applySheetFreeze($sheet, 2);

        $columnSizer = new ExcelSheetColumnAutoSizer();
        foreach ($stats->toSheetRows() as $row) {
            $values = [];
            foreach ($row as $cell) {
                $values[] = is_scalar($cell) || $cell === null ? $cell : (string) $cell;
            }
            /** @var list<mixed> $values */
            $isHeader = isset($values[0]) && is_string($values[0]) && (
                str_starts_with($values[0], '[') || $values[0] === 'metric' || $values[0] === 'writer' || $values[0] === 'domain'
            );
            $writer->addRow(Row::fromValues(
                ExcelFormulaEscaper::escapeRow($values),
                $isHeader ? $headerStyle : null,
            ));
            $columnSizer->trackRow(array_map(static fn ($v) => (string) ($v ?? ''), $values));
        }
        $columnSizer->apply($sheet);
    }

    /**
     * @deprecated kept for contract tests referencing summary — redirected conceptually to STATS
     * @param  array<string, mixed>  $payload
     */
    private function writeSummarySheet(Writer $writer, array $payload, Style $headerStyle, Style $titleStyle): void
    {
        $stats = (new ArchivedMonthExcelStatsBuilder($this->workload))->build($payload, false);
        $this->writeStatsSheet($writer, $stats, $headerStyle);
    }

    /**
     * @param  array{sheet_name?: string, rows?: list<array<string, string>>}  $sheetPayload
     */
    private function writeWriterSheet(Writer $writer, array $sheetPayload, Style $headerStyle): void
    {
        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $name = trim((string) ($sheetPayload['sheet_name'] ?? 'Sheet'));
        if ($name === '') {
            $name = 'Sheet';
        }
        $sheet->setName($name);
        $this->applySheetFreeze($sheet, ExcelDetailColumnRegistry::DATA_START_ROW);

        $columns = (new ExcelDetailColumnRegistry())->writerSheetColumns();
        $this->writeDualHeaderRows($writer, $columns, $headerStyle);

        $columnSizer = new ExcelSheetColumnAutoSizer();
        $columnSizer->trackRow(array_map(static fn ($c) => $c->label, $columns));
        $columnSizer->trackRow(array_map(static fn ($c) => $c->code, $columns));

        $resolver = new ExcelDetailRowValueResolver();
        foreach ($sheetPayload['rows'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $values = [];
            foreach ($columns as $column) {
                $values[] = $resolver->resolve($row, $column->code);
            }

            $writer->addRow(Row::fromValues(
                ExcelHyperlinkHelper::escapeRowPreservingFormulas($values),
            ));
            $columnSizer->trackRow($values);
        }

        $columnSizer->apply($sheet);
    }

    /**
     * SINGLE_DATA_SHEET: same writer rows, flattened with writer_name as first column.
     *
     * @param  array<string, mixed>  $payload
     */
    private function writeCombinedDataSheet(Writer $writer, array $payload, Style $headerStyle): void
    {
        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName('DATA');
        $this->applySheetFreeze($sheet, ExcelDetailColumnRegistry::DATA_START_ROW);

        $columns = (new ExcelDetailColumnRegistry())->dataSheetColumns();
        $this->writeDualHeaderRows($writer, $columns, $headerStyle);

        $columnSizer = new ExcelSheetColumnAutoSizer();
        $columnSizer->trackRow(array_map(static fn ($c) => $c->label, $columns));
        $columnSizer->trackRow(array_map(static fn ($c) => $c->code, $columns));

        $resolver = new ExcelDetailRowValueResolver();
        foreach ($payload['writer_sheets'] ?? [] as $sheetPayload) {
            if (! is_array($sheetPayload)) {
                continue;
            }
            $writerName = (string) ($sheetPayload['writer_name'] ?? '');
            foreach ($sheetPayload['rows'] ?? [] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $values = [];
                foreach ($columns as $column) {
                    $values[] = $resolver->resolve($row, $column->code, $writerName);
                }
                $writer->addRow(Row::fromValues(
                    ExcelHyperlinkHelper::escapeRowPreservingFormulas($values),
                ));
                $columnSizer->trackRow($values);
            }
        }

        $columnSizer->apply($sheet);
    }

    /**
     * @param  list<\Omnichannel\Addons\ContentProjects\Support\ContentProject\ExcelTemplate\ExcelDetailColumnDefinition>  $columns
     */
    private function writeDualHeaderRows(Writer $writer, array $columns, Style $headerStyle): void
    {
        $labels = array_map(static fn ($c) => $c->label, $columns);
        $codes = array_map(static fn ($c) => $c->code, $columns);
        $codeStyle = (new Style())->setFontSize(9)->setFontItalic();

        $writer->addRow(Row::fromValues(
            ExcelFormulaEscaper::escapeRow($labels),
            $headerStyle,
        ));
        $writer->addRow(Row::fromValues(
            ExcelFormulaEscaper::escapeRow($codes),
            $codeStyle,
        ));
    }

    private function tryApplyOptionsFreeze(Options $options): void
    {
        try {
            if (defined(Options::class.'::FREEZE_ROWS')) {
                $constant = constant(Options::class.'::FREEZE_ROWS');
                if (is_string($constant) && property_exists($options, $constant)) {
                    $options->{$constant} = 1;
                }
            }

            if (method_exists($options, 'setFreezeRow')) {
                $options->setFreezeRow(1);
            }
        } catch (\Throwable) {
            // skip freeze silently
        }
    }

    private function applySheetFreeze(mixed $sheet, int $freezeRow): void
    {
        if (! is_object($sheet) || ! method_exists($sheet, 'setSheetView')) {
            return;
        }

        if (! class_exists(SheetView::class)) {
            return;
        }

        try {
            $sheetView = new SheetView();
            if (method_exists($sheetView, 'setFreezeRow')) {
                $sheetView->setFreezeRow($freezeRow);
            }

            $sheet->setSheetView($sheetView);
        } catch (\Throwable) {
            // skip freeze silently
        }
    }
}
