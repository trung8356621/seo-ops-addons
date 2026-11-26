<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Services\ArticleAiHistory\ArticleAiHistoryApplyService;
use Omnichannel\Addons\Content\Services\ArticleAiHistory\ArticleAiHistoryApplicationService;
use Omnichannel\Addons\Content\Services\ArticleContentFaqService;
use Omnichannel\Addons\Content\Services\ArticleOutlineResolver;
use Omnichannel\Addons\Content\Services\SeoArticleRevisionService;
use Omnichannel\Addons\Content\Observers\SeoArticleObserver;
use Omnichannel\Addons\Content\Services\ArticleTocExtractionService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Contract + pure-helper regression for Versioned AI Article Content Apply.
 * Mutation tests against production #3337 / #4853 are forbidden here.
 */
final class ArticleAiHistoryVersionedApplyTest extends TestCase
{
    public function test_application_service_forwards_editor_html_and_document_version(): void
    {
        $method = new ReflectionMethod(ArticleAiHistoryApplicationService::class, 'applyContent');
        $names = array_map(static fn ($p) => $p->getName(), $method->getParameters());

        self::assertContains('editorHtml', $names);
        self::assertContains('expectedDocumentVersion', $names);
        self::assertContains('confirmDirty', $names);
    }

    public function test_unsaved_editor_dedupe_skips_saved_ai_and_pending_payloads(): void
    {
        $ref = new ReflectionClass(ArticleAiHistoryApplyService::class);
        /** @var ArticleAiHistoryApplyService $svc */
        $svc = $ref->newInstanceWithoutConstructor();

        $saved = '<p>saved</p>';
        $ai = '<h2>AI</h2><p>body</p>';
        $pending = $ai;
        $userDraft = '<p>user unsaved edits</p>';

        self::assertFalse($svc->isDistinctUnsavedEditorHtml('', $saved, $ai, $pending));
        self::assertFalse($svc->isDistinctUnsavedEditorHtml($saved, $saved, $ai, $pending));
        self::assertFalse($svc->isDistinctUnsavedEditorHtml($ai, $saved, $ai, $pending));
        self::assertFalse($svc->isDistinctUnsavedEditorHtml($pending, $saved, $ai, $pending));
        self::assertTrue($svc->isDistinctUnsavedEditorHtml($userDraft, $saved, $ai, $pending));
    }

    public function test_markdown_prepare_path_reuses_canonical_parser_and_strips_meta(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ArticleContentFaqService::class))->getFileName(),
        );

        self::assertStringContainsString('function prepareEditorHtmlFromAiOutput', $src);
        self::assertStringContainsString('convertMarkdownImport', $src);
        self::assertStringContainsString('meta_description', $src);
    }

    public function test_toc_rebuild_owned_by_observer_not_apply_service(): void
    {
        $applySrc = (string) file_get_contents(
            (string) (new ReflectionClass(ArticleAiHistoryApplyService::class))->getFileName(),
        );
        $observerSrc = (string) file_get_contents(
            (string) (new ReflectionClass(SeoArticleObserver::class))->getFileName(),
        );
        $tocSrc = (string) file_get_contents(
            (string) (new ReflectionClass(ArticleTocExtractionService::class))->getFileName(),
        );

        self::assertStringNotContainsString('ArticleTocExtractionService', $applySrc);
        self::assertStringNotContainsString('seo_article_headings', $applySrc);
        self::assertStringContainsString('extractForArticle', $observerSrc);
        self::assertStringContainsString('wasChanged(\'body\')', $observerSrc);
        self::assertStringContainsString('parseHtmlHeadings', $tocSrc);
    }

    public function test_planning_outline_meta_key_untouched_by_content_apply(): void
    {
        $applySrc = (string) file_get_contents(
            (string) (new ReflectionClass(ArticleAiHistoryApplyService::class))->getFileName(),
        );
        $resolverSrc = (string) file_get_contents(
            (string) (new ReflectionClass(ArticleOutlineResolver::class))->getFileName(),
        );

        self::assertStringContainsString("META_KEY = 'seo_article_outline'", $resolverSrc);
        self::assertStringContainsString('ArticleOutlineResolver::META_KEY', $applySrc);
        self::assertStringContainsString('planning_outline_unchanged', $applySrc);

        $versionedStart = strpos($applySrc, 'private function applyContentVersioned');
        self::assertNotFalse($versionedStart);
        $versionedEnd = strpos($applySrc, 'private function applyOutlinePending', $versionedStart);
        self::assertNotFalse($versionedEnd);
        $slice = substr($applySrc, $versionedStart, $versionedEnd - $versionedStart);
        self::assertStringNotContainsString('outlineResolver->persist', $slice);
    }

    public function test_wp_linked_force_revision_and_dedupe_helpers_exist(): void
    {
        $revSrc = (string) file_get_contents(
            (string) (new ReflectionClass(SeoArticleRevisionService::class))->getFileName(),
        );
        $applySrc = (string) file_get_contents(
            (string) (new ReflectionClass(ArticleAiHistoryApplyService::class))->getFileName(),
        );

        self::assertStringContainsString('wordpressLink?->wp_post_id', $revSrc);
        self::assertStringContainsString('force: true', $applySrc);
        self::assertStringContainsString('hasRevisionWithContent', $applySrc);
        self::assertStringContainsString('content_apply_save_failed', $applySrc);
        self::assertStringContainsString('assertExpected', $applySrc);
    }

    public function test_invalid_payload_rejected_before_transaction(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ArticleAiHistoryApplyService::class))->getFileName(),
        );

        $invalidPos = strpos($src, 'artifact_payload_invalid');
        $txPos = strpos($src, "DB::connection('omi_seo_ai')->transaction");
        self::assertNotFalse($invalidPos);
        self::assertNotFalse($txPos);
        self::assertLessThan($txPos, $invalidPos);
    }
}
