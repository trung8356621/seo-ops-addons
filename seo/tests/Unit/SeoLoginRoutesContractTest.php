<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Filament\Pages\Auth\SeoLogin;
use Omnichannel\Addons\Seo\Http\Controllers\SeoLoginController;
use Tests\TestCase;

final class SeoLoginRoutesContractTest extends TestCase
{
    public function test_login_routes_include_get_and_post_for_hash_and_short(): void
    {
        $routes = collect(app('router')->getRoutes());

        $getShort = $routes->first(
            static fn ($route): bool => in_array('GET', $route->methods(), true) && $route->uri() === 'seo/login',
        );
        $postShort = $routes->first(
            static fn ($route): bool => in_array('POST', $route->methods(), true) && $route->uri() === 'seo/login',
        );
        $getHash = $routes->first(
            static fn ($route): bool => in_array('GET', $route->methods(), true)
                && $route->uri() === 'seo/{connection_hash}/login',
        );
        $postHash = $routes->first(
            static fn ($route): bool => in_array('POST', $route->methods(), true)
                && $route->uri() === 'seo/{connection_hash}/login',
        );

        self::assertNotNull($getShort);
        self::assertNotNull($postShort);
        self::assertNotNull($getHash);
        self::assertNotNull($postHash);
        self::assertSame('filament.seo-main.auth.login', $getShort?->getName());
        self::assertSame('seo.auth.login.store', $postShort?->getName());
        self::assertSame('seo.auth.login.hash.store', $postHash?->getName());
        self::assertSame('filament.seo.auth.login', $getHash?->getName());
        self::assertSame(SeoLoginController::class.'@store', $postShort?->getAction('controller'));
        self::assertSame(SeoLoginController::class.'@store', $postHash?->getAction('controller'));
        self::assertTrue(\Illuminate\Support\Facades\Route::has('filament.seo-main.auth.logout'));
    }

    public function test_short_login_route_is_not_only_hash_param(): void
    {
        $uris = collect(app('router')->getRoutes())
            ->filter(static fn ($route): bool => str_contains((string) $route->uri(), 'login'))
            ->map(static fn ($route): string => (string) $route->uri())
            ->all();

        self::assertContains('seo/login', $uris);
        self::assertContains('seo/{connection_hash}/login', $uris);
    }

    public function test_post_hash_login_route_accepts_post(): void
    {
        $route = collect(app('router')->getRoutes())->first(
            static fn ($route): bool => $route->getName() === 'seo.auth.login.hash.store',
        );

        self::assertNotNull($route);
        self::assertContains('POST', $route->methods());
        self::assertSame('seo/{connection_hash}/login', $route->uri());
    }

    public function test_seo_login_form_action_helper_exists(): void
    {
        self::assertTrue(method_exists(SeoLogin::class, 'getLoginFormActionUrl'));
        self::assertTrue(method_exists(SeoLogin::class, 'getRedirectUrl'));
    }

    public function test_set_dynamic_seo_database_skips_login_paths(): void
    {
        $source = (string) file_get_contents(app_path('Http/Middleware/SetDynamicSeoDatabaseByHash.php'));

        self::assertStringContainsString('shouldSkipHashBootstrap', $source);
        self::assertStringContainsString('seo.auth.login', $source);
        self::assertStringContainsString('seo/login', $source);
    }
}
