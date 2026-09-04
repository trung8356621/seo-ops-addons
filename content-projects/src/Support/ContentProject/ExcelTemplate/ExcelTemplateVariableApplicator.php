<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject\ExcelTemplate;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Replaces scalar + table placeholders in prefix sheets only. Never touches formulas.
 */
final class ExcelTemplateVariableApplicator
{
    private const PLACEHOLDER_PATTERN = '/^\{\{([a-zA-Z0-9_.]+)\}\}$/';

    public function __construct(
        private readonly ExcelScalarVariableRegistry $scalars,
        private readonly ExcelTableVariableRegistry $tables,
        private readonly ExcelTemplateBlockExtentStore $extents = new ExcelTemplateBlockExtentStore(),
    ) {}

    /**
     * @param  array<string, mixed>  $context  from ArchivedMonthExcelTemplateVariableFactory::buildContext()
     */
    public function applyToPrefixSheets(Spreadsheet $spreadsheet, int $beginSheet, array $context): void
    {
        $beginSheet = max(0, $beginSheet);
        $sheets = $spreadsheet->getAllSheets();
        $limit = min($beginSheet, count($sheets));

        $scalarValues = is_array($context['scalars'] ?? null) ? $context['scalars'] : [];

        for ($i = 0; $i < $limit; $i++) {
            $sheet = $sheets[$i];
            if (! $sheet instanceof Worksheet) {
                continue;
            }
            $this->applyToSheet($spreadsheet, $sheet, $scalarValues, $context);
        }
    }

    /**
     * @param  array<string, scalar|null>  $scalarValues
     * @param  array<string, mixed>  $context
     */
    private function applyToSheet(Spreadsheet $spreadsheet, Worksheet $sheet, array $scalarValues, array $context): void
    {
        $anchors = $this->collectAnchors($sheet);
        // Apply tables first (they need the placeholder cell), then scalars.
        foreach ($anchors['tables'] as $anchor) {
            $definition = $this->tables->get($anchor['key']);
            if ($definition === null) {
                continue;
            }
            $block = $definition->dataset($context);
            $this->writeTableBlock($spreadsheet, $sheet, $anchor['col'], $anchor['row'], $definition->key, $block);
        }

        foreach ($anchors['scalars'] as $anchor) {
            if (! array_key_exists($anchor['key'], $scalarValues)) {
                continue;
            }
            $sheet->setCellValue([$anchor['col'], $anchor['row']], $scalarValues[$anchor['key']]);
        }
    }

    /**
     * @return array{
     *     scalars: list<array{key: string, col: int, row: int}>,
     *     tables: list<array{key: string, col: int, row: int}>
     * }
     */
    private function collectAnchors(Worksheet $sheet): array
    {
        $scalars = [];
        $tables = [];

        $highestRow = (int) $sheet->getHighestDataRow();
        $highestCol = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        for ($row = 1; $row <= $highestRow; $row++) {
            for ($col = 1; $col <= $highestCol; $col++) {
                $cell = $sheet->getCell([$col, $row]);
                $raw = $cell->getValue();

                if (! is_string($raw)) {
                    continue;
                }

                // Preserve formulas — never rewrite.
                if (str_starts_with($raw, '=')) {
                    continue;
                }

                $trimmed = trim($raw);
                if (preg_match(self::PLACEHOLDER_PATTERN, $trimmed, $m) !== 1) {
                    continue;
                }

                $key = $m[1];
                if ($this->tables->has($key)) {
                    $tables[] = ['key' => $key, 'col' => $col, 'row' => $row];
                } elseif ($this->scalars->has($key)) {
                    $scalars[] = ['key' => $key, 'col' => $col, 'row' => $row];
                }
            }
        }

        return ['scalars' => $scalars, 'tables' => $tables];
    }

    /**
     * @param  list<list<scalar|null>>  $block
     */
    private function writeTableBlock(
        Spreadsheet $spreadsheet,
        Worksheet $sheet,
        int $startCol,
        int $startRow,
        string $tableKey,
        array $block,
    ): void {
        $this->extents->clearPrevious($spreadsheet, $sheet, $tableKey);

        // Clear the placeholder cell before writing (also covered by extent if previous export existed).
        $sheet->setCellValue([$startCol, $startRow], null);

        foreach ($block as $r => $row) {
            if (! is_array($row)) {
                continue;
            }
            foreach (array_values($row) as $c => $value) {
                $sheet->setCellValue([$startCol + $c, $startRow + $r], $value);
            }
        }

        $this->extents->remember($spreadsheet, $sheet, $tableKey, $startCol, $startRow, $block);
    }
}
