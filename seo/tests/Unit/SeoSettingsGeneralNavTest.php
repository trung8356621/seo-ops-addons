<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Filament\Pages\SeoSettings;
use Omnichannel\Addons\Seo\Filament\Pages\SeoSettingsDateTime;
use Omnichannel\Addons\Seo\Filament\Pages\SeoSettingsGeneral;
use Omnichannel\Addons\Seo\Filament\Pages\SeoSettingsOverview;
use Omnichannel\Addons\Seo\Services\SeoContentLanguageSettingsService;
use Omnichannel\Addons\Seo\Support\SeoSettingsMenu;
use PHPUnit\Framework\TestCase;
use Tests\Support\ProjectRoot;

final class SeoSettingsGeneralNavTest extends TestCase
{
    public function test_menu_has_general_not_overview_or_date_time(): void
    {
        $menu = (string) file_get_contents((new \ReflectionClass(SeoSettingsMenu::class))->getFileName());

        $this->assertStringContainsString("'id' => 'general'", $menu);
        $this->assertStringContainsString('SeoSettingsGeneral::getUrl()', $menu);
        $this->assertStringContainsString('settings_general.nav', $menu);
        $this->assertStringNotContainsString("'id' => 'overview'", $menu);
        $this->assertStringNotContainsString("'id' => 'date-time'", $menu);
        $this->assertStringNotContainsString('SeoSettingsOverview::getUrl()', $menu);
        $this->assertStringNotContainsString('SeoSettingsDateTime::getUrl()', $menu);
    }

    public function test_settings_hub_and_legacy_pages_redirect_to_general(): void
    {
        $hub = (string) file_get_contents((new \ReflectionClass(SeoSettings::class))->getFileName());
        $this->assertStringContainsString('SeoSettingsGeneral::getUrl()', $hub);

        $overview = (string) file_get_contents((new \ReflectionClass(SeoSettingsOverview::class))->getFileName());
        $this->assertStringContainsString("protected static ?string \$slug = 'settings/overview'", $overview);
        $this->assertStringContainsString('SeoSettingsGeneral::getUrl()', $overview);
        $this->assertStringContainsString('redirect', $overview);

        $dateTime = (string) file_get_contents((new \ReflectionClass(SeoSettingsDateTime::class))->getFileName());
        $this->assertStringContainsString("protected static ?string \$slug = 'settings/date-time'", $dateTime);
        $this->assertStringContainsString('SeoSettingsGeneral::getUrl()', $dateTime);
        $this->assertStringContainsString('redirect', $dateTime);
    }

    public function test_general_page_merges_datetime_content_language_and_team_chat_saves(): void
    {
        $page = (string) file_get_contents((new \ReflectionClass(SeoSettingsGeneral::class))->getFileName());
        $this->assertStringContainsString("protected static ?string \$slug = 'settings/general'", $page);
        $this->assertStringContainsString('shouldRegisterNavigation = false', $page);
        $this->assertStringContainsString('saveTeamChatSettings', $page);
        $this->assertStringContainsString('SeoDateTimeSettingsService', $page);
        $this->assertStringContainsString('SeoContentLanguageSettingsService', $page);
        $this->assertStringContainsString('SeoOverviewSettingsService', $page);
        $this->assertStringContainsString('KEY_DEFAULT_CONTENT_LANGUAGE', $page);
        $this->assertStringContainsString('ContentLanguageRegistry::selectOptions()', $page);
        $this->assertStringContainsString('canAccessManagerFeatures', $page);

        $settings = (string) file_get_contents((new \ReflectionClass(SeoContentLanguageSettingsService::class))->getFileName());
        $this->assertStringContainsString('default_content_language', $settings);
        $this->assertStringContainsString('seo_content_language_settings', $settings);

        $view = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo-content-ai-compat/resources/views/filament/pages/seo-settings-general.blade.php',
        );
        $this->assertStringContainsString("'active' => 'general'", $view);
        $this->assertStringContainsString('section_regional', $view);
        $this->assertStringContainsString('section_workspace', $view);
        $this->assertStringContainsString('saveTeamChatSettings', $view);
        $this->assertStringContainsString('overview_teaser', $view);

        $en = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo-content-ai-compat/lang/en/filament.php',
        );
        $this->assertStringContainsString("'default_content_language' =>", $en);
        $this->assertStringContainsString('Not the application UI language', $en);
    }
}
