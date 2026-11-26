<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Services\ArticleAiHistory\ArticleAiHistoryApplyService;
use Omnichannel\Addons\Content\Services\ArticleAiHistory\ArticleAiHistoryDeleteService;
use Omnichannel\Addons\Content\Services\ArticleAiHistory\ArticleAiHistoryListService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Source-wiring regression lock — Article AI History apply/delete:
 * - Fail-closed theo artifact_type article_outline/article_content.
 * - Không đổi trạng thái generation/run/workflow.
 * - Không gọi AI.
 * - Content Apply = Versioned Save (canonical persist); Outline Apply = pending draft.
 * - Delete không xoá article/run/run_item/task/revision.
 */
final class ArticleAiHistoryApplyGuardsTest extends TestCase
{
    public function test_apply_service_guards_and_splits_content_versioned_vs_outline_pending(): void
    {
        $src = $this->source(ArticleAiHistoryApplyService::class);

        self::assertStringContainsString('WorkflowArtifactType::ArticleOutline', $src);
        self::assertStringContainsString('WorkflowArtifactType::ArticleContent', $src);
        self::assertStringContainsString('ArticleAiHistoryPendingDraftStore', $src);
        self::assertStringContainsString('requires_dirty_confirm', $src);
        self::assertStringContainsString('artifact_type_mismatch', $src);
        self::assertStringContainsString('artifact_deleted', $src);
        self::assertStringContainsString('artifact_payload_invalid', $src);
        self::assertStringContainsString('ArtifactReusePolicy', $src);
        self::assertStringContainsString('looksLikeOutlineMarkerPayload', $src);
        self::assertStringContainsString('prepareEditorHtmlFromAiOutput', $src);
        self::assertStringContainsString('ArticleContentFaqService', $src);
        self::assertStringContainsString('ensureBaselineBeforeAiApply', $src);
        self::assertStringContainsString('SeoArticleRevisionService', $src);
        self::assertStringContainsString('ArticleEditorPersistService', $src);
        self::assertStringContainsString('writeArticleRow', $src);
        self::assertStringContainsString('content_apply_saved', $src);
        self::assertStringContainsString('versioned_save', $src);
        self::assertStringContainsString('isDistinctUnsavedEditorHtml', $src);
        self::assertStringContainsString('assertExpected', $src);
        self::assertStringContainsString('planning_outline_unchanged', $src);
        self::assertStringContainsString('ArticleOutlineResolver::META_KEY', $src);

        // Never mutate generation/run status, never call AI / publish WP.
        self::assertStringNotContainsString('PromptRunnerService', $src);
        self::assertStringNotContainsString('AiProviderRegistry', $src);
        self::assertStringNotContainsString('SeoProjectRunItem', $src);
        self::assertStringNotContainsString('SeoProjectTask', $src);
        self::assertStringNotContainsString('->status = ', $src);
        self::assertStringNotContainsString('SyncProvider', $src);

        // Outline path still pending; content path clears pending after save.
        self::assertStringContainsString('ArticleOutlineResolver', $src);
        self::assertStringContainsString('commitPendingOnSave', $src);
        self::assertStringContainsString('outline_apply_pending', $src);
        self::assertStringContainsString('draftStore->put', $src);
        self::assertStringContainsString('draftStore->clear', $src);

        // Planning Outline persist only via commitPendingOnSave (outline target), never in content Versioned Save.
        self::assertSame(1, substr_count($src, '$this->outlineResolver->persist('));
        self::assertStringContainsString('if ($target === \'outline\' && $payload !== \'\')', $src);
    }

    public function test_list_service_wraps_article_prompt_run_history_service(): void
    {
        $src = $this->source(ArticleAiHistoryListService::class);

        self::assertStringContainsString('ArticlePromptRunHistoryService', $src);
        self::assertStringContainsString('->build(', $src);
        self::assertStringContainsString('resolveOwnedArtifact', $src);
        self::assertStringContainsString('loadOutputsForGroups', $src);
        self::assertStringContainsString('resolveClassifyOutput', $src);
        self::assertStringContainsString('buildAppliedLabel', $src);
        self::assertStringNotContainsString("can_apply_content'] = true", $src);
        self::assertStringNotContainsString('$prompt[\'can_apply_content\'] = true', $src);
    }

    public function test_delete_service_never_deletes_article_run_item_task_or_revision(): void
    {
        $src = $this->source(ArticleAiHistoryDeleteService::class);

        self::assertStringContainsString('SeoArticleAiHistoryTombstone', $src);
        self::assertStringContainsString('SeoPromptResultLink', $src);

        self::assertStringNotContainsString('SeoArticle::query()->delete', $src);
        self::assertStringNotContainsString('$article->delete()', $src);
        self::assertStringNotContainsString('SeoProjectRun::', $src);
        self::assertStringNotContainsString('SeoProjectRunItem::query()->delete', $src);
        self::assertStringNotContainsString('SeoProjectTask::', $src);
        self::assertStringNotContainsString('SeoArticleRevision', $src);
        self::assertStringNotContainsString('PromptRunnerService', $src);
        self::assertStringNotContainsString('AiProviderRegistry', $src);
        self::assertStringNotContainsString('SeoArticle::query()', $src);
    }

    /**
     * @param  class-string  $class
     */
    private function source(string $class): string
    {
        $path = (new ReflectionClass($class))->getFileName();
        self::assertNotFalse($path);

        return (string) file_get_contents($path);
    }
}
