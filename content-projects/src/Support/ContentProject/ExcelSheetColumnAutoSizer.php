<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

/**
 * Track rendered cell widths and apply OpenSpout column widths at sheet end.
 */
final class ExcelSheetColumnAutoSizer
{
    private const MIN_WIDTH = 10.0;

    private const MAX_WIDTH = 60.0;

    /** @var array<int, float> column index (0-based) => width */
    private array $maxWidths = [];

    /**
     * @param  list<mixed>  $values
     */
    public function trackRow(array $values): void
    {
        foreach (array_values($values) as $index => $value) {
            $this->trackCell((int) $index, $value);
        }
    }

    public function trackCell(int $columnIndex, mixed $value): void
    {
        if ($columnIndex < 0) {
            return;
        }

        $display = $this->displayLength($value);
        if ($display <= 0) {
            return;
        }

        $width = min(self::MAX_WIDTH, max(self::MIN_WIDTH, $display * 1.15 + 2));
        $this->maxWidths[$columnIndex] = max($this->maxWidths[$columnIndex] ?? 0.0, $width);
    }

    public function apply(mixed $sheet): void
    {
        if (! is_object($sheet) || ! method_exists($sheet, 'setColumnWidth')) {
            return;
        }

        foreach ($this->maxWidths as $columnIndex => $width) {
            $sheet->setColumnWidth($width, ((int) $columnIndex) + 1);
        }
    }

    private function displayLength(mixed $value): int
    {
        if ($value === null) {
            return 0;
        }

        if (is_string($value) && str_starts_with($value, '=HYPERLINK(')) {
            if (preg_match('/=HYPERLINK\("[^"]*","([^"]*)"\)/u', $value, $matches) === 1) {
                return mb_strlen(str_replace('""', '"', $matches[1]));
            }
        }

        if (is_scalar($value)) {
            return mb_strlen((string) $value);
        }

        return 0;
    }
}
