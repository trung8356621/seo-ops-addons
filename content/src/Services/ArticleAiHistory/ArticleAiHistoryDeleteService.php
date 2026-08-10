<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services\ArticleAiHistory;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Models\SeoArticleAiHistoryTombstone;
use Omnichannel\Addons\AiPrompt\Models\SeoPromptResult;
use Omnichannel\Addons\AiPrompt\Models\SeoPromptResultLink;

/**
 * Xoá (tombstone) artifact khỏi Article AI History — không bao giờ xoá article, run,
 * run_item, project task, revision. Chỉ unlink PromptResult khỏi article, và chỉ hard
 * clear output_text/compiled_prompt của PromptResult khi không còn link/ownership nào khác.
 */
final class ArticleAiHistoryDeleteService
{
    public function __construct(
        private readonly ArticleAiHistoryListService $listService,
    ) {}

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
        return $this->deleteMany($article, [$artifactRef], $accessibleProjectIds, $userId, $confirmPreviouslyApplied, $reason);
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
        return $this->deleteMany($article, $artifactRefs, $accessibleProjectIds, $userId, $confirmPreviouslyApplied, $reason);
    }

    /**
     * @param  list<string>  $artifactRefs
     * @param  list<int>  $accessibleProjectIds
     */
    private function deleteMany(
        SeoArticle $article,
        array $artifactRefs,
        array $accessibleProjectIds,
        int $userId,
        bool $confirmPreviouslyApplied,
        ?string $reason,
    ): ArticleAiHistoryActionResult {
        $articleId = (int) $article->getKey();

        $artifactRefs = array_values(array_unique(array_filter(
            array_map(static fn (mixed $ref): string => trim((string) $ref), $artifactRefs),
            static fn (string $ref): bool => $ref !== '',
        )));

        if ($artifactRefs === []) {
            return ArticleAiHistoryActionResult::fail(
                'validation_failed',
                'Danh sách nội dung AI cần xoá đang rỗng.',
                $articleId,
            );
        }

        $owned = [];
        foreach ($artifactRefs as $ref) {
            $artifact = $this->listService->resolveOwnedArtifact($article, $ref, $accessibleProjectIds);
            if ($artifact === null) {
                return ArticleAiHistoryActionResult::fail(
                    'artifact_not_found',
                    "Không tìm thấy nội dung AI: {$ref}.",
                    $articleId,
                    ['artifact_id' => $ref],
                );
            }
            $owned[$ref] = $artifact;
        }

        $appliedRefs = array_values(array_filter(
            $artifactRefs,
            static fn (string $ref): bool => (int) ($owned[$ref]['apply_count'] ?? 0) > 0,
        ));

        if ($appliedRefs !== [] && ! $confirmPreviouslyApplied) {
            return ArticleAiHistoryActionResult::fail(
                'requires_apply_confirm',
                'Nội dung AI này đã từng được áp dụng vào bài viết. Xác nhận để xoá khỏi lịch sử.',
                $articleId,
                ['applied_refs' => $appliedRefs],
            );
        }

        $deletedRefs = [];
        foreach ($artifactRefs as $ref) {
            $artifact = $owned[$ref];
            if ((bool) ($artifact['is_deleted'] ?? false)) {
                continue;
            }

            $this->tombstone($articleId, $ref, $artifact, $userId, $reason);
            $this->unlinkPromptResult($articleId, $artifact);
            $deletedRefs[] = $ref;
        }

        if ($deletedRefs === []) {
            return ArticleAiHistoryActionResult::fail(
                'already_deleted',
                'Nội dung AI đã được xoá khỏi lịch sử trước đó.',
                $articleId,
                ['artifact_ids' => $artifactRefs],
            );
        }

        return ArticleAiHistoryActionResult::ok(
            'artifacts_deleted',
            sprintf('Đã xoá %d nội dung AI khỏi lịch sử.', count($deletedRefs)),
            $articleId,
            [
                'article_id' => $articleId,
                'action' => count($deletedRefs) > 1 ? 'bulk_delete' : 'delete',
                'deleted_refs' => $deletedRefs,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $artifact
     */
    private function tombstone(int $articleId, string $artifactRef, array $artifact, int $userId, ?string $reason): void
    {
        SeoArticleAiHistoryTombstone::query()->updateOrCreate(
            [
                'article_id' => $articleId,
                'artifact_ref' => $artifactRef,
            ],
            [
                'prompt_result_id' => $artifact['result_id'] ?? null,
                'artifact_type' => $artifact['artifact_type'] ?? null,
                'run_id' => $artifact['run_id'] ?? null,
                'run_item_id' => $artifact['run_item_id'] ?? null,
                'attempt' => $artifact['attempt'] ?? null,
                'deleted_by' => $userId,
                'deleted_at' => now(),
                'deletion_reason' => $reason,
                'meta' => [
                    'apply_count' => $artifact['apply_count'] ?? 0,
                    'classification' => $artifact['classification'] ?? null,
                ],
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $artifact
     */
    private function unlinkPromptResult(int $articleId, array $artifact): void
    {
        $promptResultId = (int) ($artifact['result_id'] ?? 0);
        if ($promptResultId <= 0) {
            return;
        }

        SeoPromptResultLink::query()
            ->where('article_id', $articleId)
            ->where('prompt_result_id', $promptResultId)
            ->delete();

        $stillLinked = SeoPromptResultLink::query()
            ->where('prompt_result_id', $promptResultId)
            ->exists();
        if ($stillLinked) {
            return;
        }

        // articles.prompt_result_id is legacy — may be absent on current omi_seo_ai schema.
        // Shared ownership is enforced via seo_prompt_result_links only.

        $result = SeoPromptResult::query()->find($promptResultId);
        if (! $result instanceof SeoPromptResult) {
            return;
        }

        $snapshot = is_array($result->input_snapshot) ? $result->input_snapshot : [];
        unset($snapshot['compiled_prompt']);

        $result->output_text = null;
        $result->input_snapshot = $snapshot;
        $result->save();
    }
}
