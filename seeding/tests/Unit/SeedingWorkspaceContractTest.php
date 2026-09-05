<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Tests\Unit;

use Omnichannel\Addons\Seeding\Http\Controllers\SeedingTopicController;
use Omnichannel\Addons\Seeding\Filament\Pages\ManageSeedingTopicPage;
use Omnichannel\Addons\Seeding\Filament\Pages\SeedingTopicsPage;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class SeedingWorkspaceContractTest extends TestCase
{
    public function test_api_routes_registered_in_seo_panel_provider(): void
    {
        $provider = (string) file_get_contents(
            dirname(__DIR__, 3).'/seo-content-ai-compat/Providers/SeoPanelProvider.php'
        );
        self::assertStringContainsString(SeedingTopicController::class, $provider);
        self::assertStringContainsString('api/seo/seeding-topics', $provider);
        self::assertStringContainsString('seo.seeding-topics.index', $provider);
        self::assertStringContainsString('seo.seeding-topics.update', $provider);
    }

    public function test_main_page_mounts_react_workspace(): void
    {
        $page = (string) file_get_contents(
            (new ReflectionClass(SeedingTopicsPage::class))->getFileName()
        );
        self::assertStringContainsString('workspaceProps', $page);

        $blade = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/views/filament/pages/seeding-topics-page.blade.php'
        );
        self::assertStringContainsString('seeding-workspace-root', $blade);
        self::assertStringContainsString('seeding-workspace.jsx', $blade);
        self::assertStringNotContainsString('createUrl', $blade);
        self::assertStringNotContainsString('Về danh sách', $blade);
    }

    public function test_manage_page_redirects_to_workspace(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ManageSeedingTopicPage::class))->getFileName()
        );
        self::assertStringContainsString('SeedingTopicsPage::getUrl', $source);
        self::assertStringContainsString('redirect', $source);
    }

    public function test_vite_registers_seeding_workspace_entry(): void
    {
        $vitePath = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'omnichannel-client'.DIRECTORY_SEPARATOR.'vite.config.js';
        $vitePath = realpath($vitePath) ?: $vitePath;
        self::assertFileExists($vitePath);
        $vite = (string) file_get_contents($vitePath);
        self::assertStringContainsString('addons/seeding/resources/js/seeding-workspace.jsx', $vite);
    }
}
