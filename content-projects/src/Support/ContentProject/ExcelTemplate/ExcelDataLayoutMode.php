<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject\ExcelTemplate;

/**
 * How detailed article rows are laid out in managed sheets (after BEGIN_SHEET).
 */
enum ExcelDataLayoutMode: string
{
    case ByWriterSheet = 'BY_WRITER_SHEET';
    case SingleDataSheet = 'SINGLE_DATA_SHEET';

    public static function tryFromMixed(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $normalized = strtoupper(trim($value));

        return self::tryFrom($normalized);
    }

    public static function default(): self
    {
        return self::ByWriterSheet;
    }
}
