<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject\ExcelTemplate;

/**
 * One managed detail-data column (display label + stable system code).
 */
final class ExcelDetailColumnDefinition
{
    public function __construct(
        public readonly string $code,
        public readonly string $label,
        public readonly bool $requiredForImport = false,
        public readonly bool $dataSheetOnly = false,
    ) {}
}
