<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Services;

use Omnichannel\Addons\Seeding\Enums\SeedingSocialPlatform;

final class SeedingSocialPlatformDetector
{
    public function detect(?string $url): ?SeedingSocialPlatform
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $host = strtolower((string) (parse_url(trim($url), PHP_URL_HOST) ?? ''));
        if ($host === '') {
            return null;
        }

        $host = str_starts_with($host, 'www.') ? substr($host, 4) : $host;

        if ($host === 'threads.net' || $host === 'threads.com' || str_ends_with($host, '.threads.net') || str_ends_with($host, '.threads.com')) {
            return SeedingSocialPlatform::Threads;
        }

        if (
            $host === 'facebook.com'
            || $host === 'fb.com'
            || $host === 'm.facebook.com'
            || str_ends_with($host, '.facebook.com')
            || str_ends_with($host, '.fb.com')
        ) {
            return SeedingSocialPlatform::Facebook;
        }

        if ($host === 'tiktok.com' || str_ends_with($host, '.tiktok.com')) {
            return SeedingSocialPlatform::TikTok;
        }

        return SeedingSocialPlatform::Other;
    }
}
