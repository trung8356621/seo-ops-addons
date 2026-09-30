<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Tests\Unit;

use App\Core\ClientCoreServiceProvider;
use App\Core\Workspace\ServiceTopbarRouter;
use Omnichannel\Addons\SiteSync\Services\SiteHealth\GlobalSiteHealthReadModel;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class GlobalSiteHealthShellContractTest extends TestCase
{
    public function test_global_hook_scoping_aggregation_dismissal_and_scheduler_contract(): void
    {
        self::assertSame(['admin', 'seo', 'seo-main', 'seeding'], ServiceTopbarRouter::PANEL_IDS);
        $core = (string) file_get_contents((new ReflectionClass(ClientCoreServiceProvider::class))->getFileName());
        self::assertStringContainsString('PanelsRenderHook::CONTENT_BEFORE', $core);
        self::assertStringContainsString('registerGlobalSiteHealthHook', $core);

        $read = (string) file_get_contents((new ReflectionClass(GlobalSiteHealthReadModel::class))->getFileName());
        self::assertStringContainsString('accessibleSiteIds', $read);
        self::assertStringContainsString("\$row['id'].':'.\$row['severity']", $read);

        $view = (string) file_get_contents(dirname(__DIR__, 2).'/resources/views/livewire/site-health-notice.blade.php');
        self::assertStringStartsWith('<div data-site-health-notice>', $view);
        self::assertStringContainsString('localStorage.setItem', $view);
        self::assertStringNotContainsString('resolved_at', $view);

        $provider = (string) file_get_contents(dirname(__DIR__, 3).'/seo-content-ai-compat/SeoContentAiServiceProvider.php');
        self::assertSame(1, substr_count($provider, '->command(\Omnichannel\Addons\SiteSync\Console\MonitorSiteHealthCommand::class)'));
        self::assertSame(1, substr_count($provider, "'seo-content-ai:site-health-monitor'"));
        self::assertStringContainsString('->everyFiveMinutes()', $provider);
        self::assertStringContainsString('->withoutOverlapping(10)', $provider);
        self::assertStringContainsString("\\Livewire\\Livewire::component(", $provider);
        self::assertStringContainsString("'site-health-notice'", $provider);
        self::assertStringContainsString("loadViewsFrom(dirname(__DIR__).'/site-sync/resources/views', 'site-sync')", $provider);
    }
}
