<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Filament\Pages\SeoSettingsConfigurationTransfer;
use Omnichannel\Addons\Seo\Filament\Pages\SeoSettingsOverview;
use Omnichannel\Addons\Seo\Support\SeoSettingsMenu;
use PHPUnit\Framework\TestCase;
use Tests\Support\ProjectRoot;

final class ImportExportNavigationTest extends TestCase
{
    public function test_import_export_is_settings_submenu_not_overview_action(): void
    {
        $menu = (string) file_get_contents((new \ReflectionClass(SeoSettingsMenu::class))->getFileName());
        $this->assertStringContainsString('import-export', $menu);
        $this->assertStringContainsString('SeoSettingsConfigurationTransfer', $menu);
        $this->assertStringContainsString('SeoSettingsAiCenter', $menu);
        $this->assertStringContainsString('AiConnectionResource::getUrl()', $menu);
        $this->assertStringContainsString("'id' => 'api'", $menu);

        $overview = (string) file_get_contents(ProjectRoot::addonsPath().'/seo-content-ai-compat/resources/views/filament/pages/seo-settings-overview.blade.php');
        $this->assertStringNotContainsString('seo-settings-transfer-actions', $overview);
        $this->assertStringNotContainsString('AI model status', $overview);
        $this->assertStringNotContainsString('syncAllAiModels', $overview);

        $page = (string) file_get_contents((new \ReflectionClass(SeoSettingsConfigurationTransfer::class))->getFileName());
        $this->assertStringContainsString('settings/configuration', $page);
        $this->assertStringContainsString('shouldRegisterNavigation = false', $page);
    }

    public function test_overview_page_class_has_no_ai_sync_actions(): void
    {
        $src = (string) file_get_contents((new \ReflectionClass(SeoSettingsOverview::class))->getFileName());
        $this->assertStringNotContainsString('syncAllAiModels', $src);
        $this->assertStringNotContainsString('aiModelsOverview', $src);
    }
}
