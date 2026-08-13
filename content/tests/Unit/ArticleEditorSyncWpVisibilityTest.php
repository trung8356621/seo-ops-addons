<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Tests\Support\LegacyAddonPath;
use PHPUnit\Framework\TestCase;

/**
 * Sync WP toolbar visibility + AI Media workspace contracts.
 */
final class ArticleEditorSyncWpVisibilityTest extends TestCase
{
    public function test_standalone_article_keeps_sync_wp_button(): void
    {
        $actions = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/resources/article-resource/pages/partials/article-editor-page-actions.blade.php'),
        );

        self::assertStringContainsString('data-seo-page-action="sync"', $actions);
        self::assertStringContainsString('articleIsInContentProject', $actions);
    }

    public function test_content_project_branch_never_renders_sync_wp(): void
    {
        $actions = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/resources/article-resource/pages/partials/article-editor-page-actions.blade.php'),
        );

        self::assertStringContainsString('$contentProjectWpSyncEligible', $actions);
        self::assertStringContainsString('Sync WP hidden (UI-only)', $actions);

        self::assertMatchesRegularExpression(
            '/@if\s*\(\$inContentProject\s*&&\s*\$contentProjectWpSyncEligible\)(?:(?!@elseif\s*\(\$inContentProject\)).)*data-seo-page-action="save-close"/s',
            $actions,
        );
        self::assertDoesNotMatchRegularExpression(
            '/@if\s*\(\$inContentProject\s*&&\s*\$contentProjectWpSyncEligible\)(?:(?!@elseif\s*\(\$inContentProject\)).)*data-seo-page-action="sync"/s',
            $actions,
        );

        $contentProjectBranch = $this->extractBetween(
            $actions,
            '@elseif ($inContentProject)',
            "\n            @else",
        );
        $standaloneBranch = $this->extractBetween(
            $actions,
            "\n            @else",
            '@endif',
        );

        self::assertStringNotContainsString('data-seo-page-action="sync"', $contentProjectBranch);
        self::assertStringContainsString('data-seo-page-action="save-close"', $contentProjectBranch);
        self::assertStringContainsString('data-seo-page-action="sync"', $standaloneBranch);
    }

    public function test_ai_media_workspace_and_context_helpers_exist(): void
    {
        $workspace = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/editor/runtime/editorAiMediaWorkspace.js',
        );
        self::assertStringContainsString('pushAiMediaLaunchContext', $workspace);
        self::assertStringContainsString('subscribeAiMediaLaunchContext', $workspace);

        $context = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/utils/buildAiMediaContext.js',
        );
        self::assertStringContainsString('buildAiMediaContextFromSection', $context);
        self::assertStringContainsString('buildAiMediaContextFromBlock', $context);

        $panel = (string) file_get_contents(
            dirname(__DIR__, 3).'/ai-prompt/resources/js/components/ArticleAiChatPanel.jsx',
        );
        self::assertStringContainsString("t('ai_media')", $panel);
        self::assertStringContainsString("t('copy_prompt')", $panel);
        self::assertStringContainsString('generate_with_api', $panel);
    }

    public function test_legacy_ai_floating_launcher_removed_from_editor_entry(): void
    {
        $entry = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/article-editor.jsx',
        );
        self::assertStringNotContainsString('ArticleAiFloatingLauncher', $entry);
        self::assertStringNotContainsString('seo-article-ai-launcher-root', $entry);

        $blade = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/resources/article-resource/pages/edit-article.blade.php'),
        );
        self::assertStringNotContainsString('seo-article-ai-launcher-root', $blade);
    }

    public function test_image_block_picker_uses_compact_ai_prompt_action(): void
    {
        $menu = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/components/BlockInsertMenu.jsx',
        );
        self::assertStringContainsString("t('ai_prompt')", $menu);
        self::assertStringContainsString("t('image_block_choose_media')", $menu);
        self::assertStringNotContainsString("t('generate_image')}/{t('generate_video')", $menu);
        self::assertStringContainsString('openAiMedia', $menu);
    }

    public function test_sync_is_ui_only_hidden_without_shared_runtime_guards(): void
    {
        $api = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/utils/articleEditorApi.js',
        );
        self::assertStringNotContainsString('content_project_manual_sync_forbidden', $api);

        $shortcuts = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/utils/articleEditorShortcuts.js',
        );
        self::assertStringNotContainsString('__SEO_EDITOR_CONTENT_PROJECT_ID__', $shortcuts);

        $entry = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/article-editor.jsx',
        );
        self::assertStringNotContainsString('__SEO_EDITOR_CONTENT_PROJECT_ID__', $entry);

        $editArticle = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Filament/Resources/ArticleResource/Pages/EditArticle.php',
        );
        self::assertStringNotContainsString('content_project_manual_sync_forbidden', $editArticle);
        self::assertStringNotContainsString(
            'articleIsInContentProject($this->record)',
            $this->extractBetween($editArticle, 'public function requestSyncToWordPress', 'public function executeHeavyArticleAction'),
        );
    }

    public function test_copy_prompt_uses_server_prompt_resolver(): void
    {
        $resolver = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/utils/resolveAiMediaPrompt.js',
        );
        self::assertStringContainsString('media-prompt-preview', $resolver);
        self::assertStringContainsString('assertMergedAiMediaPrompt', $resolver);
        self::assertStringNotContainsString('callEditArticleLivewire', $resolver);

        $panel = (string) file_get_contents(
            dirname(__DIR__, 3).'/ai-prompt/resources/js/components/ArticleAiChatPanel.jsx',
        );
        self::assertStringContainsString('resolveAiMediaPrompt', $panel);
        self::assertStringContainsString('resolved.rendered', $panel);
        self::assertStringContainsString('preview_prompt', $panel);
        self::assertStringNotContainsString('await navigator.clipboard.writeText(trimmed)', $panel);

        $service = (string) file_get_contents(
            dirname(__DIR__, 3).'/media/src/Services/ArticleEditorMediaAiService.php',
        );
        self::assertStringContainsString('getCreateTypographyImagePromptId', $service);
        self::assertStringContainsString('previewRenderedImagePrompt', $service);
        self::assertStringContainsString('recordQueuedEditorMediaPromptAttempt', $service);
    }

    private function extractBetween(string $haystack, string $start, string $end): string
    {
        $from = strpos($haystack, $start);
        self::assertNotFalse($from, 'start marker missing: '.$start);
        $to = strpos($haystack, $end, $from);
        self::assertNotFalse($to, 'end marker missing: '.$end);

        return substr($haystack, $from, $to - $from);
    }
}
