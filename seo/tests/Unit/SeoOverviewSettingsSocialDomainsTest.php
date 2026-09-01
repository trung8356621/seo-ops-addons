<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Filament\Pages\SeoSettingsGeneral;
use Omnichannel\Addons\Seo\Services\SeoOverviewSettingsService;
use Omnichannel\Addons\Social\Services\SocialSupportedDomainService;
use PHPUnit\Framework\TestCase;
use Tests\Support\ProjectRoot;

final class SeoOverviewSettingsSocialDomainsTest extends TestCase
{
    public function test_overview_settings_exposes_social_supported_domains_defaults(): void
    {
        $settings = SeoOverviewSettingsService::withDefaults()->getSettings();

        self::assertArrayHasKey(SeoOverviewSettingsService::KEY_SOCIAL_SUPPORTED_DOMAINS, $settings);
        self::assertSame(SocialSupportedDomainService::DEFAULT_DOMAINS, $settings[SeoOverviewSettingsService::KEY_SOCIAL_SUPPORTED_DOMAINS]);
    }

    public function test_general_settings_page_registers_social_supports_form(): void
    {
        $page = (string) file_get_contents((new \ReflectionClass(SeoSettingsGeneral::class))->getFileName());

        self::assertStringContainsString('socialSettingsForm', $page);
        self::assertStringContainsString('saveSocialSettings', $page);
        self::assertStringContainsString('KEY_SOCIAL_SUPPORTED_DOMAINS', $page);
        self::assertStringContainsString('SocialSupportedDomainService', $page);

        $view = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo-content-ai-compat/resources/views/filament/pages/seo-settings-general.blade.php',
        );
        self::assertStringContainsString('saveSocialSettings', $view);
        self::assertStringContainsString('socialSettingsForm', $view);
        self::assertStringContainsString('section_social_reporting', $view);
    }

    public function test_social_supported_domain_service_normalizes_allowlist_entries(): void
    {
        $service = SocialSupportedDomainService::withSupportedDomains([]);
        $domains = $service->domainsFromTextarea(" Facebook.com \nHTTPS://WWW.X.COM/path\nmastodon.social\n");

        self::assertSame([
            'facebook.com',
            'x.com',
            'mastodon.social',
        ], $domains);
    }
}
