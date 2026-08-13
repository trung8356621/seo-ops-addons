<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use Omnichannel\Addons\Content\Enums\ArticleImproveScope;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\PromptHooks\PromptHookExecutionService;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\PromptBindingResolver;
use Omnichannel\Addons\AiPrompt\Services\PromptTestPublishService;
use Omnichannel\Addons\Content\Support\ArticleImproveExecutionResult;
use Omnichannel\Addons\Content\Support\ArticleImproveInput;
use Omnichannel\Addons\Content\Support\ArticleWritingExecutionResult;

/**
 * Improve capability riêng — không Publish graph, không article.content.generate.
 */
class ArticleImproveExecutionService
{
    public const HOOK_KEY = 'article.content.improve';

    public function __construct(
        private readonly PromptBindingResolver $promptBindingResolver,
        private readonly PromptHookExecutionService $hookExecution,
        private readonly PromptTestPublishService $publisher,
    ) {}

    public function execute(ArticleImproveInput $input): ArticleImproveExecutionResult
    {
        if ($input->articleId <= 0) {
            return $this->fail('Improve cần article_id.');
        }

        $body = trim($input->bodyMarkdown);
        if ($body === '') {
            return $this->fail('Improve cần nội dung bài hiện tại.');
        }

        // Limitation: selection/section chưa có patch path an toàn — không giả vờ patch.
        if ($input->scope !== ArticleImproveScope::Article) {
            return $this->fail(
                'Improve scope «'.$input->scope->value.'» chưa hỗ trợ persist an toàn. '
                .'Hiện chỉ hỗ trợ scope=article (replace full body).',
            );
        }

        if (! $this->promptBindingResolver->isSettingsHookConfigured(self::HOOK_KEY)) {
            return $this->fail(
                'Chưa gắn Prompt Settings cho article.content.improve. '
                .'Improve không dùng quy trình Đăng bài viết / article.content.generate.',
            );
        }

        $prompt = $this->promptBindingResolver->resolveSettingsHook(self::HOOK_KEY);
        $article = SeoArticle::query()->find($input->articleId);
        if (! $article instanceof SeoArticle) {
            return $this->fail('Không tìm thấy bài #'.$input->articleId.'.');
        }

        if (! $this->passesStaleGuard($article, $input->expectedUpdatedAt)) {
            return new ArticleImproveExecutionResult(
                success: true,
                message: 'Kết quả Improve bị bỏ qua vì bài đã được sửa (ignored_stale).',
                articleId: $input->articleId,
                promptId: (int) $prompt->getKey(),
                persistStatus: ArticleWritingExecutionResult::PERSIST_IGNORED_STALE,
                historyMetadata: [
                    'hook_key' => self::HOOK_KEY,
                    'prompt_owner_type' => 'settings_binding',
                    'prompt_owner_id' => self::HOOK_KEY,
                    'prompt_id' => (int) $prompt->getKey(),
                    'persist_status' => 'ignored_stale',
                ],
            );
        }

        $instruction = trim($input->instruction);
        $runtimeInput = [
            'input' => $body,
            'improve_instruction' => $instruction,
            'rewrite_instruction' => $instruction,
            'instruction' => $instruction,
            'post_title' => $input->title,
            'focus_keyword' => $input->keyword,
        ];
        // Không truyền article_length — Improve không full generation.

        try {
            $hookResult = $this->hookExecution->execute(
                self::HOOK_KEY,
                $input->articleId,
                $runtimeInput,
                $prompt,
            );
        } catch (\Throwable $exception) {
            return $this->fail($exception->getMessage(), (int) $prompt->getKey());
        }

        $markdown = trim((string) ($hookResult->output['value'] ?? $hookResult->output['raw'] ?? ''));
        if ($markdown === '') {
            return $this->fail('Improve output trống.', (int) $prompt->getKey());
        }

        // Re-check stale trước persist (user có thể sửa trong lúc AI chạy).
        $article->refresh();
        if (! $this->passesStaleGuard($article, $input->expectedUpdatedAt)) {
            return new ArticleImproveExecutionResult(
                success: true,
                message: 'Kết quả Improve bị bỏ qua vì bài đã được sửa (ignored_stale).',
                articleId: $input->articleId,
                promptId: (int) $prompt->getKey(),
                persistStatus: ArticleWritingExecutionResult::PERSIST_IGNORED_STALE,
                historyMetadata: [
                    'hook_key' => self::HOOK_KEY,
                    'prompt_owner_type' => 'settings_binding',
                    'persist_status' => 'ignored_stale',
                ],
            );
        }

        $publish = $this->publisher->publishArticle($article, $markdown, [
            'post_title' => $input->title,
            'focus_keyword' => $input->keyword,
        ]);
        $ok = (bool) ($publish['success'] ?? false);

        return new ArticleImproveExecutionResult(
            success: $ok,
            message: (string) ($publish['message'] ?? ($ok ? 'Đã cải thiện bài.' : 'Persist thất bại.')),
            articleId: $input->articleId,
            promptId: (int) $prompt->getKey(),
            persistStatus: $ok
                ? ArticleWritingExecutionResult::PERSIST_APPLIED
                : ArticleWritingExecutionResult::PERSIST_FAILED,
            steps: [[
                'type' => 'prompt',
                'status' => $ok ? 'success' : 'failed',
                'prompt_id' => (int) $prompt->getKey(),
                'prompt_name' => (string) ($prompt->name ?? self::HOOK_KEY),
                'hook_key' => self::HOOK_KEY,
                'message' => (string) ($publish['message'] ?? ''),
            ]],
            historyMetadata: [
                'hook_key' => self::HOOK_KEY,
                'prompt_owner_type' => 'settings_binding',
                'prompt_owner_id' => self::HOOK_KEY,
                'prompt_id' => (int) $prompt->getKey(),
                'improve_instruction_present' => $instruction !== '',
                'improve_scope' => $input->scope->value,
            ],
        );
    }

    /**
     * Bridge từ TaskTestContext (CP TYPE_IMPROVE).
     *
     * @return array{success: bool, article_id: ?int, message: string, steps: list<array<string, mixed>>}
     */
    public function executeFromTaskContext(\Omnichannel\Addons\ContentProjects\Support\TaskTestContext $context): array
    {
        $article = $context->article;
        if (! $article instanceof SeoArticle) {
            return [
                'success' => false,
                'article_id' => null,
                'message' => 'Improve cần bài viết nguồn.',
                'steps' => [],
            ];
        }

        $variables = $context->variables;
        $body = trim((string) (
            $variables['post_content']
            ?? $variables['input']
            ?? ''
        ));
        $instruction = trim((string) (
            $variables['improve_instruction']
            ?? $variables['rewrite_instruction']
            ?? $variables['rewrite_notes']
            ?? $context->rewriteNotes
            ?? ''
        ));
        $scope = ArticleImproveScope::tryFromMixed($variables['improve_scope'] ?? null)
            ?? ArticleImproveScope::Article;

        $result = $this->execute(new ArticleImproveInput(
            articleId: (int) $article->getKey(),
            bodyMarkdown: $body,
            instruction: $instruction,
            title: trim((string) ($variables['post_title'] ?? $article->title ?? '')),
            keyword: trim((string) ($variables['focus_keyword'] ?? '')),
            scope: $scope,
            selectedText: isset($variables['selected_text'])
                ? trim((string) $variables['selected_text'])
                : null,
            sectionId: isset($variables['section_id'])
                ? trim((string) $variables['section_id'])
                : null,
            expectedUpdatedAt: $article->updated_at?->toIso8601String(),
        ));

        return $result->toLegacyWorkflowArray();
    }

    private function passesStaleGuard(SeoArticle $article, ?string $expectedUpdatedAt): bool
    {
        $expected = trim((string) $expectedUpdatedAt);
        if ($expected === '') {
            return true;
        }

        $current = $article->updated_at?->toIso8601String() ?? '';

        return $current === '' || $current === $expected;
    }

    private function fail(string $message, ?int $promptId = null): ArticleImproveExecutionResult
    {
        return new ArticleImproveExecutionResult(
            success: false,
            message: $message,
            promptId: $promptId,
            persistStatus: ArticleWritingExecutionResult::PERSIST_FAILED,
            historyMetadata: [
                'hook_key' => self::HOOK_KEY,
                'prompt_owner_type' => 'settings_binding',
            ],
        );
    }
}
