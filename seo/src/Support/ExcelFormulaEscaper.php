<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Support;

/**
 * Neutralize Excel formula injection for string cells (=, +, -, @).
 */
final class ExcelFormulaEscaper
{
    public static function escape(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        if ($value === '') {
            return $value;
        }

        $first = $value[0];
        if ($first === '=' || $first === '+' || $first === '-' || $first === '@') {
            return "'".$value;
        }

        return $value;
    }

    /**
     * @param  list<mixed>  $values
     * @return list<mixed>
     */
    public static function escapeRow(array $values): array
    {
        return array_map(static fn (mixed $value): mixed => self::escape($value), $values);
    }
}
