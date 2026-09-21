<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\Models\Site;
use App\Models\WpOption;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\Filament\Resources\PromptResource;
use Omnichannel\Addons\AiPrompt\Filament\Resources\PromptResource\Pages\TestPrompt;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Services\SeoPromptSettingsService;
use Omnichannel\Addons\AiPrompt\Support\PromptSiteContextVariable;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use ReflectionClass;
use Tests\TestCase;

/**
 * TestPrompt mount must not crash when global SEO Site is absent.
 * Root cause was unresolved SeoAccessControl FQCN in PromptSiteContextVariable.
 */
final class PromptTestPageSiteContextContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('wp_options');
        Schema::create('wp_options', function (Blueprint $table): void {
            $table->id();
            $table->string('option_name')->unique();
            $table->longText('option_value')->nullable();
            $table->string('autoload')->default('no');
            $table->timestamps();
        });
        WpOption::clearRequestCache();
        WpOption::set(SeoPromptSettingsService::OPTION_KEY, [
            SeoPromptSettingsService::KEY_TONE_TEXT => 'neutral',
            SeoPromptSettingsService::KEY_ARTICLE_LENGTH_DEFAULT => 2000,
            SeoPromptSettingsService::KEY_ARTICLE_LENGTH_PRODUCT => 800,
            SeoPromptSettingsService::KEY_KEYWORD_DENSITY_DEFAULT => '1%',
            SeoPromptSettingsService::KEY_KEYWORD_DENSITY_PRODUCT => '1%',
        ]);
    }

    public function test_prompt_site_context_imports_seo_access_control(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(PromptSiteContextVariable::class))->getFileName()
        );
        self::assertStringContainsString(
            'use Omnichannel\\Addons\\Seo\\Support\\SeoAccessControl;',
            $src
        );
        self::assertStringContainsString('SeoAccessControl::globalSiteId()', $src);
        self::assertSame(
            'Omnichannel\\Addons\\Seo\\Support\\SeoAccessControl',
            SeoAccessControl::class
        );
        self::assertFalse(class_exists('Omnichannel\\Addons\\AiPrompt\\Support\\SeoAccessControl', false));
    }

    public function test_resolve_for_global_site_without_site_does_not_throw(): void
    {
        SeoAccessControl::clearGlobalSiteSelection();

        $resolved = PromptSiteContextVariable::resolveForGlobalSite('article', []);

        self::assertIsArray($resolved);
        self::assertArrayHasKey('site_domain', $resolved);
        self::assertSame('', (string) $resolved['site_domain']);
        self::assertArrayHasKey('tone', $resolved);
        self::assertArrayHasKey('language', $resolved);
    }

    public function test_resolve_for_site_null_is_domain_independent_safe(): void
    {
        $resolved = PromptSiteContextVariable::resolveForSite(null, 'article');

        self::assertSame('', (string) ($resolved['site_domain'] ?? 'x'));
        self::assertSame('', (string) ($resolved['site_cta'] ?? 'x'));
        self::assertArrayHasKey('article_length', $resolved);
    }

    public function test_resolve_for_site_with_site_still_merges_site_variables(): void
    {
        Schema::dropIfExists('sites');
        Schema::create('sites', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('domain')->nullable();
            $table->string('name')->nullable();
            $table->timestamps();
        });
        Schema::dropIfExists('site_meta');
        Schema::create('site_meta', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->string('meta_key');
            $table->longText('meta_value')->nullable();
            $table->timestamps();
        });

        $site = Site::query()->create([
            'user_id' => 1,
            'domain' => 'example.test',
            'name' => 'Example',
        ]);

        $resolved = PromptSiteContextVariable::resolveForSite($site, 'article');

        self::assertSame('example.test', (string) ($resolved['site_domain'] ?? ''));
        self::assertArrayHasKey('tone', $resolved);
        self::assertArrayHasKey('language', $resolved);
    }

    public function test_merge_into_does_not_require_site_selection(): void
    {
        SeoAccessControl::clearGlobalSiteSelection();

        $merged = PromptSiteContextVariable::mergeInto([
            'mcp_context' => 'hello',
            PromptSiteContextVariable::POST_TYPE_FIELD => 'article',
        ]);

        self::assertSame('hello', $merged['mcp_context']);
        self::assertArrayHasKey('site_domain', $merged);
        self::assertSame('', (string) $merged['site_domain']);
    }

    public function test_test_prompt_mount_always_applies_site_defaults_via_shared_helper(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(TestPrompt::class))->getFileName());
        self::assertStringContainsString('applySiteContextDefaults(true)', $src);
        self::assertStringContainsString('PromptSiteContextVariable::resolveForGlobalSite', $src);
        self::assertStringNotContainsString("hook_key === 'seeding.comment.generate'", $src);
        self::assertStringNotContainsString("panel === 'admin'", $src);
        self::assertStringNotContainsString("getId() === 'admin'", $src);
    }

    public function test_uses_in_prompt_detects_optional_site_vars_without_requiring_them(): void
    {
        $withSite = new SeoPrompt;
        $withSite->markdown_content = 'Domain: {{site_domain}}';
        self::assertTrue(PromptSiteContextVariable::usesInPrompt($withSite));

        $independent = new SeoPrompt;
        $independent->markdown_content = 'Context: {{mcp_context}}';
        $independent->variables = [['name' => 'mcp_context', 'description' => 'x']];
        self::assertFalse(PromptSiteContextVariable::usesInPrompt($independent));
    }

    public function test_no_cloned_test_prompt_ui_or_panel_fallback(): void
    {
        $resourceSrc = (string) file_get_contents((new ReflectionClass(PromptResource::class))->getFileName());
        self::assertStringContainsString("'test' => Pages\\TestPrompt::route", $resourceSrc);
        $props = (new ReflectionClass(TestPrompt::class))->getDefaultProperties();
        self::assertSame(PromptResource::class, $props['resource'] ?? null);
    }
}
