<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\LegacyAddonPath;

final class DomainContextLoadingUxTest extends TestCase
{
    public function test_global_domain_loading_starts_from_selector_not_page_listener(): void
    {
        $boot = (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/domain-context.js');
        $assets = (string) file_get_contents(dirname(__DIR__, 2).'/resources/views/hooks/domain-context-assets.blade.php');
        $bar = (string) file_get_contents(dirname(__DIR__, 2).'/resources/views/livewire/global-seo-bar.blade.php');

        self::assertStringContainsString('beginDomainTransition', $boot);
        self::assertStringContainsString('domainTransitionKey', $boot);
        self::assertStringContainsString('SeoDomainContext.select', $boot);
        self::assertStringContainsString("x-on:change=\"window.SeoDomainContext && window.SeoDomainContext.select(\$event.target.value)\"", $bar);
        self::assertStringNotContainsString('LOADING_DELAY_MS', $boot);
        self::assertStringNotContainsString('endLoading()', $boot);
        self::assertStringNotContainsString('onDomainContextChanged', $boot);
        self::assertStringContainsString('isPollCommit', $boot);
        self::assertStringContainsString('DOMAIN_FAILSAFE_MS', $boot);
        self::assertStringContainsString('is-domain-context-loading', $assets);
        self::assertStringContainsString('seo-panel-loading-bar', $assets);
        self::assertStringContainsString('.fi-page', $assets);
        self::assertStringContainsString('isDomainNeutralPanelPath', $boot);
    }

    public function test_shared_bar_is_refcounted_and_used_by_list_shells(): void
    {
        $panel = (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/panelLoading.js');
        $article = (string) file_get_contents(dirname(__DIR__, 3).'/content/resources/js/articleListTableLoading.js');
        $shell = (string) file_get_contents(LegacyAddonPath::resolve(
            'resources/views/components/list-table-loading-shell.blade.php',
        ));

        self::assertStringContainsString('beginPanelBar', $panel);
        self::assertStringContainsString('endPanelBar', $panel);
        self::assertStringContainsString('barCount', $panel);
        self::assertStringContainsString('domainCount', $panel);
        self::assertStringContainsString('SeoPanelLoading', $panel);
        self::assertStringContainsString('SeoPanelLoading?.beginBar', $article);
        self::assertStringContainsString('SeoPanelLoading?.endBar', $article);
        self::assertStringContainsString('is-table-loading', $article);
        self::assertStringContainsString('SeoPanelLoading?.beginBar', $shell);
        self::assertStringContainsString('SeoPanelLoading?.endBar', $shell);
        self::assertStringContainsString('is-table-loading', $shell);
    }

    public function test_project_list_does_not_need_domain_refresh_trait_for_global_loading(): void
    {
        $boot = (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/domain-context.js');
        $list = (string) file_get_contents(dirname(__DIR__, 3).'/content-projects/src/Filament/Resources/SeoProjectResource/Pages/ListSeoProjects.php');

        self::assertStringContainsString('beginDomainTransition', $boot);
        self::assertStringNotContainsString('RefreshesOnDomainContextChanged', $list);
        self::assertStringContainsString('list-table-loading-shell', LegacyAddonPath::read(
            'resources/views/filament/resources/seo-project-resource/pages/list-seo-projects.blade.php',
        ));
    }
}
