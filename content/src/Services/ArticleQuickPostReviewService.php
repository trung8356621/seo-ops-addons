<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;


use Omnichannel\Addons\Seo\Services\SeoAnalyzerService;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\AiPrompt\Models\SeoTask;
use Omnichannel\Addons\Content\Support\ArticlePostTypeResolver;
use Omnichannel\Addons\WordPress\Support\CommentReviewPayloadParser;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Illuminate\Database\Eloquent\Builder;

final class ArticleQuickPostReviewService
{
    public function __construct(
        private readonly SeoCreateArticleSettingsService $settings,
        private readonly TaskTestInputResolver $inputResolver,
        private readonly TaskWorkflowTestRunner $workflowRunner,
        private readonly WordPressCommentReviewService $commentReviewPublisher,
        private readonly VirtualCommentService $virtualComments,
        private readonly SeoAnalyzerService $seoAnalyzer,
        private readonly CommentReviewPayloadParser $payloadParser,
        private readonly PromptResultLinkService $promptResultLinks,
    ) {}

    /**
     * @return array{success: bool, message: string, created_count?: int}
     */
    public function runForArticle(SeoArticle $article): array
    {
        $taskId = $this->settings->getPostReviewTaskId();
        if ($taskId === null) {
            return [
                'success' => false,
                'message' => 'Chưa cấu hình workflow Đăng review. Vào SEO → Tùy chỉnh → Workflows.',
            ];
        }

        $task = SeoTask::query()->find($taskId);
        if (! $task instanceof SeoTask) {
            return [
                'success' => false,
                'message' => 'Không tìm thấy workflow Đăng review (#' . $taskId . ').',
            ];
        }

        if (! $task->is_active) {
            return [
                'success' => false,
                'message' => 'Workflow «' . $task->name . '» đang tắt.',
            ];
        }

        $title = trim((string) ($article->title ?? ''));
        if ($title === '') {
            $title = 'Article #' . (int) $article->id;
        }

        $focusKeyword = $this->seoAnalyzer->resolveFocusKeywordForArticle($article) ?? $title;

        try {
            $context = $this->inputResolver->resolve(
                (int) $article->id,
                $title,
                $focusKeyword,
                $this->siteAccessScope(),
            );
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'message' => $exception->getMessage(),
            ];
        }

        try {
            $steps = $this->workflowRunner->run($task, $context);
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'message' => $exception->getMessage(),
            ];
        }

        $failedMessage = $this->firstFailedStepMessage($steps);
        if ($failedMessage !== null) {
            return [
                'success' => false,
                'message' => $failedMessage,
            ];
        }

        $this->promptResultLinks->linkFromWorkflowSteps(
            steps: $steps,
            articleId: (int) $article->id,
            runId: 0,
            taskId: 0,
            source: 'quick_review_workflow',
        );

        $publishedByWorkflow = $this->findCompletedPostCommentReviewStep($steps);
        if ($publishedByWorkflow !== null) {
            return $this->finalizeWithWordPressSync($article->fresh() ?? $article, $publishedByWorkflow);
        }

        $aiOutput = $this->extractAiOutputFromSteps($steps);
        if ($aiOutput === '') {
            return [
                'success' => false,
                'message' => 'Workflow đã chạy nhưng không có kết quả AI để tạo review.',
            ];
        }

        $article = $article->fresh() ?? $article;

        return $this->finalizeWithWordPressSync($article, $this->publishAiOutput($article, $aiOutput));
    }

    /**
     * @param  array{success: bool, message: string, created_count?: int|null}  $result
     * @return array{success: bool, message: string, created_count?: int}
     */
    private function finalizeWithWordPressSync(SeoArticle $article, array $result): array
    {
        if (! ($result['success'] ?? false)) {
            return $result;
        }

        $count = (int) ($result['created_count'] ?? 0);

        return [
            'success' => true,
            'message' => (string) ($result['message'] ?? ''),
            'created_count' => $count,
            'review_ids' => $result['review_ids'] ?? [],
            'automation_enabled' => (bool) ($result['automation_enabled'] ?? false),
            'has_wp_post_id' => (bool) ($result['has_wp_post_id'] ?? ((int) ($article->wordpressLink?->wp_post_id ?? 0) > 0)),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     */
    private function extractAiOutputFromSteps(array $steps): string
    {
        $promptOutput = collect($steps)
            ->reverse()
            ->first(function (array $step): bool {
                if ((string) ($step['status'] ?? '') !== 'completed') {
                    return false;
                }

                if ((string) ($step['type'] ?? '') !== 'prompt') {
                    return false;
                }

                return trim((string) ($step['output'] ?? '')) !== '';
            });

        if (is_array($promptOutput)) {
            return trim((string) ($promptOutput['output'] ?? ''));
        }

        $anyOutput = collect($steps)
            ->reverse()
            ->first(function (array $step): bool {
                if ((string) ($step['status'] ?? '') !== 'completed') {
                    return false;
                }

                return trim((string) ($step['output'] ?? '')) !== '';
            });

        if (! is_array($anyOutput)) {
            return '';
        }

        return trim((string) ($anyOutput['output'] ?? ''));
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     */
    private function firstFailedStepMessage(array $steps): ?string
    {
        $failed = collect($steps)
            ->first(fn (array $step): bool => (string) ($step['status'] ?? '') === 'failed');

        if (! is_array($failed)) {
            return null;
        }

        $message = trim((string) ($failed['message'] ?? ''));

        return $message !== '' ? $message : 'Workflow có bước lỗi.';
    }

    /**
     * @return array{success: bool, message: string, created_count?: int}
     */
    private function publishAiOutput(SeoArticle $article, string $aiOutput): array
    {
        return $this->commentReviewPublisher->storeLocalFromAiOutput($article, $aiOutput);
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     * @return array{success: bool, message: string, created_count?: int}|null
     */
    private function findCompletedPostCommentReviewStep(array $steps): ?array
    {
        $step = collect($steps)
            ->reverse()
            ->first(function (array $step): bool {
                if ((string) ($step['action_type'] ?? '') !== 'post_comment_review') {
                    return false;
                }

                return in_array((string) ($step['status'] ?? ''), ['completed', 'failed'], true);
            });

        if (! is_array($step)) {
            return null;
        }

        if ((string) ($step['status'] ?? '') === 'failed') {
            return [
                'success' => false,
                'message' => trim((string) ($step['message'] ?? '')) !== ''
                    ? trim((string) $step['message'])
                    : 'Đăng review/bình luận thất bại.',
            ];
        }

        return [
            'success' => true,
            'message' => trim((string) ($step['message'] ?? '')) !== ''
                ? trim((string) $step['message'])
                : 'Đã đăng review/bình luận qua workflow.',
            'created_count' => isset($step['created_count']) ? (int) $step['created_count'] : null,
        ];
    }

    private function articleIsProduct(SeoArticle $article): bool
    {
        return ArticlePostTypeResolver::resolve($article) === 'product';
    }

    /**
     * @return callable(Builder): void
     */
    private function siteAccessScope(): callable
    {
        return function (Builder $query): void {
            SeoAccessControl::applyAccessibleSiteScope($query);
        };
    }
}
