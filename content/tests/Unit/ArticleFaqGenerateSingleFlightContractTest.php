<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\ProjectRoot;

final class ArticleFaqGenerateSingleFlightContractTest extends TestCase
{
    public function test_all_faq_entry_points_share_one_claimed_single_flight_command(): void
    {
        $editor = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/components/ArticleFaqEditor.jsx',
        );
        $seo = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/hooks/useArticleEditorSeoAnalysis.js',
        );
        $command = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/faqGenerationCommand.js',
        );
        $shortcode = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/components/FaqAccordionPreview.jsx',
        );

        self::assertStringContainsString("state.phase !== 'idle'", $command);
        self::assertStringContainsString("state.phase !== 'opening'", $command);
        self::assertStringContainsString('claimFaqGeneration(requestId)', $editor);
        self::assertStringContainsString("requestFaqGeneration('shortcode')", $seo);
        self::assertStringContainsString("requestFaqGeneration('seo-violation')", $seo);
        self::assertStringContainsString("requestFaqGeneration('faq-panel')", $editor);
        self::assertStringContainsString("disabled={generationBusy", $shortcode);
        self::assertStringContainsString('generateFaqPreview', $editor);
        self::assertStringNotContainsString("new CustomEvent('generate-article-faqs')", $seo);
    }

    public function test_generate_preview_controller_uses_cache_lock(): void
    {
        $controller = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Http/Controllers/ArticleEditorFaqSnapshotController.php',
        );

        self::assertStringContainsString('article-faq-generate-preview:', $controller);
        self::assertStringContainsString('Cache::lock', $controller);
        self::assertStringContainsString('faq_generation_in_flight', $controller);
        self::assertStringContainsString('generatePreview', $controller);
    }

    public function test_prompt_hook_bridge_requires_provider_shadow_flag(): void
    {
        $bridge = (string) file_get_contents(
            ProjectRoot::addonsPath().'/ai-prompt/src/PromptHooks/Runtime/PromptHookCallerBridge.php',
        );
        $flags = (string) file_get_contents(
            ProjectRoot::addonsPath().'/ai-prompt/src/PromptHooks/Runtime/PromptHookMigrationFlags.php',
        );
        $config = (string) file_get_contents(
            ProjectRoot::path().'/config/seo-content-ai.php',
        );

        self::assertStringContainsString('liveShadowProviderEnabled', $bridge);
        self::assertStringContainsString('shadowWithoutProvider', $bridge);
        self::assertStringContainsString('liveShadowProviderEnabled', $flags);
        self::assertStringContainsString('live_shadow_provider_enabled', $config);
        self::assertStringContainsString("env('PROMPT_HOOK_LIVE_SHADOW_PROVIDER_ENABLED', false)", $config);
    }
}
