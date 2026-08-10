<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class AiConnectionResourceRouteConflictTest extends TestCase
{
    private const CONNECTION_HASH = 'YTzrG3WKuG3ygjB3cA8wU7CVfV9aRh7G';

    public function test_gsc_edit_route_is_registered_before_numeric_record_edit(): void
    {
        $routes = collect(Route::getRoutes())
            ->filter(static fn ($route): bool => str_contains((string) $route->uri(), 'settings/api'))
            ->values();

        $gscIndex = $routes->search(
            static fn ($route): bool => (string) $route->uri() === 'seo/{connection_hash}/settings/api/google-search-console/{record}/edit',
        );
        $recordEditIndex = $routes->search(
            static fn ($route): bool => (string) $route->uri() === 'seo/{connection_hash}/settings/api/{record}/edit',
        );

        $this->assertNotFalse($gscIndex);
        $this->assertNotFalse($recordEditIndex);
        $this->assertLessThan($recordEditIndex, $gscIndex);
    }

    public function test_legacy_gsc_edit_route_is_registered_before_record_edit(): void
    {
        $routes = collect(Route::getRoutes())
            ->filter(static fn ($route): bool => str_contains((string) $route->uri(), 'settings/api'))
            ->values();

        $legacyIndex = $routes->search(
            static fn ($route): bool => (string) $route->uri() === 'seo/{connection_hash}/settings/api/gsc/edit',
        );
        $recordEditIndex = $routes->search(
            static fn ($route): bool => (string) $route->uri() === 'seo/{connection_hash}/settings/api/{record}/edit',
        );

        $this->assertNotFalse($legacyIndex);
        $this->assertNotFalse($recordEditIndex);
        $this->assertLessThan($recordEditIndex, $legacyIndex);
        $this->assertSame(
            'filament.seo.resources.settings.api.gsc-edit-legacy',
            $routes[$legacyIndex]->getName(),
        );
    }
}
