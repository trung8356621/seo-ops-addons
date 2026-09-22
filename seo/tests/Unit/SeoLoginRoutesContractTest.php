<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Tests\TestCase;

/**
 * Canonical auth: panel login routes redirect to /login; no SEO-owned login controllers.
 */
final class SeoLoginRoutesContractTest extends TestCase
{
    public function test_legacy_seo_login_paths_redirect_to_canonical_login(): void
    {
        $this->get('/seo/login')->assertRedirect('/login');

        $hash = str_repeat('a', 32);
        $this->get('/seo/'.$hash.'/login')->assertRedirect('/login');
    }

    public function test_seo_login_post_and_filament_login_routes_are_removed(): void
    {
        self::assertFalse(\Illuminate\Support\Facades\Route::has('filament.seo-main.auth.login'));
        self::assertFalse(\Illuminate\Support\Facades\Route::has('filament.seo.auth.login'));
        self::assertFalse(\Illuminate\Support\Facades\Route::has('seo.auth.login.store'));
        self::assertFalse(\Illuminate\Support\Facades\Route::has('seo.auth.login.hash.store'));
        self::assertTrue(\Illuminate\Support\Facades\Route::has('login'));
        self::assertTrue(\Illuminate\Support\Facades\Route::has('filament.seo-main.auth.logout'));

        $seoLogin = collect(app('router')->getRoutes())->first(
            static fn ($route): bool => $route->uri() === 'seo/login',
        );
        self::assertNotNull($seoLogin);
        self::assertSame(
            \Illuminate\Routing\RedirectController::class,
            ltrim((string) $seoLogin->getControllerClass(), '\\'),
        );
    }

    public function test_set_dynamic_seo_database_skips_legacy_login_paths(): void
    {
        $source = (string) file_get_contents(app_path('Http/Middleware/SetDynamicSeoDatabaseByHash.php'));

        self::assertStringContainsString('shouldSkipHashBootstrap', $source);
        self::assertStringContainsString('seo/login', $source);
        self::assertStringContainsString('/workspace', $source);
    }
}
