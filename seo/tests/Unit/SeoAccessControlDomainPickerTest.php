<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class SeoAccessControlDomainPickerTest extends TestCase
{
    public function test_shows_domain_picker_on_keyword_clusters_page(): void
    {
        Route::get('/seo/test-hash/keywords/clusters', fn () => 'ok')->name('filament.seo.resources.keywords.clusters');

        $request = Request::create('/seo/test-hash/keywords/clusters', 'GET');
        $route = Route::getRoutes()->match($request);
        $request->setRouteResolver(static fn () => $route);
        $this->app->instance('request', $request);

        $this->assertTrue(SeoAccessControl::shouldShowGlobalSitePicker());
    }

    public function test_hides_domain_picker_on_performance_hub_provider_source(): void
    {
        Route::get('/seo/test-hash/performance-hub', fn () => 'ok')->name('filament.seo.pages.performance-hub');

        $request = Request::create('/seo/test-hash/performance-hub?source=serpapi', 'GET');
        $route = Route::getRoutes()->match($request);
        $request->setRouteResolver(static fn () => $route);
        $this->app->instance('request', $request);

        $this->assertFalse(SeoAccessControl::shouldShowGlobalSitePicker());
    }

    public function test_shows_domain_picker_on_performance_hub_gsc_source(): void
    {
        Route::get('/seo/test-hash/performance-hub', fn () => 'ok')->name('filament.seo.pages.performance-hub');

        $request = Request::create('/seo/test-hash/performance-hub?source=gsc', 'GET');
        $route = Route::getRoutes()->match($request);
        $request->setRouteResolver(static fn () => $route);
        $this->app->instance('request', $request);

        $this->assertTrue(SeoAccessControl::shouldShowGlobalSitePicker());
    }

    public function test_shows_domain_picker_on_other_pages(): void
    {
        Route::get('/seo/test-hash/articles', fn () => 'ok')->name('filament.seo.resources.articles.index');

        $request = Request::create('/seo/test-hash/articles', 'GET');
        $route = Route::getRoutes()->match($request);
        $request->setRouteResolver(static fn () => $route);
        $this->app->instance('request', $request);

        $this->assertTrue(SeoAccessControl::shouldShowGlobalSitePicker());
    }

    public function test_hides_domain_picker_on_articles_optimal_page(): void
    {
        Route::get('/seo/test-hash/articles/optimal', fn () => 'ok')->name('filament.seo.pages.articles-optimal');

        $request = Request::create('/seo/test-hash/articles/optimal', 'GET');
        $route = Route::getRoutes()->match($request);
        $request->setRouteResolver(static fn () => $route);
        $this->app->instance('request', $request);

        $this->assertFalse(SeoAccessControl::shouldShowGlobalSitePicker());
    }

    public function test_hides_domain_picker_on_content_projects_list(): void
    {
        Route::get('/seo/test-hash/content-projects', fn () => 'ok')->name('filament.seo.resources.content-projects.index');

        $request = Request::create('/seo/test-hash/content-projects', 'GET');
        $route = Route::getRoutes()->match($request);
        $request->setRouteResolver(static fn () => $route);
        $this->app->instance('request', $request);

        $this->assertFalse(SeoAccessControl::shouldShowGlobalSitePicker());
    }
}
