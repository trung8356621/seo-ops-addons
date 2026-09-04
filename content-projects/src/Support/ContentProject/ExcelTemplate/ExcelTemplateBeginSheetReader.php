<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject\ExcelTemplate;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Reads BEGIN_SHEET from the workbook (defined name, custom prop, or key/value cell).
 */
final class ExcelTemplateBeginSheetReader
{
    public const KEY = 'BEGIN_SHEET';

    public function read(Spreadsheet $spreadsheet): int
    {
        $fromName = $this->fromDefinedName($spreadsheet);
        if ($fromName !== null) {
            return $fromName;
        }

        $fromProp = $this->fromCustomProperty($spreadsheet);
        if ($fromProp !== null) {
            return $fromProp;
        }

        $fromCell = $this->fromKeyValueCell($spreadsheet);
        if ($fromCell !== null) {
            return $fromCell;
        }

        return 0;
    }

    private function fromDefinedName(Spreadsheet $spreadsheet): ?int
    {
        try {
            $named = $spreadsheet->getNamedRange(self::KEY);
            if ($named === null) {
                return null;
            }
            $range = $named->getRange();
            $worksheet = $named->getWorksheet() ?? $spreadsheet->getActiveSheet();
            $value = $worksheet->getCell($range)->getCalculatedValue();

            return $this->normalizeInt($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function fromCustomProperty(Spreadsheet $spreadsheet): ?int
    {
        try {
            $props = $spreadsheet->getProperties();
            if (! method_exists($props, 'isCustomPropertySet') || ! $props->isCustomPropertySet(self::KEY)) {
                return null;
            }
            if (! method_exists($props, 'getCustomPropertyValue')) {
                return null;
            }

            return $this->normalizeInt($props->getCustomPropertyValue(self::KEY));
        } catch (\Throwable) {
            return null;
        }
    }

    private function fromKeyValueCell(Spreadsheet $spreadsheet): ?int
    {
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            if (! $sheet instanceof Worksheet) {
                continue;
            }
            $found = $this->scanSheetForKey($sheet);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private function scanSheetForKey(Worksheet $sheet): ?int
    {
        $highestRow = min((int) $sheet->getHighestDataRow(), 50);
        $highestCol = $sheet->getHighestDataColumn();
        $highestColIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestCol);
        $highestColIndex = min($highestColIndex, 20);

        for ($row = 1; $row <= $highestRow; $row++) {
            for ($col = 1; $col <= $highestColIndex; $col++) {
                $value = $sheet->getCell([$col, $row])->getValue();
                if (! is_string($value) && ! is_numeric($value)) {
                    continue;
                }
                if (strtoupper(trim((string) $value)) !== self::KEY) {
                    continue;
                }

                $right = $this->normalizeInt($sheet->getCell([$col + 1, $row])->getValue());
                if ($right !== null) {
                    return $right;
                }

                $below = $this->normalizeInt($sheet->getCell([$col, $row + 1])->getValue());
                if ($below !== null) {
                    return $below;
                }
            }
        }

        return null;
    }

    private function normalizeInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return max(0, $value);
        }
        if (is_float($value)) {
            return max(0, (int) $value);
        }
        if (is_string($value) && is_numeric(trim($value))) {
            return max(0, (int) trim($value));
        }

        return null;
    }
}
