<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Support;


use Omnichannel\Addons\Content\Support\SystemDateTime;
use Carbon\Carbon;

/**
 * @deprecated Use SystemDateTime — kept as thin BC facade for existing callers.
 */
final class SeoDisplayTimezone
{
    public static function name(): string
    {
        return SystemDateTime::timezone();
    }

    public static function now(): Carbon
    {
        return SystemDateTime::currentSystemTime();
    }

    public static function parse(?string $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            if (preg_match('/[Zz]$|[+-]\d{2}:?\d{2}$/', trim($value)) === 1) {
                return SystemDateTime::toSystemTimezone($value);
            }

            return SystemDateTime::parseSystemInputToUtc($value)->timezone(self::name());
        } catch (\Throwable) {
            return SystemDateTime::toSystemTimezone($value);
        }
    }

    public static function format(?string $value, string $format = ''): ?string
    {
        if ($format !== '' && $format !== 'd/m/Y H:i') {
            $local = SystemDateTime::toSystemTimezone($value);

            return $local?->format($format);
        }

        return SystemDateTime::formatDateTime($value);
    }

    public static function formatScheduleLabel(Carbon $dateTime): string
    {
        return SystemDateTime::formatScheduleLabel($dateTime);
    }
}
