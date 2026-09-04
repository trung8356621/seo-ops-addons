<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject\ExcelTemplate;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

/**
 * Scans the system-code header row and builds code => column mapping.
 */
final class ExcelDetailColumnScanner
{
    public function __construct(
        private readonly ExcelDetailColumnRegistry $registry = new ExcelDetailColumnRegistry(),
    ) {}

    /**
     * @throws RuntimeException on duplicate known system codes
     */
    public function scan(Worksheet $sheet, int $codeRow = ExcelDetailColumnRegistry::SYSTEM_CODE_ROW): ExcelDetailColumnMap
    {
        $highestCol = Coordinate::columnIndexFromString($sheet->getHighestDataColumn($codeRow));
        /** @var array<string, int> $map */
        $map = [];

        for ($col = 1; $col <= $highestCol; $col++) {
            $raw = $sheet->getCell([$col, $codeRow])->getValue();
            if (! is_string($raw) && ! is_numeric($raw)) {
                continue;
            }
            $code = strtolower(trim((string) $raw));
            if ($code === '' || ! $this->registry->isKnown($code)) {
                continue;
            }
            if (isset($map[$code])) {
                throw new RuntimeException("Column code '{$code}' appears more than once.");
            }
            $map[$code] = $col;
        }

        return new ExcelDetailColumnMap($map);
    }

    /**
     * @param  list<string>  $requiredCodes
     * @throws RuntimeException
     */
    public function assertRequired(ExcelDetailColumnMap $map, array $requiredCodes): void
    {
        $missing = [];
        foreach ($requiredCodes as $code) {
            if (! $map->has($code)) {
                $missing[] = $code;
            }
        }
        if ($missing !== []) {
            throw new RuntimeException(
                'Missing required column code(s): '.implode(', ', $missing),
            );
        }
    }
}
