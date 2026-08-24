<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services\ArticleAiHistory;

use Omnichannel\Addons\Content\Services\ArticleExecutionHistory\ArticleExecutionHistoryService;
use Omnichannel\Addons\Content\Models\SeoArticle;

/**
 * Facade cho Livewire/UI — delegate sang List/Apply/Delete service.
 * Không gọi AI, không đổi trạng thái workflow, không publish, không sync WP.
 */
final class ArticleAiHistoryApplicationService
{
    public function __construct(
        private readonly ArticleAiHistoryListService $listService,
        private readonly ArticleAiHistoryApplyService $applyService,
        private readonly ArticleAiHistoryDeleteService $deleteService,
        private readonly ArticleExecutionHistoryService $executionHistoryService,
        private readonly ArticleAiCallRawDetailService $rawDetailService,
    ) {}

    /**
     * @param  list<int>  $accessibleProjectIds
     * @param  array{type?: string, status?: string, include_deleted?: bool}  $filters
     * @return list<array<string, mixed>>
     */
    public function list(SeoArticle $article, array $accessibleProjectIds, array $filters = []): array
    {
        return $this->listService->list($article, $accessibleProjectIds, $filters);
    }

    /**
     * @param  list<int>  $accessibleProjectIds
     * @param  array{type?: string, status?: string, include_deleted?: bool}  $filters
     * @return list<array<string, mixed>>
     */
    public function listAiCalls(SeoArticle $article, array $accessibleProjectIds, array $filters = []): array
    {
        return $this->listService->listAiCalls($article, $accessibleProjectIds, $filters);
    }

    /**
     * @param  list<int>  $accessibleProjectIds
     * @return list<array<string, mixed>>
     */
    public function executionHistory(SeoArticle $article, array $accessibleProjectIds): array
    {
        return $this->executionHistoryService->build($article, $accessibleProjectIds);
    }

    /**
     * @param  list<int>  $accessibleProjectIds
     */
    public function preview(SeoArticle $article, string $artifactRef, array $accessibleProjectIds): ArticleAiHistoryActionResult
    {
        return $this->applyService->preview($article, $artifactRef, $accessibleProjectIds);
    }

    /**
     * Raw compiled prompt + output_text for View prompt / output (not normalized artifact).
     *
     * @param  list<int>  $accessibleProjectIds
     * @return array{success: bool, title?: string, prompt?: string, output?: string, meta?: string, message?: string, prompt_result_id?: int, artifact_ref?: string}
     */
    public function rawAiCallDetail(SeoArticle $article, string $artifactRef, array $accessibleProjectIds): array
    {
        return $this->rawDetailService->resolve($article, $artifactRef, $accessibleProjectIds);
    }

    /**
     * @param  list<int>  $accessibleProjectIds
     */
    public function applyOutline(
        SeoArticle $article,
        string $artifactRef,
        array $accessibleProjectIds,
        int $userId,
        bool $confirmDirty = false,
    ): ArticleAiHistoryActionResult {
        return $this->applyService->applyOutline($article, $artifactRef, $accessibleProjectIds, $userId, $confirmDirty);
    }

    /**
     * @param  list<int>  $accessibleProjectIds
     */
    public function applyContent(
        SeoArticle $article,
        string $artifactRef,
        array $accessibleProjectIds,
        int $userId,
        bool $confirmDirty = false,
    ): ArticleAiHistoryActionResult {
        return $this->applyService->applyContent($article, $artifactRef, $accessibleProjectIds, $userId, $confirmDirty);
    }

    /**
     * @param  list<int>  $accessibleProjectIds
     */
    public function delete(
        SeoArticle $article,
        string $artifactRef,
        array $accessibleProjectIds,
        int $userId,
        bool $confirmPreviouslyApplied = false,
        ?string $reason = null,
    ): ArticleAiHistoryActionResult {
        return $this->deleteService->delete($article, $artifactRef, $accessibleProjectIds, $userId, $confirmPreviouslyApplied, $reason);
    }

    /**
     * @param  list<string>  $artifactRefs
     * @param  list<int>  $accessibleProjectIds
     */
    public function bulkDelete(
        SeoArticle $article,
        array $artifactRefs,
        array $accessibleProjectIds,
        int $userId,
        bool $confirmPreviouslyApplied = false,
        ?string $reason = null,
    ): ArticleAiHistoryActionResult {
        return $this->deleteService->bulkDelete($article, $artifactRefs, $accessibleProjectIds, $userId, $confirmPreviouslyApplied, $reason);
    }

    public function undoPending(SeoArticle $article, int $userId): ArticleAiHistoryActionResult
    {
        return $this->applyService->undoPending($article, $userId);
    }

    public function commitPendingOnSave(SeoArticle $article, int $userId): ArticleAiHistoryActionResult
    {
        return $this->applyService->commitPendingOnSave($article, $userId);
    }
}
