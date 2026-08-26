<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Tests\Unit;

use Omnichannel\Addons\Content\Support\ArticleLanguageCode;
use Omnichannel\Addons\Content\Support\ContentLanguageCodeNormalizer;
use Omnichannel\Addons\Content\Support\ContentLanguageRegistry;
use Omnichannel\Addons\Seo\Services\SeoContentLanguageSettingsService;
use Omnichannel\Addons\WordPress\Services\SitePrimaryLanguageService;
use Omnichannel\Addons\WordPress\Services\SitePolylangService;
use PHPUnit\Framework\TestCase;
use Tests\Support\ProjectRoot;

final class SitePrimaryLanguageServiceTest extends TestCase
{
    public function test_service_contract_methods_and_meta_key(): void
    {
        $ref = new \ReflectionClass(SitePrimaryLanguageService::class);
        $this->assertSame('seo_primary_language', SitePrimaryLanguageService::META_KEY);

        foreach ([
            'hasPolylang',
            'syncedLanguageOptions',
            'formLanguageOptions',
            'getStoredPrimaryLanguage',
            'wordpressLocale',
            'languageFromWordpressLocale',
            'resolvePrimaryLanguage',
            'seedCandidate',
            'seedIfEmpty',
            'setPrimaryLanguage',
            'primaryLanguageLabel',
            'globalDefaultContentLanguage',
        ] as $method) {
            $this->assertTrue($ref->hasMethod($method), $method.' must exist');
        }
    }

    public function test_options_from_synced_only_no_global_fallback_into_polylang_list(): void
    {
        $src = (string) file_get_contents((new \ReflectionClass(SitePrimaryLanguageService::class))->getFileName());

        $this->assertStringContainsString('WordPressSiteInfoService', $src);
        $this->assertStringContainsString("['active'] ?? false) !== true", $src);
        $this->assertStringNotContainsString('defaultLanguageOptions()', $src);
        $this->assertStringContainsString('ContentLanguageRegistry::selectOptions()', $src);
        $this->assertStringContainsString('normalizePolylangLanguageEntry', $src);

        $polylang = (string) file_get_contents((new \ReflectionClass(SitePolylangService::class))->getFileName());
        $this->assertStringContainsString('defaultLanguageOptions()', $polylang);
    }

    public function test_resolve_precedence_uses_global_default_not_wp_locale(): void
    {
        $src = (string) file_get_contents((new \ReflectionClass(SitePrimaryLanguageService::class))->getFileName());

        $this->assertStringContainsString('hasPolylang', $src);
        $this->assertStringContainsString('globalDefaultContentLanguage', $src);
        $this->assertStringContainsString('SeoContentLanguageSettingsService', $src);
        $this->assertStringContainsString('polylangFallbackLanguage', $src);
        $this->assertStringContainsString('Never seeds from WordPress locale', $src);
        $this->assertStringContainsString('never used as content-language fallback', $src);

        // Non-Polylang setPrimaryLanguage must persist via registry validation.
        $this->assertStringContainsString('ContentLanguageRegistry::isSupported', $src);
        $this->assertDoesNotMatchRegularExpression(
            '/function setPrimaryLanguage[\s\S]*?hasPolylang\(\$site\)[\s\S]*?return;/',
            $src,
        );
    }

    public function test_wordpress_locale_mapping_remains_metadata_helper(): void
    {
        $this->assertSame('vi', ArticleLanguageCode::fromWordpressLocale('vi_VN'));
        $this->assertSame('en', ArticleLanguageCode::fromWordpressLocale('en_US'));
        $this->assertSame('en', ArticleLanguageCode::fromWordpressLocale('en_GB'));
        $this->assertSame('fr', ArticleLanguageCode::fromWordpressLocale('fr_FR'));
        $this->assertSame('', ArticleLanguageCode::fromWordpressLocale(''));
        $this->assertSame('', ArticleLanguageCode::fromWordpressLocale(null));
    }

    public function test_invalid_code_rejected_and_seed_does_not_overwrite(): void
    {
        $src = (string) file_get_contents((new \ReflectionClass(SitePrimaryLanguageService::class))->getFileName());

        $this->assertStringContainsString('ValidationException', $src);
        $this->assertStringContainsString('! isset($options[$normalized])', $src);
        $this->assertStringContainsString('primary_language_invalid_registry', $src);

        $this->assertMatchesRegularExpression(
            '/function seedIfEmpty[\s\S]*?getStoredPrimaryLanguage\(\$site\) !== null[\s\S]*?return \$this->getStoredPrimaryLanguage/',
            $src,
        );
        $this->assertMatchesRegularExpression(
            '/function seedCandidate[\s\S]*?hasPolylang\(\$site\)[\s\S]*?return null/',
            $src,
        );
    }

    public function test_domain_edit_persists_for_polylang_and_non_polylang(): void
    {
        $edit = (string) file_get_contents(
            ProjectRoot::addonsPath().'/search-foundation/src/Filament/Resources/DomainResource/Pages/EditDomain.php',
        );
        $this->assertStringContainsString('seedIfEmpty', $edit);

        $persist = (string) file_get_contents(
            ProjectRoot::addonsPath().'/search-foundation/src/Filament/Resources/DomainResource/Pages/Concerns/PersistsSeoDomainMetas.php',
        );
        $this->assertStringContainsString('seo_primary_language', $persist);
        $this->assertStringContainsString('setPrimaryLanguage', $persist);
        $this->assertStringContainsString('resolvePrimaryLanguage', $persist);
        $this->assertStringNotContainsString('if ($primarySvc->hasPolylang($site))', $persist);

        $resource = (string) file_get_contents(
            ProjectRoot::addonsPath().'/search-foundation/src/Filament/Resources/DomainResource.php',
        );
        $this->assertStringContainsString("Select::make('seo_primary_language')", $resource);
        $this->assertStringContainsString('formLanguageOptions', $resource);
        $this->assertStringContainsString('primary_language_no_polylang', $resource);
        $this->assertStringContainsString('->dehydrated(true)', $resource);
        $this->assertStringNotContainsString('return ! app(SitePrimaryLanguageService::class)->hasPolylang($record)', $resource);

        $siteInfo = (string) file_get_contents(
            ProjectRoot::addonsPath().'/wordpress/src/Services/WordPressSiteInfoService.php',
        );
        $this->assertStringNotContainsString('seo_primary_language', $siteInfo);
    }

    public function test_plugin_site_info_exposes_locale_as_external_metadata(): void
    {
        $candidates = [
            dirname(ProjectRoot::addonsPath(), 2).DIRECTORY_SEPARATOR.'wp-seo-ai'.DIRECTORY_SEPARATOR.'includes'.DIRECTORY_SEPARATOR.'class-seo-plugin-resolver.php',
            'D:'.DIRECTORY_SEPARATOR.'work'.DIRECTORY_SEPARATOR.'wp-seo-ai'.DIRECTORY_SEPARATOR.'includes'.DIRECTORY_SEPARATOR.'class-seo-plugin-resolver.php',
        ];
        $plugin = '';
        foreach ($candidates as $path) {
            if (is_file($path)) {
                $plugin = (string) file_get_contents($path);
                break;
            }
        }
        $this->assertNotSame('', $plugin, 'wp-seo-ai Seo_Plugin_Resolver source must be readable');
        $this->assertStringContainsString("'locale'", $plugin);
        $this->assertStringContainsString('get_locale', $plugin);
    }

    public function test_normalizer_and_registry_ssot(): void
    {
        $this->assertSame('vi', ContentLanguageCodeNormalizer::normalize('vi_VN'));
        $this->assertSame('en', ContentLanguageCodeNormalizer::normalize('en-US'));
        $this->assertNull(ContentLanguageCodeNormalizer::normalize(null));
        $this->assertNull(ContentLanguageCodeNormalizer::normalize(''));
        $this->assertSame(['vi', 'en'], ContentLanguageRegistry::codes());
        $this->assertTrue(ContentLanguageRegistry::isSupported('VI'));
        $this->assertFalse(ContentLanguageRegistry::isSupported('fr'));

        $settings = (string) file_get_contents((new \ReflectionClass(SeoContentLanguageSettingsService::class))->getFileName());
        $this->assertStringContainsString('default_content_language', $settings);
        $this->assertStringContainsString('ContentLanguageRegistry', $settings);
    }

    public function test_ui_language_preset_is_not_content_language(): void
    {
        $general = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo/src/Filament/Pages/SeoSettingsGeneral.php',
        );
        $this->assertStringContainsString('SeoContentLanguageSettingsService', $general);
        $this->assertStringContainsString('KEY_DEFAULT_CONTENT_LANGUAGE', $general);
        $this->assertStringContainsString('ContentLanguageRegistry::selectOptions()', $general);

        // DateTime PRESET_VI/EN is format locale — must remain separate key from content language.
        $this->assertStringContainsString('SeoDateTimeSettingsService::KEY_PRESET', $general);
        $this->assertStringContainsString(
            'SeoContentLanguageSettingsService::KEY_DEFAULT_CONTENT_LANGUAGE',
            $general,
        );
        $this->assertNotSame(
            SeoContentLanguageSettingsService::KEY_DEFAULT_CONTENT_LANGUAGE,
            \Omnichannel\Addons\Seo\Services\SeoDateTimeSettingsService::KEY_PRESET,
        );
    }
}
