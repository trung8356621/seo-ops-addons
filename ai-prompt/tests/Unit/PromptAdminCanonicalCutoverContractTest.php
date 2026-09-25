<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Filament\Facades\Filament;
use Omnichannel\Addons\AiPrompt\Filament\Resources\PromptResource;
use Omnichannel\Addons\Seo\Filament\Pages\SeoSettingsWorkflows;
use Omnichannel\Addons\Seo\Support\SeoUserNavigation;
use ReflectionClass;
use Tests\TestCase;

/**
 * STEP 2B.2 — Admin is canonical Prompt management; SEO keeps compatibility routes only.
 */
final class PromptAdminCanonicalCutoverContractTest extends TestCase
{
    public function test_default_panel_id_is_admin(): void
    {
        self::assertSame('admin', PromptResource::panelId());
        $default = PromptResource::getUrl('index');
        self::assertStringContainsString('/admin/prompts', $default);
        self::assertStringNotContainsString('/seo/prompts', $default);
    }

    public function test_seo_compatibility_urls_still_generate_when_panel_forced(): void
    {
        self::assertStringContainsString('/seo/prompts', PromptResource::getUrl('index', panel: 'seo-main'));
        self::assertStringContainsString('/seo/prompts/create', PromptResource::getUrl('create', panel: 'seo-main'));
        self::assertStringContainsString('/seo/prompts/26/edit', PromptResource::getUrl('edit', ['record' => 26], panel: 'seo-main'));
        self::assertStringContainsString('/seo/prompts/26/test', PromptResource::getUrl('test', ['record' => 26], panel: 'seo-main'));
    }

    public function test_admin_nav_shown_seo_nav_hidden(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        self::assertTrue(PromptResource::shouldRegisterNavigation());
        self::assertSame(SeoUserNavigation::systemGroup(), PromptResource::getNavigationGroup());

        Filament::setCurrentPanel(Filament::getPanel('seo-main'));
        self::assertFalse(PromptResource::shouldRegisterNavigation());
    }

    public function test_both_panels_still_register_same_resource(): void
    {
        self::assertContains(PromptResource::class, Filament::getPanel('admin')->getResources());
        self::assertContains(PromptResource::class, Filament::getPanel('seo-main')->getResources());
    }

    public function test_settings_workflows_prompt_link_uses_default_canonical_get_url(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(SeoSettingsWorkflows::class))->getFileName());
        self::assertStringContainsString('PromptResource::getUrl(\'index\'', $src);
        self::assertStringNotContainsString("panel: 'seo-main'", $src);
    }

    public function test_seeding_prompt_controller_uses_prompt_resource_get_url_without_runtime_change(): void
    {
        $controller = dirname(__DIR__, 3).'/seeding/src/Http/Controllers/SeedingCommentPromptController.php';
        self::assertFileExists($controller);
        $src = (string) file_get_contents($controller);
        self::assertStringContainsString('PromptResource::getUrl', $src);
        self::assertStringContainsString('SeedingSharedCommentPromptResolver', $src);
        self::assertStringNotContainsString('SystemAiClient', $src);
        self::assertStringNotContainsString("panel: 'seo-main'", $src);
    }

    public function test_no_new_prompt_ui_files_and_no_hook_special_case_for_nav(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(PromptResource::class))->getFileName());
        self::assertStringContainsString("return 'admin'", $src);
        self::assertStringContainsString('shouldRegisterNavigation', $src);
        self::assertStringNotContainsString("hook_key === 'seeding.comment.generate'", $src);
        self::assertStringNotContainsString('class AdminPromptResource', $src);
    }

    public function test_domain_article_prompts_route_unchanged(): void
    {
        $articleResource = dirname(__DIR__, 3).'/content/src/Filament/Resources/ArticleResource.php';
        $src = (string) file_get_contents($articleResource);
        self::assertStringContainsString("ViewArticlePrompts::route('/{record}/prompts')", $src);
        self::assertStringNotContainsString('PromptResource::getUrl', $src);
    }
}
