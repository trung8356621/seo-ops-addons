<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject\ExcelTemplate;

use Omnichannel\Addons\ContentProjects\Support\ContentProject\ExcelSheetNameSanitizer;
use Omnichannel\Addons\Seo\Support\ExcelFormulaEscaper;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Production managed-sheet writer (STATS + writers|DATA) and template fill helpers.
 * Detail columns are resolved by system code row (row 2), not by position or display labels.
 */
final class ExcelTemplateManagedSheetWriter
{
    public const WRITER_TEMPLATE_SHEET = ExcelRawTemplateGenerator::WRITER_TEMPLATE_SHEET;

    public function __construct(
        private readonly ExcelDetailColumnRegistry $columnRegistry = new ExcelDetailColumnRegistry(),
        private readonly ExcelDetailColumnScanner $columnScanner = new ExcelDetailColumnScanner(),
        private readonly ExcelDetailRowValueResolver $valueResolver = new ExcelDetailRowValueResolver(),
        private readonly ExcelDetailHeaderWriter $headerWriter = new ExcelDetailHeaderWriter(),
    ) {}

    /**
     * Fill an uploaded/raw template workbook with live production data.
     *
     * @param  array<string, mixed>  $payload
     */
    public function fillProductionWorkbook(
        Spreadsheet $spreadsheet,
        array $payload,
        ExcelDataLayoutMode $layoutMode,
        ArchivedMonthExcelStatsDocument $stats,
        ExcelScalarVariableRegistry $scalars,
        ExcelTableVariableRegistry $tables,
    ): void {
        $this->removeSheetByName($spreadsheet, ExcelTemplateHelpSheetBuilder::SHEET_NAME);

        $context = (new ArchivedMonthExcelTemplateVariableFactory())->buildContext($payload, $stats);
        (new ExcelTemplateVariableApplicator($scalars, $tables))->applyToPrefixSheets(
            $spreadsheet,
            count($spreadsheet->getAllSheets()),
            $context,
        );

        $statsSheet = $this->findSheet($spreadsheet, ArchivedMonthExcelStatsDocument::SHEET_NAME);
        if ($statsSheet === null) {
            $statsSheet = $spreadsheet->createSheet();
            $used = $this->usedTitles($spreadsheet);
            $statsSheet->setTitle(ExcelSheetNameSanitizer::unique(ArchivedMonthExcelStatsDocument::SHEET_NAME, $used));
        } else {
            $this->clearSheetCells($statsSheet);
        }
        $this->writeGrid($statsSheet, $stats->toSheetRows());

        /** @var array<string, true> $usedLower */
        $usedLower = $this->usedTitles($spreadsheet);

        if ($layoutMode === ExcelDataLayoutMode::SingleDataSheet) {
            $this->removeSheetByName($spreadsheet, self::WRITER_TEMPLATE_SHEET);
            $dataSheet = $this->findSheet($spreadsheet, ExcelRawTemplateGenerator::DATA_SHEET);
            if ($dataSheet === null) {
                $dataSheet = $spreadsheet->createSheet();
                $dataSheet->setTitle(ExcelSheetNameSanitizer::unique(ExcelRawTemplateGenerator::DATA_SHEET, $usedLower));
                $this->headerWriter->write($dataSheet, $this->columnRegistry->dataSheetColumns());
            }
            $map = $this->ensureColumnMap($dataSheet, $this->columnRegistry->dataSheetColumns());
            $this->clearManagedDataRows($dataSheet, $map);
            $this->writeCombinedDataRows($dataSheet, $payload, $map);

            return;
        }

        $template = $this->findSheet($spreadsheet, self::WRITER_TEMPLATE_SHEET);
        if ($template === null) {
            foreach ($payload['writer_sheets'] ?? [] as $sheetPayload) {
                if (! is_array($sheetPayload)) {
                    continue;
                }
                $name = trim((string) ($sheetPayload['sheet_name'] ?? $sheetPayload['writer_name'] ?? 'Sheet'));
                if ($name === '') {
                    $name = 'Sheet';
                }
                $title = ExcelSheetNameSanitizer::unique($name, $usedLower);
                $sheet = $spreadsheet->createSheet();
                $sheet->setTitle($title);
                $this->writeWriterSheet($sheet, $sheetPayload);
            }

            return;
        }

        $templateMap = $this->ensureColumnMap($template, $this->columnRegistry->writerSheetColumns());
        $templateIndex = (int) $spreadsheet->getIndex($template);
        foreach ($payload['writer_sheets'] ?? [] as $sheetPayload) {
            if (! is_array($sheetPayload)) {
                continue;
            }
            $name = trim((string) ($sheetPayload['sheet_name'] ?? $sheetPayload['writer_name'] ?? 'Sheet'));
            if ($name === '') {
                $name = 'Sheet';
            }
            $title = ExcelSheetNameSanitizer::unique($name, $usedLower);
            $clone = clone $template;
            $clone->setTitle($title);
            $spreadsheet->addSheet($clone, ++$templateIndex);
            $this->clearManagedDataRows($clone, $templateMap);
            $this->writeWriterDataRows($clone, $sheetPayload, $templateMap);
        }

        $this->removeSheetByName($spreadsheet, self::WRITER_TEMPLATE_SHEET);
    }

    /**
     * Production export without custom template: create STATS + writers|DATA from scratch.
     *
     * @param  array<string, mixed>  $payload
     */
    public function buildProductionWorkbook(
        array $payload,
        ExcelDataLayoutMode $layoutMode,
        ArchivedMonthExcelStatsDocument $stats,
    ): Spreadsheet {
        $spreadsheet = new Spreadsheet();
        $statsSheet = $spreadsheet->getActiveSheet();
        $statsSheet->setTitle(ArchivedMonthExcelStatsDocument::SHEET_NAME);
        $this->writeGrid($statsSheet, $stats->toSheetRows());

        /** @var array<string, true> $usedLower */
        $usedLower = [mb_strtolower(ArchivedMonthExcelStatsDocument::SHEET_NAME) => true];

        if ($layoutMode === ExcelDataLayoutMode::SingleDataSheet) {
            $dataSheet = $spreadsheet->createSheet();
            $dataSheet->setTitle(ExcelSheetNameSanitizer::unique(ExcelRawTemplateGenerator::DATA_SHEET, $usedLower));
            $this->writeCombinedDataSheet($dataSheet, $payload);

            return $spreadsheet;
        }

        foreach ($payload['writer_sheets'] ?? [] as $sheetPayload) {
            if (! is_array($sheetPayload)) {
                continue;
            }
            $name = trim((string) ($sheetPayload['sheet_name'] ?? $sheetPayload['writer_name'] ?? 'Sheet'));
            if ($name === '') {
                $name = 'Sheet';
            }
            $title = ExcelSheetNameSanitizer::unique($name, $usedLower);
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($title);
            $this->writeWriterSheet($sheet, $sheetPayload);
        }

        return $spreadsheet;
    }

    /**
     * @param  list<list<scalar|null>>  $grid
     */
    public function writeGrid(Worksheet $sheet, array $grid): void
    {
        $rowNum = 1;
        foreach ($grid as $row) {
            if (! is_array($row)) {
                $rowNum++;

                continue;
            }
            foreach (array_values($row) as $c => $value) {
                $sheet->setCellValue([1 + $c, $rowNum], $value);
            }
            $rowNum++;
        }
    }

    /**
     * @param  array{rows?: list<array<string, mixed>>}  $sheetPayload
     */
    private function writeWriterSheet(Worksheet $sheet, array $sheetPayload): void
    {
        $this->headerWriter->write($sheet, $this->columnRegistry->writerSheetColumns());
        $map = $this->columnScanner->scan($sheet);
        $this->writeWriterDataRows($sheet, $sheetPayload, $map);
    }

    /**
     * @param  array{rows?: list<array<string, mixed>>}  $sheetPayload
     */
    private function writeWriterDataRows(Worksheet $sheet, array $sheetPayload, ExcelDetailColumnMap $map): void
    {
        $excelRow = ExcelDetailColumnRegistry::DATA_START_ROW;
        foreach ($sheetPayload['rows'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $this->writeMappedDataRow($sheet, $excelRow, $map, $row, null);
            $excelRow++;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeCombinedDataSheet(Worksheet $sheet, array $payload): void
    {
        $this->headerWriter->write($sheet, $this->columnRegistry->dataSheetColumns());
        $map = $this->columnScanner->scan($sheet);
        $this->writeCombinedDataRows($sheet, $payload, $map);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeCombinedDataRows(Worksheet $sheet, array $payload, ExcelDetailColumnMap $map): void
    {
        $excelRow = ExcelDetailColumnRegistry::DATA_START_ROW;
        foreach ($payload['writer_sheets'] ?? [] as $sheetPayload) {
            if (! is_array($sheetPayload)) {
                continue;
            }
            $writerName = (string) ($sheetPayload['writer_name'] ?? '');
            foreach ($sheetPayload['rows'] ?? [] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $this->writeMappedDataRow($sheet, $excelRow, $map, $row, $writerName);
                $excelRow++;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function writeMappedDataRow(
        Worksheet $sheet,
        int $excelRow,
        ExcelDetailColumnMap $map,
        array $row,
        ?string $writerName,
    ): void {
        foreach ($map->codes() as $code) {
            $col = $map->column($code);
            if ($col === null) {
                continue;
            }
            $value = $this->valueResolver->resolve($row, $code, $writerName);
            if ($value === null) {
                continue;
            }
            if (is_string($value) && str_starts_with($value, '=')) {
                $sheet->setCellValueExplicit([$col, $excelRow], $value, DataType::TYPE_FORMULA);

                continue;
            }
            $sheet->setCellValue([$col, $excelRow], ExcelFormulaEscaper::escape($value));
        }
    }

    /**
     * @param  list<ExcelDetailColumnDefinition>  $defaultColumns
     */
    private function ensureColumnMap(Worksheet $sheet, array $defaultColumns): ExcelDetailColumnMap
    {
        $map = $this->columnScanner->scan($sheet);
        if ($map->codes() !== []) {
            $this->columnScanner->assertRequired($map, $this->columnRegistry->requiredImportCodes());

            return $map;
        }

        // Legacy single-header templates: rewrite dual header from registry defaults.
        $this->headerWriter->write($sheet, $defaultColumns);

        return $this->columnScanner->scan($sheet);
    }

    private function clearManagedDataRows(Worksheet $sheet, ExcelDetailColumnMap $map): void
    {
        $highestRow = (int) $sheet->getHighestDataRow();
        $start = ExcelDetailColumnRegistry::DATA_START_ROW;
        for ($row = $start; $row <= max($start, $highestRow); $row++) {
            foreach ($map->codes() as $code) {
                $col = $map->column($code);
                if ($col === null) {
                    continue;
                }
                $sheet->setCellValue([$col, $row], null);
            }
        }
    }

    private function clearSheetCells(Worksheet $sheet): void
    {
        $highestRow = (int) $sheet->getHighestDataRow();
        $highestCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        for ($row = 1; $row <= max(1, $highestRow); $row++) {
            for ($col = 1; $col <= $highestCol; $col++) {
                $sheet->setCellValue([$col, $row], null);
            }
        }
    }

    private function findSheet(Spreadsheet $spreadsheet, string $name): ?Worksheet
    {
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            if (strcasecmp($sheet->getTitle(), $name) === 0) {
                return $sheet;
            }
        }

        return null;
    }

    private function removeSheetByName(Spreadsheet $spreadsheet, string $name): void
    {
        foreach ($spreadsheet->getAllSheets() as $index => $sheet) {
            if (strcasecmp($sheet->getTitle(), $name) === 0) {
                $spreadsheet->removeSheetByIndex((int) $index);

                return;
            }
        }
    }

    /**
     * @return array<string, true>
     */
    private function usedTitles(Spreadsheet $spreadsheet): array
    {
        $used = [];
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $used[mb_strtolower($sheet->getTitle())] = true;
        }

        return $used;
    }

}
