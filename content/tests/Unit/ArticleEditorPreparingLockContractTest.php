<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;



use Tests\Support\ProjectRoot;
use Tests\Support\LegacyAddonPath;
use PHPUnit\Framework\TestCase;

/**
 * Static contract: editor preparing gate must reconcile / allow force-open when AI media stuck.
 */
final class ArticleEditorPreparingLockContractTest extends TestCase
{
    public function test_readiness_evaluate_reconciles_stale_ai_media_before_counting(): void
    {
        $path = ProjectRoot::addonsPath().'/content/src/Services/ArticleEditorReadinessService.php';
        $source = (string) file_get_contents($path);

        self::assertStringContainsString(
            'reconcileStaleAiMediaJobs',
            $source,
            'evaluate() must call reconcileStaleAiMediaJobs so dead processing media cannot lock the editor forever',
        );
        self::assertStringContainsString('function abandonPreparingGate', $source);
        self::assertStringContainsString('failAllProcessingAiMediaJobs', $source);
    }

    public function test_media_ai_service_can_fail_all_processing_jobs(): void
    {
        $path = ProjectRoot::addonsPath().'/media/src/Services/ArticleEditorMediaAiService.php';
        $source = (string) file_get_contents($path);

        self::assertStringContainsString('function failAllProcessingAiMediaJobs', $source);
        self::assertStringContainsString(
            "KhÃ´ng gá»­i Ä‘Æ°á»£c job AI:",
            $source,
            'dispatchGenerateMediaJob must mark placeholder failed when dispatch throws',
        );
    }

    public function test_edit_article_exposes_force_open_while_preparing(): void
    {
        $pagePath = ProjectRoot::addonsPath().'/content/src/Filament/Resources/ArticleResource/Pages/EditArticle.php';
        $bladePath = LegacyAddonPath::resolve('resources/views/filament/resources/article-resource/pages/edit-article.blade.php');

        $page = (string) file_get_contents($pagePath);
        $blade = (string) file_get_contents($bladePath);

        self::assertStringContainsString('function forceOpenEditorWhilePreparing', $page);
        self::assertStringContainsString('abandonPreparingGate', $page);
        self::assertStringContainsString('wire:click="forceOpenEditorWhilePreparing"', $blade);
        self::assertStringContainsString('article_editor_preparing_open_anyway', $blade);
    }
}
