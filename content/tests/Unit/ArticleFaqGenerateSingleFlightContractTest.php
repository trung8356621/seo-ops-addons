<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\ProjectRoot;

final class ArticleFaqGenerateSingleFlightContractTest extends TestCase
{
    public function test_faq_editor_has_generate_inflight_ref_guard(): void
    {
        $editor = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/components/ArticleFaqEditor.jsx',
        );

        self::assertStringContainsString('generateFaqInFlightRef', $editor);
        self::assertStringContainsString('generateFaqInFlightRef.current', $editor);
        self::assertStringContainsString('generateFaqPreview', $editor);
        self::assertStringNotContainsString("new CustomEvent('generate-article-faqs')", $editor);
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
