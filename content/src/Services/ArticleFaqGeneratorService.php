<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;


use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\Services\PromptRunnerService;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;
use Omnichannel\Addons\AiPrompt\Services\ArticleFaqPromptVariablesService;
use Omnichannel\Addons\AiPrompt\Services\PromptResultLinkService;
use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\WordPress\Services\ArticleFaqWordPressRestoreService;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookCallerBridge;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookExecutionInput;

/**
 * Sinh FAQ bằng prompt (renew_faq_prompt_id) theo contract JSON có cấu trúc.
 */
final class ArticleFaqGeneratorService
{
    public function __construct(
        private readonly SeoCreateArticleSettingsService $workflowSettings,
        private readonly PromptRunnerService $promptRunner,
        private readonly ArticleContentFaqService $contentFaq,
        private readonly ArticleFaqEditorService $faqEditor,
        private readonly ArticleFaqExtractDebugService $extractDebug,
        private readonly ArticleFaqPromptVariablesService $faqPromptVariables,
        private readonly PromptResultLinkService $promptResultLinks,
        private readonly PromptHookCallerBridge $promptHookBridge,
        private readonly ArticleFaqResultNormalizer $resultNormalizer,
    ) {
    }

    /**
     * AI FAQ preview only — no seo_faqs write, no body inject.
     *
     * @return array{faq_count: int, faqs: list<array<string, mixed>>, preview: true}
     */
    public function generatePreview(SeoArticle $article, string $editorHtml = ''): array
    {
        $faqs = $this->runFaqGeneration($article, $editorHtml);

        return [
            'faq_count' => count($faqs),
            'faqs' => $faqs,
            'preview' => true,
        ];
    }

    /**
     * @return array{
     *     faq_count: int,
     *     faqs: list<array<string, mixed>>,
     *     editor_html: string,
     * }
     */
    /**
     * @param  \App\Models\User|null  $editorUser  Owning editor session user when called from Article Editor
     */
    public function generate(
        SeoArticle $article,
        string $editorHtml = '',
        ?\App\Models\User $editorUser = null,
        ?string $editorSessionId = null,
        int|string|null $expectedDocumentVersion = null,
    ): array {
        $faqs = $this->runFaqGeneration($article, $editorHtml);

        // Domain persist — ngoài Hook runtime (caller responsibility).
        $this->faqEditor->saveFromEditor($article, $faqs);
        $this->extractDebug->clear($article);

        $baseHtml = trim($editorHtml);
        if ($baseHtml === '') {
            $baseHtml = trim((string) ($article->body ?? ''));
        }

        if ($baseHtml !== '') {
            app(ArticleFaqWordPressRestoreService::class)->persistWordPressSourceSnapshot($article, $baseHtml);
        }

        $newHtml = $this->contentFaq->injectFaqPlaceholderInEditorHtml($baseHtml);
        $this->contentFaq->persistArticleBodyHtml(
            $article,
            $newHtml,
            $editorUser,
            $editorSessionId,
            $expectedDocumentVersion,
        );

        $article = $article->fresh() ?? $article;

        return [
            'faq_count' => count($faqs),
            'faqs' => $this->faqEditor->payloadForArticle($article),
            'editor_html' => $newHtml,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function runFaqGeneration(SeoArticle $article, string $editorHtml = ''): array
    {
        $prompt = $this->resolvePrompt();
        $article->loadMissing(['site', 'faqs']);

        $variables = $this->faqPromptVariables->buildForArticle($article, [
            'faq_question' => '',
            'faq_answer' => '',
            'existing_faqs' => $this->summarizeExistingFaqs($article),
        ]);
        $variables = $this->withLegacyStructuredExecutionContract($variables);

        $envelope = PromptHookExecutionInput::fromArray([
            'context' => [
                'site_id' => (int) ($article->site_id ?? 0),
                'article_id' => (int) $article->id,
                'locale' => (string) ($article->language ?? ''),
                'prompt_id' => (int) $prompt->id,
            ],
            'input' => [
                'title' => (string) ($article->title ?? ''),
                'content_excerpt' => mb_substr(trim($editorHtml), 0, 50000),
                'language' => (string) ($variables['language'] ?? ''),
            ],
            'previous_outputs' => [],
            'settings' => [],
        ]);

        /** @var list<array<string, mixed>> $faqs */
        $faqs = $this->promptHookBridge->run(
            hookKey: 'article.faq.generate',
            version: '0.1.0',
            envelope: $envelope,
            legacyExecute: function () use ($prompt, $variables, $article): array {
                try {
                    $result = $this->promptRunner->run($prompt, $variables);
                } catch (PromptRunException $exception) {
                    $this->rethrowLinkedPromptFailure($article, $prompt, $exception);
                }

                $this->linkPromptResultToArticle($article, $prompt, $result);

                return $this->normalizeAndRecordResult($result->output_text, (int) $result->getKey());
            },
            mapHookResult: function ($runtimeResult): array {
                return $this->normalizeAndRecordResult(
                    $runtimeResult->output['value'] ?? null,
                    (int) ($runtimeResult->meta['prompt_result_id'] ?? 0),
                );
            },
        );

        return $faqs;
    }

    /** @param array<string, mixed> $variables */
    private function withLegacyStructuredExecutionContract(array $variables): array
    {
        $variables['_hook_key'] = 'article.faq.generate';
        $variables['_structured_output'] = true;
        $variables['_structured_strategy'] = 'json_mode';

        return $variables;
    }

    private function rethrowLinkedPromptFailure(
        SeoArticle $article,
        SeoPrompt $prompt,
        PromptRunException $exception,
    ): never {
        $promptResultId = (int) ($exception->context['prompt_result_id'] ?? 0);
        if ($promptResultId > 0) {
            $result = PromptResult::query()->find($promptResultId);
            if ($result instanceof PromptResult) {
                $this->linkPromptResultToArticle($article, $prompt, $result);
            }
        }

        throw $exception;
    }

    private function linkPromptResultToArticle(SeoArticle $article, SeoPrompt $prompt, PromptResult $result): void
    {
        $resultId = (int) $result->getKey();
        if ($resultId <= 0) {
            return;
        }

        $this->promptResultLinks->linkPromptResult(
            promptResultId: $resultId,
            articleId: (int) $article->id,
            source: 'article_faq_generate',
            workflowStepTitle: 'Generate FAQ (AI)',
            meta: [
                'prompt_id' => (int) $prompt->id,
                'prompt_name' => (string) ($prompt->name ?? ''),
                'status' => (string) ($result->status ?? ''),
            ],
        );
    }

    private function resolvePrompt(): SeoPrompt
    {
        $promptId = $this->workflowSettings->getRenewFaqPromptId();
        if ($promptId === null) {
            throw new \InvalidArgumentException(
                'Chưa cấu hình Prompt làm mới FAQ. Vào SEO → Tùy chỉnh → Quy trình.',
            );
        }

        $prompt = SeoPrompt::query()->find($promptId);
        if ($prompt === null) {
            throw new \InvalidArgumentException('Prompt làm mới FAQ không tồn tại hoặc đã tắt.');
        }

        return $prompt;
    }

    private function summarizeExistingFaqs(SeoArticle $article): string
    {
        $lines = [];
        foreach ($article->faqs as $faq) {
            $question = trim((string) $faq->question);
            $answer = trim(strip_tags((string) $faq->answer));
            if ($question === '') {
                continue;
            }
            $lines[] = 'Q: ' . $question . ($answer !== '' ? "\nA: " . $answer : '');
        }

        return implode("\n\n", $lines);
    }

    /** @return list<array{question: string, answer: string}> */
    private function normalizeAndRecordResult(mixed $value, int $promptResultId): array
    {
        try {
            $faqs = $this->resultNormalizer->normalize($value);
        } catch (\InvalidArgumentException $exception) {
            $this->recordFaqResult($promptResultId, 'invalid', 0, $exception->getMessage());
            throw $exception;
        }

        $this->recordFaqResult($promptResultId, 'valid', count($faqs));

        return $faqs;
    }

    private function recordFaqResult(int $promptResultId, string $status, int $faqCount, ?string $error = null): void
    {
        if ($promptResultId <= 0) {
            return;
        }

        $result = PromptResult::query()->find($promptResultId);
        if (! $result instanceof PromptResult) {
            return;
        }

        $snapshot = is_array($result->input_snapshot) ? $result->input_snapshot : [];
        $snapshot['hook_key'] = 'article.faq.generate';
        $snapshot['validation_contract'] = 'article.faq.generate';
        $snapshot['validators_applied'] = ['non_empty', 'json_schema', 'faq_structure'];
        $snapshot['faq_result_status'] = match (true) {
            $status === 'valid' => 'valid',
            str_starts_with((string) $error, 'FAQ_INVALID_JSON:') => 'invalid_json',
            str_starts_with((string) $error, 'FAQ_INVALID_SCHEMA:') => 'invalid_schema',
            str_starts_with((string) $error, 'FAQ_EMPTY_RESULT:') => 'empty',
            default => 'invalid',
        };
        $snapshot['faq_count'] = $faqCount;
        if ($error !== null) {
            $snapshot['faq_validation_error'] = $error;
        }
        $payload = ['input_snapshot' => $snapshot];
        if ($status === 'invalid') {
            $payload['status'] = 'failed';
            $payload['error_message'] = $error;
        }
        $result->update($payload);
    }
}
