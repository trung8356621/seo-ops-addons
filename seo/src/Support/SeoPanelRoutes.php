<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Support;

use Illuminate\Support\Facades\Route;

/**
 * Short Main Service panel uses id "seo-main" (path /seo/...).
 * Hash/secondary panel keeps id "seo" (path /seo/{connection_hash}/...).
 * Route-name checks must accept both.
 */
final class SeoPanelRoutes
{
    /**
     * @param  string  ...$patterns  Prefer filament.seo.* patterns; seo-main aliases are added automatically.
     */
    public static function is(string ...$patterns): bool
    {
        $expanded = [];

        foreach ($patterns as $pattern) {
            $expanded[] = $pattern;

            if (str_starts_with($pattern, 'filament.seo-main.')) {
                continue;
            }

            if (str_starts_with($pattern, 'filament.seo.')) {
                $expanded[] = 'filament.seo-main.'.substr($pattern, strlen('filament.seo.'));
            }
        }

        return request()->routeIs(...$expanded);
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public static function shortLoginUrl(array $parameters = []): string
    {
        if (Route::has('filament.seo-main.auth.login')) {
            return route('filament.seo-main.auth.login', $parameters);
        }

        return url('/seo/login');
    }
}
