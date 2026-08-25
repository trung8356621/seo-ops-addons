<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Tests\Unit;

use Omnichannel\Addons\Content\Support\ArticleLanguageCode;
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
        ] as $method) {
            $this->assertTrue($ref->hasMethod($method), $method.' must exist');
        }
    }

    public function test_options_from_synced_only_no_global_fallback(): void
    {
        $src = (string) file_get_contents((new \ReflectionClass(SitePrimaryLanguageService::class))->getFileName());

        $this->assertStringContainsString('WordPressSiteInfoService', $src);
        $this->assertStringContainsString("['active'] ?? false) !== true", $src);
        $this->assertStringNotContainsString('defaultLanguageOptions()', $src);
        $this->assertStringNotContainsString('? $options : $this->defaultLanguageOptions()', $src);
        $this->assertDoesNotMatchRegularExpression(
            '/return\s+ArticleLanguageCode::defaultLabels\(\)/',
            $src,
        );

        $polylang = (string) file_get_contents((new \ReflectionClass(SitePolylangService::class))->getFileName());
        $this->assertStringContainsString('defaultLanguageOptions()', $polylang);
    }

    public function test_resolve_precedence_includes_wp_locale_when_no_polylang(): void
    {
        $src = (string) file_get_contents((new \ReflectionClass(SitePrimaryLanguageService::class))->getFileName());

        $this->assertStringContainsString('hasPolylang', $src);
        $this->assertStringContainsString('languageFromWordpressLocale', $src);
        $this->assertStringContainsString('fromWordpressLocale', $src);
        $this->assertStringContainsString('polylangFallbackLanguage', $src);
        $this->assertStringContainsString('Never seeds from WordPress locale', $src);

        // Non-Polylang setPrimaryLanguage must no-op (no fake persist).
        $this->assertMatchesRegularExpression(
            '/function setPrimaryLanguage[\s\S]*?hasPolylang\(\$site\)[\s\S]*?return;/',
            $src,
        );
    }

    public function test_wordpress_locale_mapping(): void
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

        $this->assertMatchesRegularExpression(
            '/function seedIfEmpty[\s\S]*?getStoredPrimaryLanguage\(\$site\) !== null[\s\S]*?return \$this->getStoredPrimaryLanguage/',
            $src,
        );
        $this->assertMatchesRegularExpression(
            '/function seedCandidate[\s\S]*?hasPolylang\(\$site\)[\s\S]*?return null/',
            $src,
        );
    }

    public function test_domain_edit_seeds_and_persists_via_service(): void
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
        $this->assertStringContainsString('hasPolylang', $persist);
        $this->assertStringContainsString('resolvePrimaryLanguage', $persist);

        $resource = (string) file_get_contents(
            ProjectRoot::addonsPath().'/search-foundation/src/Filament/Resources/DomainResource.php',
        );
        $this->assertStringContainsString("Select::make('seo_primary_language')", $resource);
        $this->assertStringContainsString('formLanguageOptions', $resource);
        $this->assertStringContainsString('hasPolylang', $resource);
        $this->assertStringContainsString('primary_language_no_polylang', $resource);
        $this->assertStringContainsString('->dehydrated(', $resource);

        $siteInfo = (string) file_get_contents(
            ProjectRoot::addonsPath().'/wordpress/src/Services/WordPressSiteInfoService.php',
        );
        $this->assertStringNotContainsString('seo_primary_language', $siteInfo);
    }

    public function test_plugin_site_info_exposes_locale_for_non_polylang_fallback(): void
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
}
