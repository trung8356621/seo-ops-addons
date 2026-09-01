<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectArchivedMonthExportAssembler;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectArchiveSocialExportRowExpander;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ExcelHyperlinkHelper;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ExcelSheetColumnAutoSizer;
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
 * Month workbook: Summary + one sheet per writer.
 * Detail Domain always from item.site_id.
 */
final class ContentProjectArchivedMonthExportService
{
    public function __construct(
        private readonly ContentProjectArchivedMonthlyWorkloadService $workload,
        private readonly ContentProjectArchivedMonthExportAssembler $assembler,
        private readonly ArticleSocialLinkService $socialLinks,
        private readonly ContentProjectArchiveSocialExportRowExpander $socialRowExpander = new ContentProjectArchiveSocialExportRowExpander(),
    ) {}

    /**
     * @return list<string>
     */
    private function userSheetHeaders(): array
    {
        return [
            (string) __('seo-content-ai::filament.projects.archive_export_col_project'),
            (string) __('seo-content-ai::filament.projects.archive_export_col_domain'),
            (string) __('seo-content-ai::filament.projects.archive_export_col_article'),
            (string) __('seo-content-ai::filament.projects.archive_export_col_keyword'),
            (string) __('seo-content-ai::filament.projects.archive_export_col_post_type'),
            (string) __('seo-content-ai::filament.projects.archive_export_col_plan'),
            (string) __('seo-content-ai::filament.projects.archive_export_col_index'),
            (string) __('seo-content-ai::filament.projects.archive_export_col_archived_at'),
            (string) __('seo-content-ai::filament.projects.archive_export_col_archived_by'),
        ];
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
        RuntimeLogger::info('content_project_archived_month_exported', [
            'month' => $payload['month'] ?? '',
            'total_articles' => (int) ($payload['total_articles'] ?? 0),
            'writer_sheets' => count($payload['writer_sheets'] ?? []),
            'unresolved_site_id_count' => (int) ($payload['unresolved_site_id_count'] ?? 0),
            'user_id' => auth()->id(),
        ]);

        $options = new Options();
        $this->tryApplyOptionsFreeze($options);

        $writer = new Writer($options);
        $writer->openToFile($path);

        $headerStyle = (new Style())->setFontBold();
        $titleStyle = (new Style())->setFontBold();

        $this->writeSummarySheet($writer, $payload, $headerStyle, $titleStyle);

        foreach ($payload['writer_sheets'] ?? [] as $sheet) {
            if (! is_array($sheet)) {
                continue;
            }
            $this->writeWriterSheet($writer, $sheet, $headerStyle);
        }

        $writer->close();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeSummarySheet(Writer $writer, array $payload, Style $headerStyle, Style $titleStyle): void
    {
        $sheet = $writer->getCurrentSheet();
        $summaryName = (string) __('seo-content-ai::filament.projects.archive_export_sheet_summary');
        $sheet->setName($summaryName !== '' ? $summaryName : 'Summary');
        $this->applySheetFreeze($sheet, 2);

        $columnSizer = new ExcelSheetColumnAutoSizer();
        $monthLabel = (string) ($payload['month_label'] ?? '');
        $total = (int) ($payload['total_articles'] ?? 0);
        $unresolved = (int) ($payload['unresolved_site_id_count'] ?? 0);

        $summaryRows = [
            [
                (string) __('seo-content-ai::filament.projects.archive_export_summary_month'),
                $monthLabel,
            ],
            [
                (string) __('seo-content-ai::filament.projects.archive_export_summary_total'),
                (string) $total,
            ],
        ];
        if ($unresolved > 0) {
            $summaryRows[] = [
                (string) __('seo-content-ai::filament.projects.archive_export_summary_unresolved'),
                (string) $unresolved,
            ];
        }

        foreach ($summaryRows as $summaryRow) {
            $writer->addRow(Row::fromValues(
                ExcelFormulaEscaper::escapeRow($summaryRow),
                $summaryRow === $summaryRows[0] ? $titleStyle : null,
            ));
            $columnSizer->trackRow($summaryRow);
        }

        $writer->addRow(Row::fromValues(ExcelFormulaEscaper::escapeRow(['', ''])));
        $columnSizer->trackRow(['', '']);

        $writer->addRow(Row::fromValues(
            ExcelFormulaEscaper::escapeRow([(string) __('seo-content-ai::filament.projects.chart_articles_by_domain')]),
            $titleStyle,
        ));
        $columnSizer->trackRow([(string) __('seo-content-ai::filament.projects.chart_articles_by_domain')]);

        $domainHeader = [
            (string) __('seo-content-ai::filament.projects.archive_export_col_domain'),
            (string) __('seo-content-ai::filament.projects.archive_export_summary_articles'),
        ];
        $writer->addRow(Row::fromValues(
            ExcelFormulaEscaper::escapeRow($domainHeader),
            $headerStyle,
        ));
        $columnSizer->trackRow($domainHeader);

        foreach ($payload['by_domain'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $dataRow = [
                (string) ($row['domain'] ?? ''),
                (string) (int) ($row['item_count'] ?? 0),
            ];
            $writer->addRow(Row::fromValues(ExcelFormulaEscaper::escapeRow($dataRow)));
            $columnSizer->trackRow($dataRow);
        }

        $writer->addRow(Row::fromValues(ExcelFormulaEscaper::escapeRow(['', ''])));
        $columnSizer->trackRow(['', '']);

        $writer->addRow(Row::fromValues(
            ExcelFormulaEscaper::escapeRow([(string) __('seo-content-ai::filament.projects.chart_articles_by_writer')]),
            $titleStyle,
        ));
        $columnSizer->trackRow([(string) __('seo-content-ai::filament.projects.chart_articles_by_writer')]);

        $writerHeader = [
            (string) __('seo-content-ai::filament.projects.archive_export_summary_writer'),
            (string) __('seo-content-ai::filament.projects.archive_export_summary_articles'),
        ];
        $writer->addRow(Row::fromValues(
            ExcelFormulaEscaper::escapeRow($writerHeader),
            $headerStyle,
        ));
        $columnSizer->trackRow($writerHeader);

        foreach ($payload['by_writer'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $dataRow = [
                (string) ($row['writer_name'] ?? ''),
                (string) (int) ($row['item_count'] ?? 0),
            ];
            $writer->addRow(Row::fromValues(ExcelFormulaEscaper::escapeRow($dataRow)));
            $columnSizer->trackRow($dataRow);
        }

        $columnSizer->apply($sheet);
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
        $this->applySheetFreeze($sheet, 2);

        $headers = $this->userSheetHeaders();
        $writer->addRow(Row::fromValues(
            ExcelFormulaEscaper::escapeRow($headers),
            $headerStyle,
        ));

        $columnSizer = new ExcelSheetColumnAutoSizer();
        $columnSizer->trackRow($headers);

        foreach ($sheetPayload['rows'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $title = (string) ($row['title'] ?? '');
            $hyperlinkUrl = trim((string) ($row['hyperlink_url'] ?? ''));
            $wordpressUrl = trim((string) ($row['wordpress_url'] ?? ''));
            $linkUrl = $hyperlinkUrl !== '' ? $hyperlinkUrl : $wordpressUrl;
            $titleCell = $linkUrl !== '' && $title !== ''
                ? ExcelHyperlinkHelper::formula($linkUrl, $title)
                : $title;

            $values = [
                (string) ($row['project'] ?? ''),
                (string) ($row['domain'] ?? ''),
                $titleCell,
                (string) ($row['keyword'] ?? ''),
                (string) ($row['post_type'] ?? ''),
                (string) ($row['plan'] ?? ''),
                (string) ($row['index_status'] ?? ''),
                (string) ($row['archived_at'] ?? ''),
                (string) ($row['archived_by'] ?? ''),
            ];

            $writer->addRow(Row::fromValues(
                ExcelHyperlinkHelper::escapeRowPreservingFormulas($values),
            ));
            $columnSizer->trackRow($values);
        }

        $columnSizer->apply($sheet);
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
