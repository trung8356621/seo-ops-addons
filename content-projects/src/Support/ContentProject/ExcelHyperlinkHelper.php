<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

/**
 * Build Excel HYPERLINK formulas for OpenSpout FormulaCell (= prefix).
 */
final class ExcelHyperlinkHelper
{
    public static function formula(string $url, string $label): string
    {
        $url = trim($url);
        $label = trim($label);
        if ($url === '' || $label === '') {
            return $label;
        }

        return '=HYPERLINK("'.self::escape($url).'","'.self::escape($label).'")';
    }

    /**
     * Escape string cells but keep formula cells (=...) intact for OpenSpout.
     *
     * @param  list<mixed>  $values
     * @return list<mixed>
     */
    public static function escapeRowPreservingFormulas(array $values): array
    {
        $escaped = [];
        foreach ($values as $value) {
            if (is_string($value) && str_starts_with($value, '=')) {
                $escaped[] = $value;

                continue;
            }

            $escaped[] = \Omnichannel\Addons\Seo\Support\ExcelFormulaEscaper::escape($value);
        }

        return $escaped;
    }

    private static function escape(string $value): string
    {
        return str_replace('"', '""', $value);
    }
}
