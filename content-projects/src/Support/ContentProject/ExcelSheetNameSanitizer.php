<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

/**
 * Excel worksheet name constraints: invalid chars, 31-char max, unique names.
 */
final class ExcelSheetNameSanitizer
{
    public const MAX_LENGTH = 31;

    /**
     * @param  array<string, true>  $usedLower
     */
    public static function unique(string $name, array &$usedLower): string
    {
        $base = self::sanitize($name);
        $candidate = $base;
        $n = 2;

        while (isset($usedLower[mb_strtolower($candidate)])) {
            $suffix = ' ('.$n.')';
            $keep = self::MAX_LENGTH - mb_strlen($suffix);
            $candidate = self::truncate($base, max(1, $keep)).$suffix;
            $n++;
        }

        $usedLower[mb_strtolower($candidate)] = true;

        return $candidate;
    }

    public static function sanitize(string $name): string
    {
        $name = str_replace(['\\', '/', '?', '*', '[', ']', ':'], ' ', $name);
        $collapsed = preg_replace('/\s+/u', ' ', $name);
        $name = trim(is_string($collapsed) ? $collapsed : $name);
        $name = trim($name, "'");

        if ($name === '') {
            $name = 'Sheet';
        }

        return self::truncate($name, self::MAX_LENGTH);
    }

    private static function truncate(string $name, int $max): string
    {
        if ($max < 1) {
            return 'S';
        }

        if (mb_strlen($name) <= $max) {
            return $name;
        }

        return mb_substr($name, 0, $max);
    }
}
