<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject\ExcelTemplate;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectExcelTemplateSettingsService;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\NamedRange;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Tracks previous table-block extents (workbook named ranges + settings fallback)
 * so stale cells can be cleared without touching unrelated user cells.
 */
final class ExcelTemplateBlockExtentStore
{
    private const PREFIX = '_OMI_BLK_';

    public function __construct(
        private readonly ?ContentProjectExcelTemplateSettingsService $settings = null,
    ) {}

    public function clearPrevious(Spreadsheet $spreadsheet, Worksheet $sheet, string $tableKey): void
    {
        $cleared = false;
        $name = $this->definedNameFor($tableKey);
        try {
            $named = $spreadsheet->getNamedRange($name);
        } catch (\Throwable) {
            $named = null;
        }

        if ($named !== null) {
            $range = (string) $named->getRange();
            $targetSheet = $named->getWorksheet();
            if ($targetSheet instanceof Worksheet) {
                $this->clearRange($targetSheet, $range);
            } else {
                $this->clearRange($sheet, $range);
            }
            try {
                $spreadsheet->removeNamedRange($name);
            } catch (\Throwable) {
                // ignore
            }
            $cleared = true;
        }

        if (! $cleared && $this->settings !== null) {
            $stored = $this->settings->findBlockExtent($sheet->getTitle(), $tableKey);
            if ($stored !== null) {
                $this->clearRange($sheet, $stored);
                $this->settings->forgetBlockExtent($sheet->getTitle(), $tableKey);
            }
        }
    }

    /**
     * @param  list<list<scalar|null>>  $block
     */
    public function remember(Spreadsheet $spreadsheet, Worksheet $sheet, string $tableKey, int $startCol, int $startRow, array $block): void
    {
        $rowCount = count($block);
        $colCount = 0;
        foreach ($block as $row) {
            $colCount = max($colCount, count($row));
        }
        if ($rowCount < 1 || $colCount < 1) {
            $rowCount = 1;
            $colCount = 1;
        }

        $endCol = $startCol + $colCount - 1;
        $endRow = $startRow + $rowCount - 1;
        $a1 = Coordinate::stringFromColumnIndex($startCol).$startRow
            .':'.Coordinate::stringFromColumnIndex($endCol).$endRow;
        $absolute = '$'.Coordinate::stringFromColumnIndex($startCol).'$'.$startRow
            .':$'.Coordinate::stringFromColumnIndex($endCol).'$'.$endRow;

        $name = $this->definedNameFor($tableKey);
        try {
            $spreadsheet->removeNamedRange($name);
        } catch (\Throwable) {
            // ignore
        }

        $spreadsheet->addNamedRange(new NamedRange($name, $sheet, $absolute));

        $this->settings?->rememberBlockExtent($sheet->getTitle(), $tableKey, $a1);
    }

    private function clearRange(Worksheet $sheet, string $range): void
    {
        $range = preg_replace('/^[^!]+!/', '', $range) ?? $range;
        $range = str_replace('$', '', $range);
        if ($range === '') {
            return;
        }

        try {
            [$start, $end] = array_pad(explode(':', $range, 2), 2, null);
            if ($start === null) {
                return;
            }
            $end ??= $start;
            [$startCol, $startRow] = Coordinate::coordinateFromString($start);
            [$endCol, $endRow] = Coordinate::coordinateFromString($end);
            $startColIndex = Coordinate::columnIndexFromString($startCol);
            $endColIndex = Coordinate::columnIndexFromString($endCol);

            for ($row = (int) $startRow; $row <= (int) $endRow; $row++) {
                for ($col = $startColIndex; $col <= $endColIndex; $col++) {
                    $sheet->setCellValue([$col, $row], null);
                }
            }
        } catch (\Throwable) {
            // ignore malformed extent
        }
    }

    private function definedNameFor(string $tableKey): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_]/', '_', $tableKey) ?? 'table';
        $safe = substr($safe, 0, 200);

        return self::PREFIX.$safe;
    }
}
