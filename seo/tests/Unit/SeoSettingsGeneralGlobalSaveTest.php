<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Content\Support\ContentLanguageRegistry;
use Omnichannel\Addons\ContentProjects\Services\ContentProjectWriterCapacitySettingsService;
use Omnichannel\Addons\Seo\Filament\Pages\SeoSettingsGeneral;
use Omnichannel\Addons\Seo\Services\SeoAnalyticsScopeSettingsService;
use Omnichannel\Addons\Seo\Services\SeoContentLanguageSettingsService;
use Omnichannel\Addons\Seo\Services\SeoDateTimeSettingsService;
use Omnichannel\Addons\Seo\Services\SeoOverviewSettingsService;
use PHPUnit\Framework\TestCase;
use Tests\Support\ProjectRoot;

final class SeoSettingsGeneralGlobalSaveTest extends TestCase
{
    public function test_page_uses_single_global_save_action_for_all_sections(): void
    {
        $page = (string) file_get_contents((new \ReflectionClass(SeoSettingsGeneral::class))->getFileName());

        $this->assertStringContainsString('validatedAllFormStates', $page);
        $this->assertStringContainsString('DB::transaction', $page);
        $this->assertStringContainsString('$settings->save', $page);
        $this->assertStringContainsString('$contentLanguageSettings->save', $page);
        $this->assertStringContainsString('$writerCapacitySettings->save', $page);
        $this->assertStringContainsString('$analyticsScopeSettings->save', $page);
        $this->assertStringContainsString('$overviewSettings->saveTeamChatSettings', $page);
        $this->assertStringContainsString('$overviewSettings->saveSocialSupportedDomainsSettings', $page);
        $this->assertStringContainsString("settings_general.settings_saved", $page);

        // Validate every form before any persist (no partial state on validation failure).
        $validatePos = strpos($page, 'validatedAllFormStates');
        $transactionPos = strpos($page, 'DB::transaction');
        $this->assertNotFalse($validatePos);
        $this->assertNotFalse($transactionPos);
        $this->assertLessThan($transactionPos, $validatePos);

        $this->assertStringNotContainsString('function saveTeamChatSettings', $page);
        $this->assertStringNotContainsString('function saveSocialSettings', $page);
        $this->assertStringNotContainsString('team_chat_saved', $page);
        $this->assertStringNotContainsString('social_supports_saved', $page);
        $this->assertStringNotContainsString('default_content_language_saved', $page);
    }

    public function test_view_has_one_save_button_and_no_section_local_saves(): void
    {
        $view = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo-content-ai-compat/resources/views/filament/pages/seo-settings-general.blade.php',
        );

        $this->assertSame(1, substr_count($view, 'form-save-button'));
        $this->assertSame(1, substr_count($view, 'wire:submit="save"'));
        $this->assertStringContainsString('seo-settings-global-save', $view);
        $this->assertStringContainsString('teamChatForm', $view);
        $this->assertStringContainsString('socialSettingsForm', $view);
        $this->assertStringContainsString('section_social_reporting', $view);
        $this->assertStringNotContainsString('saveTeamChatSettings', $view);
        $this->assertStringNotContainsString('saveSocialSettings', $view);
        $this->assertStringNotContainsString('team_chat_save', $view);
        $this->assertStringNotContainsString('social_supports_save', $view);
    }

    public function test_locale_keys_for_global_save_exist(): void
    {
        $en = include ProjectRoot::addonsPath().'/seo-content-ai-compat/lang/en/filament.php';
        $vi = include ProjectRoot::addonsPath().'/seo-content-ai-compat/lang/vi/filament.php';

        foreach (['save', 'settings_saved'] as $key) {
            $this->assertArrayHasKey($key, $en['settings_general']);
            $this->assertArrayHasKey($key, $vi['settings_general']);
        }

        $this->assertSame('Settings saved successfully', $en['settings_general']['settings_saved']);
    }

    public function test_existing_setting_keys_still_roundtrip_via_section_services(): void
    {
        $dateTime = SeoDateTimeSettingsService::withDefaults();
        $contentLanguage = SeoContentLanguageSettingsService::withDefaults();
        $writerCapacity = ContentProjectWriterCapacitySettingsService::withDefaults();
        $analyticsScope = SeoAnalyticsScopeSettingsService::withDefaults();

        $dateTime->save([
            SeoDateTimeSettingsService::KEY_TIMEZONE => 'Asia/Ho_Chi_Minh',
            SeoDateTimeSettingsService::KEY_PRESET => SeoDateTimeSettingsService::PRESET_VI,
        ]);
        $contentLanguage->save([
            SeoContentLanguageSettingsService::KEY_DEFAULT_CONTENT_LANGUAGE => ContentLanguageRegistry::codes()[0],
        ]);
        $writerCapacity->save([
            ContentProjectWriterCapacitySettingsService::KEY_DEFAULT_CAPACITY => 42,
        ]);
        $analyticsScope->save([
            SeoAnalyticsScopeSettingsService::KEY_EXCLUDE_PAGES_FROM_STATISTICS => false,
        ]);

        $this->assertSame('Asia/Ho_Chi_Minh', $dateTime->getSettings()[SeoDateTimeSettingsService::KEY_TIMEZONE]);
        $this->assertSame(
            ContentLanguageRegistry::codes()[0],
            $contentLanguage->getSettings()[SeoContentLanguageSettingsService::KEY_DEFAULT_CONTENT_LANGUAGE],
        );
        $this->assertSame(42, $writerCapacity->getSettings()[ContentProjectWriterCapacitySettingsService::KEY_DEFAULT_CAPACITY]);
        $this->assertFalse($analyticsScope->excludePagesFromStatistics());
    }

    public function test_overview_section_savers_preserve_sibling_keys_in_payload(): void
    {
        $source = (string) file_get_contents((new \ReflectionClass(SeoOverviewSettingsService::class))->getFileName());

        $this->assertStringContainsString('function saveTeamChatSettings', $source);
        $this->assertStringContainsString('function saveSocialSupportedDomainsSettings', $source);
        $this->assertStringContainsString('KEY_TEAM_CHAT_ALLOWED_EXTENSIONS', $source);
        $this->assertStringContainsString('KEY_TEAM_CHAT_MAX_FILE_SIZE_MB', $source);
        $this->assertStringContainsString('KEY_SOCIAL_SUPPORTED_DOMAINS', $source);

        // Each partial saver re-writes the shared option with current sibling values.
        $this->assertMatchesRegularExpression(
            '/saveTeamChatSettings[\s\S]*?KEY_SOCIAL_SUPPORTED_DOMAINS\s*=>\s*\$current\[/',
            $source,
        );
        $this->assertMatchesRegularExpression(
            '/saveSocialSupportedDomainsSettings[\s\S]*?KEY_TEAM_CHAT_ALLOWED_EXTENSIONS\s*=>\s*\$current\[/',
            $source,
        );
    }
}
