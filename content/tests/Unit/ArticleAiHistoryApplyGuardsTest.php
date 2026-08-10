<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Services\ArticleAiHistory\ArticleAiHistoryApplyService;
use Omnichannel\Addons\Content\Services\ArticleAiHistory\ArticleAiHistoryDeleteService;
use Omnichannel\Addons\Content\Services\ArticleAiHistory\ArticleAiHistoryListService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Source-wiring regression lock — Article AI History apply/delete phải luôn:
 * - Fail-closed theo artifact_type article_outline/article_content.
 * - Không đổi trạng thái generation/run/workflow.
 * - Không gọi AI (PromptRunnerService / AiProviderRegistry).
 * - Apply chỉ viết vào ArticleAiHistoryPendingDraftStore (không viết body/outline chính thức).
 * - Delete không bao giờ xoá article/run/run_item/task/revision.
 */
final class ArticleAiHistoryApplyGuardsTest extends TestCase
{
    public function test_apply_service_guards_artifact_type_and_uses_pending_draft_store(): void
    {
        $src = $this->source(ArticleAiHistoryApplyService::class);

        self::assertStringContainsString('WorkflowArtifactType::ArticleOutline', $src);
        self::assertStringContainsString('WorkflowArtifactType::ArticleContent', $src);
        self::assertStringContainsString('ArticleAiHistoryPendingDraftStore', $src);
        self::assertStringContainsString('requires_dirty_confirm', $src);
        self::assertStringContainsString('artifact_type_mismatch', $src);
        self::assertStringContainsString('artifact_deleted', $src);

        // Never mutate generation/run status, never call AI.
        self::assertStringNotContainsString('PromptRunnerService', $src);
        self::assertStringNotContainsString('AiProviderRegistry', $src);
        self::assertStringNotContainsString('SeoProjectRunItem', $src);
        self::assertStringNotContainsString('SeoProjectTask', $src);
        self::assertStringNotContainsString('->status = ', $src);
        self::assertStringNotContainsString('publish', $src);
        self::assertStringNotContainsString('SyncProvider', $src);

        // Apply writes body/outline as a pending draft only — never persists to
        // the article's canonical body/outline meta directly.
        self::assertStringNotContainsString('->body =', $src);
        // Apply path uses PendingDraftStore; outline persist only on commitPendingOnSave via ArticleOutlineResolver.
        self::assertStringContainsString('ArticleOutlineResolver', $src);
        self::assertStringContainsString('commitPendingOnSave', $src);
        self::assertStringContainsString('draftStore->put', $src);
    }

    public function test_list_service_wraps_article_prompt_run_history_service(): void
    {
        $src = $this->source(ArticleAiHistoryListService::class);

        self::assertStringContainsString('ArticlePromptRunHistoryService', $src);
        self::assertStringContainsString('->build(', $src);
        self::assertStringContainsString('resolveOwnedArtifact', $src);
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
        // Legacy articles.prompt_result_id may be dropped — ownership via links only.
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
