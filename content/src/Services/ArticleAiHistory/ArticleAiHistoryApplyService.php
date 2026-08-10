<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services\ArticleAiHistory;

use Omnichannel\Addons\ContentProjects\Enums\WorkflowArtifactType;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Models\SeoArticleAiHistoryApply;
use Omnichannel\Addons\Content\Services\ArticleEditor\ArticleEditorSessionException;
use Omnichannel\Addons\Content\Services\ArticleEditor\ArticleEditorSessionService;
use Omnichannel\Addons\Content\Services\ArticleOutlineResolver;
use Illuminate\Support\Str;

/**
 * Apply artifact AI (outline/content) vào bản nháp editor — không viết trực tiếp vào
 * body/outline chính thức, không gọi AI, không đổi trạng thái workflow/generation.
 *
 * Rule bắt buộc:
 * - Chỉ apply artifact đã được {@see ArticleAiHistoryListService} phân loại `can_apply_*`.
 * - Nếu đã có pending draft khác và caller không xác nhận `confirmDirty` → fail
 *   `requires_dirty_confirm` (không âm thầm ghi đè).
 * - Không apply artifact đã bị tombstone (đã xoá khỏi lịch sử).
 */
final class ArticleAiHistoryApplyService
{
    public function __construct(
        private readonly ArticleAiHistoryListService $listService,
        private readonly ArticleAiHistoryPendingDraftStore $draftStore,
        private readonly ArticleOutlineResolver $outlineResolver,
    ) {}

    /**
     * @param  list<int>  $accessibleProjectIds
     */
    public function preview(SeoArticle $article, string $artifactRef, array $accessibleProjectIds): ArticleAiHistoryActionResult
    {
        $articleId = (int) $article->getKey();

        $artifact = $this->listService->resolveOwnedArtifact($article, $artifactRef, $accessibleProjectIds);
        if ($artifact === null) {
            return ArticleAiHistoryActionResult::fail(
                'artifact_not_found',
                'Không tìm thấy nội dung AI này trong lịch sử bài viết.',
                $articleId,
            );
        }

        if ((bool) ($artifact['is_deleted'] ?? false)) {
            return ArticleAiHistoryActionResult::fail(
                'artifact_deleted',
                'Nội dung này đã bị xoá khỏi lịch sử.',
                $articleId,
                ['artifact_id' => $artifactRef],
            );
        }

        $payload = (string) ($artifact['normalized_artifact'] ?? '');
        $summary = $this->buildPreviewSummary($payload, $artifact['artifact_type'] ?? null);

        return ArticleAiHistoryActionResult::ok(
            'preview_ready',
            'Xem trước nội dung AI.',
            $articleId,
            [
                'artifact_id' => $artifactRef,
                'artifact_type' => $artifact['artifact_type'] ?? null,
                'run_id' => $artifact['run_id'] ?? null,
                'attempt' => $artifact['attempt'] ?? null,
                'article_id' => $articleId,
                'created_at' => $artifact['ran_at'] ?? null,
                'section_count' => $summary['section_count'],
                'word_count' => $summary['word_count'],
                'preview_text' => $summary['preview_text'],
            ],
        );
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
        return $this->applyTarget($article, $artifactRef, $accessibleProjectIds, $userId, 'outline', $confirmDirty);
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
        return $this->applyTarget($article, $artifactRef, $accessibleProjectIds, $userId, 'content', $confirmDirty);
    }

    public function undoPending(SeoArticle $article, int $userId): ArticleAiHistoryActionResult
    {
        $articleId = (int) $article->getKey();
        $pending = $this->draftStore->get($article);

        if ($pending === null) {
            return ArticleAiHistoryActionResult::fail(
                'no_pending_draft',
                'Không có bản nháp áp dụng AI để hoàn tác.',
                $articleId,
                ['draft_dirty' => false],
            );
        }

        $this->draftStore->clear($article);

        return ArticleAiHistoryActionResult::ok(
            'pending_draft_cleared',
            'Đã hoàn tác bản nháp áp dụng AI.',
            $articleId,
            [
                'artifact_id' => $pending['artifact_ref'] ?? null,
                'article_id' => $articleId,
                'action' => 'undo_pending',
                'applied_target' => $pending['target'] ?? null,
                'draft_dirty' => false,
            ],
        );
    }

    /**
     * Đánh dấu apply đã được lưu vào bài viết (editor tự lưu body/outline chính thức
     * ở nơi khác) — KHÔNG đổi trạng thái generation/run/workflow ở đây.
     */
    public function commitPendingOnSave(SeoArticle $article, int $userId): ArticleAiHistoryActionResult
    {
        $articleId = (int) $article->getKey();
        $pending = $this->draftStore->get($article);

        if ($pending === null) {
            return ArticleAiHistoryActionResult::ok(
                'no_pending_draft',
                'Không có bản nháp AI cần lưu.',
                $articleId,
                ['draft_dirty' => false],
            );
        }

        $artifactRef = trim((string) ($pending['artifact_ref'] ?? ''));
        $target = trim((string) ($pending['target'] ?? ''));
        $payload = trim((string) ($pending['payload'] ?? ''));

        // Outline apply: editor save thường không ghi meta dàn ý — commit tại đây.
        // Content apply: body đã được editor ghi trước khi gọi commit.
        if ($target === 'outline' && $payload !== '') {
            $persisted = $this->outlineResolver->persist($article, $payload);
            if (! ($persisted['ok'] ?? false)) {
                return ArticleAiHistoryActionResult::fail(
                    'outline_persist_failed',
                    (string) ($persisted['message'] ?? 'Không lưu được dàn ý từ bản nháp AI.'),
                    $articleId,
                    [
                        'artifact_id' => $artifactRef !== '' ? $artifactRef : null,
                        'draft_dirty' => true,
                    ],
                );
            }
        }

        if ($artifactRef !== '') {
            $applyRow = SeoArticleAiHistoryApply::query()
                ->where('article_id', $articleId)
                ->where('artifact_ref', $artifactRef)
                ->where('committed', false)
                ->orderByDesc('id')
                ->first();

            if ($applyRow instanceof SeoArticleAiHistoryApply) {
                $existingProvenance = is_array($applyRow->provenance) ? $applyRow->provenance : [];
                $applyRow->committed = true;
                $applyRow->provenance = array_merge(
                    $existingProvenance,
                    is_array($pending['provenance'] ?? null) ? $pending['provenance'] : [],
                    [
                        'committed_by' => $userId,
                        'committed_at' => now()->toIso8601String(),
                        'apply_mode' => 'manual_debug_apply',
                    ],
                );
                $applyRow->save();
            }
        }

        $this->draftStore->clear($article);

        return ArticleAiHistoryActionResult::ok(
            'pending_draft_committed',
            'Đã lưu nội dung AI vào bài viết.',
            $articleId,
            [
                'artifact_id' => $artifactRef !== '' ? $artifactRef : null,
                'article_id' => $articleId,
                'action' => 'commit_pending',
                'applied_target' => $target !== '' ? $target : null,
                'draft_dirty' => false,
                'provenance' => $pending['provenance'] ?? null,
            ],
        );
    }

    /**
     * @param  list<int>  $accessibleProjectIds
     */
    private function applyTarget(
        SeoArticle $article,
        string $artifactRef,
        array $accessibleProjectIds,
        int $userId,
        string $target,
        bool $confirmDirty,
    ): ArticleAiHistoryActionResult {
        $articleId = (int) $article->getKey();

        try {
            app(ArticleEditorSessionService::class)
                ->assertNoActiveEditorSession($article, 'ai_apply_'.$target);
        } catch (ArticleEditorSessionException $exception) {
            return ArticleAiHistoryActionResult::fail(
                $exception->errorCode,
                $exception->getMessage(),
                $articleId,
                [
                    'lock' => $exception->context['lock'] ?? null,
                    'operation' => 'ai_apply_'.$target,
                ],
            );
        }

        $artifact = $this->listService->resolveOwnedArtifact($article, $artifactRef, $accessibleProjectIds);
        if ($artifact === null) {
            return ArticleAiHistoryActionResult::fail(
                'artifact_not_found',
                'Không tìm thấy nội dung AI này trong lịch sử bài viết.',
                $articleId,
            );
        }

        if ((bool) ($artifact['is_deleted'] ?? false)) {
            return ArticleAiHistoryActionResult::fail(
                'artifact_deleted',
                'Nội dung này đã bị xoá khỏi lịch sử, không thể áp dụng.',
                $articleId,
                ['artifact_id' => $artifactRef],
            );
        }

        $expectedType = $target === 'outline'
            ? WorkflowArtifactType::ArticleOutline->value
            : WorkflowArtifactType::ArticleContent->value;
        $canApplyKey = $target === 'outline' ? 'can_apply_outline' : 'can_apply_content';

        if (($artifact['artifact_type'] ?? null) !== $expectedType || ! (bool) ($artifact[$canApplyKey] ?? false)) {
            return ArticleAiHistoryActionResult::fail(
                'artifact_type_mismatch',
                $target === 'outline'
                    ? 'Nội dung này không hợp lệ để áp dụng làm dàn ý.'
                    : 'Nội dung này không hợp lệ để áp dụng làm nội dung bài viết.',
                $articleId,
                ['artifact_id' => $artifactRef],
            );
        }

        $existingPending = $this->draftStore->get($article);
        if ($existingPending !== null && ! $confirmDirty) {
            return ArticleAiHistoryActionResult::fail(
                'requires_dirty_confirm',
                'Đang có bản nháp áp dụng AI chưa lưu. Xác nhận để ghi đè bản nháp này.',
                $articleId,
                [
                    'artifact_id' => $artifactRef,
                    'pending_draft' => $existingPending,
                    'draft_dirty' => true,
                ],
            );
        }

        $payload = (string) ($artifact['normalized_artifact'] ?? '');
        if (trim($payload) === '') {
            return ArticleAiHistoryActionResult::fail(
                'artifact_payload_empty',
                'Nội dung AI rỗng, không thể áp dụng.',
                $articleId,
                ['artifact_id' => $artifactRef],
            );
        }

        $previous = $this->draftStore->snapshotPrevious($article, $target);

        $provenance = [
            'artifact_ref' => $artifactRef,
            'artifact_type' => $expectedType,
            'run_id' => $artifact['run_id'] ?? null,
            'run_item_id' => $artifact['run_item_id'] ?? null,
            'attempt' => $artifact['attempt'] ?? null,
            'classification' => $artifact['classification'] ?? null,
            'hook_key' => $artifact['hook_key'] ?? null,
        ];

        $operationId = (string) Str::uuid();

        $draft = [
            'article_id' => $articleId,
            'artifact_ref' => $artifactRef,
            'artifact_type' => $expectedType,
            'run_id' => $artifact['run_id'] ?? null,
            'run_item_id' => $artifact['run_item_id'] ?? null,
            'attempt' => $artifact['attempt'] ?? null,
            'target' => $target,
            'apply_mode' => 'manual_debug_apply',
            'payload' => $payload,
            'previous_payload' => $target === 'outline'
                ? ($previous['previous_outline'] ?? '')
                : ($previous['previous_body'] ?? ''),
            'applied_by' => $userId,
            'applied_at' => now()->toIso8601String(),
            'provenance' => $provenance,
            'operation_id' => $operationId,
        ];

        $this->draftStore->put($article, $draft);

        SeoArticleAiHistoryApply::query()->create([
            'article_id' => $articleId,
            'artifact_ref' => $artifactRef,
            'prompt_result_id' => $artifact['result_id'] ?? null,
            'artifact_type' => $expectedType,
            'run_id' => $artifact['run_id'] ?? null,
            'run_item_id' => $artifact['run_item_id'] ?? null,
            'attempt' => $artifact['attempt'] ?? null,
            'applied_by' => $userId,
            'applied_at' => now(),
            'target' => $target,
            'apply_mode' => 'manual_debug_apply',
            'committed' => false,
            'provenance' => $provenance,
        ]);

        return ArticleAiHistoryActionResult::ok(
            $target === 'outline' ? 'outline_apply_pending' : 'content_apply_pending',
            $target === 'outline'
                ? 'Đã áp dụng dàn ý vào bản nháp editor. Lưu bài viết để giữ thay đổi.'
                : 'Đã áp dụng nội dung vào bản nháp editor. Lưu bài viết để giữ thay đổi.',
            $articleId,
            [
                'artifact_id' => $artifactRef,
                'artifact_type' => $expectedType,
                'run_id' => $artifact['run_id'] ?? null,
                'attempt' => $artifact['attempt'] ?? null,
                'article_id' => $articleId,
                'action' => $target === 'outline' ? 'apply_outline' : 'apply_content',
                'applied_target' => $target,
                'draft_dirty' => true,
                'provenance' => $provenance,
                'operation_id' => $operationId,
                'pending_draft' => $draft,
            ],
        );
    }

    /**
     * @return array{section_count: ?int, word_count: int, preview_text: string}
     */
    private function buildPreviewSummary(string $payload, ?string $artifactType): array
    {
        if ($artifactType === WorkflowArtifactType::ArticleOutline->value) {
            $sectionCount = preg_match_all('/^#{1,6}\s+\S+/mu', $payload);
            $plain = trim(strip_tags($payload));

            return [
                'section_count' => $sectionCount !== false ? $sectionCount : 0,
                'word_count' => $plain === '' ? 0 : str_word_count($plain),
                'preview_text' => mb_substr($plain, 0, 500),
            ];
        }

        $plain = trim(strip_tags($this->sanitizeContentHtml($payload)));

        return [
            'section_count' => null,
            'word_count' => $plain === '' ? 0 : str_word_count($plain),
            'preview_text' => mb_substr($plain, 0, 500),
        ];
    }

    /**
     * Sanitize đơn giản cho preview — bóc script/iframe/style, không dùng để lưu chính thức.
     */
    private function sanitizeContentHtml(string $html): string
    {
        $html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html) ?? $html;
        $html = preg_replace('#<iframe\b[^>]*>.*?</iframe>#is', '', $html) ?? $html;
        $html = preg_replace('#<style\b[^>]*>.*?</style>#is', '', $html) ?? $html;

        return trim($html);
    }
}
