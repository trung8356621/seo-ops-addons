<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\SiteHealth;

final class SiteHealthSanitizer
{
    public static function clean(?string $message): ?string
    {
        if ($message === null || trim($message) === '') {
            return null;
        }

        $clean = preg_replace('/Bearer\s+\S+/i', 'Bearer [redacted]', $message) ?? $message;
        $clean = preg_replace('/(token|secret|password|authorization)\s*[:=]\s*\S+/i', '$1=[redacted]', $clean) ?? $clean;

        return mb_substr(trim($clean), 0, 500);
    }
}
