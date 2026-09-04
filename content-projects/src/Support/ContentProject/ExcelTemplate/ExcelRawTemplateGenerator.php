<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject\ExcelTemplate;

use Omnichannel\Addons\ContentProjects\Support\ContentProject\ExcelSheetNameSanitizer;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Builds RAW (uploadable) Excel templates — structure + placeholders only.
 * Never queries or embeds live article/report rows.
 */
final class ExcelRawTemplateGenerator
{
    public const WRITER_TEMPLATE_SHEET = '_WRITER_TEMPLATE';

    public const DATA_SHEET = 'DATA';

    public function __construct(
        private readonly ArchivedMonthExcelTemplateVariableFactory $variableFactory = new ArchivedMonthExcelTemplateVariableFactory(),
        private readonly ExcelDetailColumnRegistry $columnRegistry = new ExcelDetailColumnRegistry(),
        private readonly ExcelDetailHeaderWriter $headerWriter = new ExcelDetailHeaderWriter(),
    ) {}

    public function build(ExcelDataLayoutMode $layoutMode): Spreadsheet
    {
        $scalars = $this->variableFactory->buildScalarRegistry();
        $tables = $this->variableFactory->buildTableRegistry();

        $spreadsheet = new Spreadsheet();
        $help = $spreadsheet->getActiveSheet();
        $help->setTitle(ExcelTemplateHelpSheetBuilder::SHEET_NAME);
        $this->writeGrid($help, (new ExcelTemplateHelpSheetBuilder())->rows($scalars, $tables));

        /** @var array<string, true> $usedLower */
        $usedLower = [mb_strtolower(ExcelTemplateHelpSheetBuilder::SHEET_NAME) => true];

        $statsSheet = $spreadsheet->createSheet();
        $statsTitle = ExcelSheetNameSanitizer::unique(ArchivedMonthExcelStatsDocument::SHEET_NAME, $usedLower);
        $statsSheet->setTitle($statsTitle);
        $this->writeGrid($statsSheet, ExcelRawTemplateStatsSchema::rows());

        if ($layoutMode === ExcelDataLayoutMode::SingleDataSheet) {
            $dataSheet = $spreadsheet->createSheet();
            $dataTitle = ExcelSheetNameSanitizer::unique(self::DATA_SHEET, $usedLower);
            $dataSheet->setTitle($dataTitle);
            $this->headerWriter->write($dataSheet, $this->columnRegistry->dataSheetColumns());

            return $spreadsheet;
        }

        $writerTpl = $spreadsheet->createSheet();
        $tplTitle = ExcelSheetNameSanitizer::unique(self::WRITER_TEMPLATE_SHEET, $usedLower);
        $writerTpl->setTitle($tplTitle);
        $this->headerWriter->write($writerTpl, $this->columnRegistry->writerSheetColumns());

        return $spreadsheet;
    }

    /**
     * @param  list<list<scalar|null>>  $grid
     */
    private function writeGrid(Worksheet $sheet, array $grid): void
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
}
