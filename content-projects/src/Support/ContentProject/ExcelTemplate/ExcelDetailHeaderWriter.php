<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject\ExcelTemplate;

use Omnichannel\Addons\Seo\Support\ExcelFormulaEscaper;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Writes dual header rows (display label + system code) for managed detail sheets.
 */
final class ExcelDetailHeaderWriter
{
    public function __construct(
        private readonly ExcelDetailColumnRegistry $registry = new ExcelDetailColumnRegistry(),
    ) {}

    /**
     * @param  list<ExcelDetailColumnDefinition>  $columns
     */
    public function write(Worksheet $sheet, array $columns): void
    {
        foreach (array_values($columns) as $i => $column) {
            $col = 1 + $i;
            $sheet->setCellValue([$col, ExcelDetailColumnRegistry::DISPLAY_HEADER_ROW], ExcelFormulaEscaper::escape($column->label));
            $sheet->setCellValue([$col, ExcelDetailColumnRegistry::SYSTEM_CODE_ROW], $column->code);
        }

        $lastCol = max(1, count($columns));
        $codeRange = 'A'.ExcelDetailColumnRegistry::SYSTEM_CODE_ROW.':'.\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($lastCol).ExcelDetailColumnRegistry::SYSTEM_CODE_ROW;
        $style = $sheet->getStyle($codeRange);
        $style->getFont()->setSize(9)->setItalic(true)->setColor(new Color('FF6B7280'));
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF3F4F6');

        $noteCol = $lastCol + 1;
        $sheet->setCellValue(
            [$noteCol, ExcelDetailColumnRegistry::SYSTEM_CODE_ROW],
            (string) __('seo-content-ai::filament.projects.excel_tpl_code_row_note'),
        );
        $sheet->getStyle([$noteCol, ExcelDetailColumnRegistry::SYSTEM_CODE_ROW])
            ->getFont()->setSize(8)->setItalic(true)->setColor(new Color('FF9CA3AF'));
    }
}
