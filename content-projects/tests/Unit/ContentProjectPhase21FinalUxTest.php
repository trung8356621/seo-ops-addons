<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;



use Tests\Support\ProjectRoot;
use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\Media\Services\ContentProjectImageRerunCleanupContract;
use PHPUnit\Framework\TestCase;

final class ContentProjectPhase21FinalUxTest extends TestCase
{
    public function test_generic_picker_no_browser_prompt(): void
    {
        $js = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/resources/js/project-run-queue.js',
        );
        $blade = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/resources/seo-project-resource/pages/view-project-run.blade.php'),
        );
        self::assertStringNotContainsString('window.prompt(', $js);
        self::assertStringContainsString('genericStepOpen', $js);
        self::assertStringContainsString('confirmGenericStepRerun', $js);
        self::assertStringContainsString('genericStepOpen', $blade);
        self::assertStringContainsString('Chạy lại bước...', $blade);
    }

    public function test_history_wires_execution_type(): void
    {
        $history = (string) file_get_contents(
            ProjectRoot::addonsPath().'/ai-prompt/src/Services/ArticlePromptRunHistoryService.php',
        );
        $view = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/resources/article-resource/pages/view-article-prompts.blade.php'),
        );
        self::assertStringContainsString('execution_type_label', $history);
        self::assertStringContainsString('Chạy lại', $history);
        self::assertStringContainsString('Thử lại', $history);
        self::assertStringContainsString('Lần chạy đầu', $history);
        self::assertStringContainsString('Bỏ qua vì bài đã thay đổi', $history);
        self::assertStringContainsString('execution_type_label', $view);
    }

    public function test_image_cleanup_contract_after_persist(): void
    {
        self::assertSame(
            ['generate', 'persist', 'update_reference', 'commit', 'cleanup_old'],
            ContentProjectImageRerunCleanupContract::requiredOrder(),
        );
        $mediaAi = (string) file_get_contents(
            ProjectRoot::addonsPath().'/media/src/Services/ArticleEditorMediaAiService.php',
        );
        self::assertTrue(
            ContentProjectImageRerunCleanupContract::assertsCleanupAfterPersist($mediaAi),
        );
    }
}
