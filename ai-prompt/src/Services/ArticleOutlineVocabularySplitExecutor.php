<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\PromptHookFailure;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookBindingRunner;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Services\ArticleGenerationInputResolver;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;

/**
 * Split outline logical step: two provider calls (structure + vocabulary),
 * then assemble legacy marker artifact for downstream compatibility.
 *
 * Provider contract is markerless: validate direct final content.
 * Markers exist only in PHP-assembled legacy total.
 */
final class ArticleOutlineVocabularySplitExecutor
{
    public const OUTLINE_STRUCTURE_HOOK = 'article.outline.structure.generate';

    public const VOCABULARY_HOOK = 'article.vocabulary.generate';

    public const MIN_DIRECT_BODY_LENGTH = 100;

    public function __construct(
        private readonly PromptHookBindingRunner $hookBindingExecutor,
        private readonly SeoCreateArticleSettingsService $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $nodeData
     * @param  array<string, mixed>  $variables
     * @param  array<string, mixed>  $contextExtras
     * @return array{
     *   status: string,
     *   message: string,
     *   output: string,
     *   ports: array<string, string>,
     *   sections: array<string, string>,
     *   outline_result: array<string, mixed>,
     *   vocabulary_result?: array<string, mixed>|null,
     *   prompt_result_ids: list<int>,
     *   hook_key: string,
     *   hook_version: string,
     *   execution_source: string,
     *   correlation_id: string,
     *   ai_model: ?string,
     *   duration_ms: int,
     *   outline_subtask?: string,
     *   outline_ai_invoked?: bool,
     *   vocabulary_ai_invoked?: bool,
     *   outline_status?: string,
     *   vocabulary_status?: string
     * }
     */
    public function execute(
        array $nodeData,
        SeoPrompt $fallbackPrompt,
        array $variables,
        array $contextExtras,
    ): array {
        unset($fallbackPrompt);

        $outlinePrompt = $this->resolveOutlinePrompt($nodeData);
        if ($outlinePrompt === null) {
            return $this->fail(
                'Outline generation failed: chưa cấu hình Outline Prompt (outline_prompt_id hoặc binding '
                .self::OUTLINE_STRUCTURE_HOOK.'). Chạy: php artisan seo:prompt:install-split-outline-prompts',
            );
        }

        $vocabularyPrompt = $this->resolveVocabularyPrompt($nodeData);
        if ($vocabularyPrompt === null) {
            return $this->fail(
                'Vocabulary generation failed: chưa cấu hình Vocabulary Prompt (vocabulary_prompt_id hoặc binding article.vocabulary.generate).',
            );
        }

        $started = (int) round(microtime(true) * 1000);
        $outlineAiInvoked = false;
        $vocabularyAiInvoked = false;

        $reusedOutline = $this->normalizeDirectBody(
            (string) ($contextExtras['reused_outline_markdown'] ?? $nodeData['reused_outline_markdown'] ?? ''),
        );

        if ($reusedOutline !== '') {
            $outlineMarkdown = $reusedOutline;
            $outlineResult = [
                'output' => $outlineMarkdown,
                'reused' => true,
                'prompt_result_id' => $this->positiveInt($contextExtras['reused_outline_prompt_result_id'] ?? null),
                'correlation_id' => (string) ($contextExtras['reused_outline_correlation_id'] ?? ''),
                'model' => $contextExtras['reused_outline_model'] ?? null,
            ];
        } else {
            $baseContext = $this->enrichContext($contextExtras, 'outline');
            try {
                $outlineResult = $this->hookBindingExecutor->execute(
                    $outlinePrompt,
                    $variables,
                    $baseContext,
                );
                $outlineAiInvoked = true;
            } catch (PromptHookFailure $exception) {
                return $this->fail(
                    'Outline generation failed: '.$exception->getMessage(),
                    outlineResult: [
                        'error' => $exception->getMessage(),
                        'result_id' => $exception->promptResultId(),
                        'prompt_result_id' => $exception->promptResultId(),
                    ],
                    outlineAiInvoked: true,
                    durationMs: (int) round(microtime(true) * 1000) - $started,
                );
            } catch (\Throwable $exception) {
                $promptResultId = $this->exceptionPromptResultId($exception);

                return $this->fail(
                    'Outline generation failed: '.$exception->getMessage(),
                    outlineResult: [
                        'error' => $exception->getMessage(),
                        'result_id' => $promptResultId,
                        'prompt_result_id' => $promptResultId,
                    ],
                    outlineAiInvoked: true,
                    durationMs: (int) round(microtime(true) * 1000) - $started,
                );
            }

            $outlineMarkdown = $this->normalizeDirectBody((string) ($outlineResult['output'] ?? ''));
            if ($outlineMarkdown === '') {
                return $this->fail(
                    'Outline generation failed: empty output.',
                    outlineResult: $outlineResult,
                    outlineAiInvoked: true,
                    durationMs: (int) round(microtime(true) * 1000) - $started,
                );
            }

            $outlineLengthError = $this->validateDirectBody($outlineMarkdown, 'Outline');
            if ($outlineLengthError !== null) {
                return $this->fail(
                    $outlineLengthError,
                    outlineResult: $outlineResult,
                    outlineAiInvoked: true,
                    durationMs: (int) round(microtime(true) * 1000) - $started,
                );
            }
        }

        $vocabContext = $this->enrichContext($contextExtras, 'vocabulary');
        $vocabularyVariables = $this->bindVocabularyVariables($variables, $contextExtras, $outlineMarkdown);
        if (isset($vocabularyVariables['__error'])) {
            return $this->fail(
                (string) $vocabularyVariables['__error'],
                outlineResult: $outlineResult,
                sections: ['outline' => $outlineMarkdown],
                outlineSubtask: 'vocabulary_failed',
                outlineAiInvoked: $outlineAiInvoked,
                durationMs: (int) round(microtime(true) * 1000) - $started,
            );
        }

        try {
            $vocabularyResult = $this->hookBindingExecutor->execute(
                $vocabularyPrompt,
                $vocabularyVariables,
                $vocabContext,
            );
            $vocabularyAiInvoked = true;
        } catch (PromptHookFailure $exception) {
            return $this->fail(
                'Vocabulary generation failed: '.$exception->getMessage(),
                outlineResult: $outlineResult,
                vocabularyResult: [
                    'error' => $exception->getMessage(),
                    'result_id' => $exception->promptResultId(),
                    'prompt_result_id' => $exception->promptResultId(),
                ],
                sections: ['outline' => $outlineMarkdown],
                outlineSubtask: 'vocabulary_failed',
                outlineAiInvoked: $outlineAiInvoked,
                vocabularyAiInvoked: true,
                durationMs: (int) round(microtime(true) * 1000) - $started,
            );
        } catch (\Throwable $exception) {
            $promptResultId = $this->exceptionPromptResultId($exception);

            return $this->fail(
                'Vocabulary generation failed: '.$exception->getMessage(),
                outlineResult: $outlineResult,
                vocabularyResult: [
                    'error' => $exception->getMessage(),
                    'result_id' => $promptResultId,
                    'prompt_result_id' => $promptResultId,
                ],
                sections: ['outline' => $outlineMarkdown],
                outlineSubtask: 'vocabulary_failed',
                outlineAiInvoked: $outlineAiInvoked,
                vocabularyAiInvoked: true,
                durationMs: (int) round(microtime(true) * 1000) - $started,
            );
        }

        $vocabularyMarkdown = $this->normalizeDirectBody((string) ($vocabularyResult['output'] ?? ''));
        if ($vocabularyMarkdown === '') {
            return $this->fail(
                'Vocabulary generation failed: empty output.',
                outlineResult: $outlineResult,
                vocabularyResult: $vocabularyResult,
                sections: ['outline' => $outlineMarkdown],
                outlineSubtask: 'vocabulary_failed',
                outlineAiInvoked: $outlineAiInvoked,
                vocabularyAiInvoked: true,
                durationMs: (int) round(microtime(true) * 1000) - $started,
            );
        }

        $vocabLengthError = $this->validateDirectBody($vocabularyMarkdown, 'Vocabulary');
        if ($vocabLengthError !== null) {
            return $this->fail(
                $vocabLengthError,
                outlineResult: $outlineResult,
                vocabularyResult: $vocabularyResult,
                sections: ['outline' => $outlineMarkdown],
                outlineSubtask: 'vocabulary_failed',
                outlineAiInvoked: $outlineAiInvoked,
                vocabularyAiInvoked: true,
                durationMs: (int) round(microtime(true) * 1000) - $started,
            );
        }

        $ports = $this->assemblePorts($outlineMarkdown, $vocabularyMarkdown);
        $durationMs = (int) round(microtime(true) * 1000) - $started;
        $resultIds = array_values(array_filter([
            isset($outlineResult['prompt_result_id']) ? (int) $outlineResult['prompt_result_id'] : null,
            isset($vocabularyResult['prompt_result_id']) ? (int) $vocabularyResult['prompt_result_id'] : null,
        ], static fn (?int $id): bool => $id !== null && $id > 0));

        return [
            'status' => 'completed',
            'message' => 'Split outline completed (structure + vocabulary).',
            'output' => $ports['total'],
            'ports' => $ports,
            'sections' => [
                'outline' => $outlineMarkdown,
                'vocabulary' => $vocabularyMarkdown,
            ],
            'outline_result' => $outlineResult,
            'vocabulary_result' => $vocabularyResult,
            'prompt_result_ids' => $resultIds,
            'hook_key' => self::OUTLINE_STRUCTURE_HOOK,
            'hook_version' => '0.1.0',
            'execution_source' => 'split_outline_vocabulary',
            'correlation_id' => (string) ($outlineResult['correlation_id'] ?? $vocabularyResult['correlation_id'] ?? ''),
            'ai_model' => $vocabularyResult['model'] ?? $outlineResult['model'] ?? null,
            'duration_ms' => $durationMs,
            'outline_ai_invoked' => $outlineAiInvoked,
            'vocabulary_ai_invoked' => $vocabularyAiInvoked,
            'outline_status' => 'completed',
            'vocabulary_status' => 'completed',
        ];
    }

    /**
     * Strip accidental marker wrappers; never require markers for validation.
     */
    public function normalizeDirectBody(string $raw): string
    {
        $text = trim($raw);
        if ($text === '') {
            return '';
        }

        $pairs = [
            [ArticleGenerationInputResolver::OUTLINE_START, ArticleGenerationInputResolver::OUTLINE_END],
            [ArticleGenerationInputResolver::VOCABULARY_START, ArticleGenerationInputResolver::VOCABULARY_END],
        ];

        foreach ($pairs as [$start, $end]) {
            if (! str_starts_with($text, $start)) {
                continue;
            }
            $endPos = strrpos($text, $end);
            if ($endPos === false) {
                continue;
            }
            $inner = trim(substr($text, strlen($start), $endPos - strlen($start)));
            if ($inner !== '') {
                return $inner;
            }
        }

        $text = (string) preg_replace('/^\s*\[START_TASK_[^\]]+\]\s*/u', '', $text);
        $text = (string) preg_replace('/\s*\[END_TASK_[^\]]+\]\s*$/u', '', $text);

        return trim($text);
    }

    /**
     * @return array<string, string>
     */
    public function assemblePorts(string $outlineMarkdown, string $vocabularyMarkdown): array
    {
        $outlineBody = trim($outlineMarkdown);
        $vocabBody = trim($vocabularyMarkdown);

        $task1 = ArticleGenerationInputResolver::OUTLINE_START."\n"
            .$outlineBody."\n"
            .ArticleGenerationInputResolver::OUTLINE_END;
        $task2 = ArticleGenerationInputResolver::VOCABULARY_START."\n"
            .$vocabBody."\n"
            .ArticleGenerationInputResolver::VOCABULARY_END;

        return [
            'task_1_outline' => $outlineBody,
            'task_2_vocabulary' => $vocabBody,
            'total' => $task1."\n\n".$task2,
        ];
    }

    /**
     * @param  array<string, mixed>  $nodeData
     */
    protected function resolveOutlinePrompt(array $nodeData): ?SeoPrompt
    {
        if (($nodeData['outline_prompt'] ?? null) instanceof SeoPrompt) {
            return $nodeData['outline_prompt'];
        }

        $fromNode = $this->loadPrompt($nodeData['outline_prompt_id'] ?? null);
        if ($fromNode !== null) {
            return $fromNode;
        }

        return $this->loadPrompt($this->settings->getBoundPromptId(self::OUTLINE_STRUCTURE_HOOK));
    }

    /**
     * @param  array<string, mixed>  $nodeData
     */
    protected function resolveVocabularyPrompt(array $nodeData): ?SeoPrompt
    {
        if (($nodeData['vocabulary_prompt'] ?? null) instanceof SeoPrompt) {
            return $nodeData['vocabulary_prompt'];
        }

        $fromNode = $this->loadPrompt($nodeData['vocabulary_prompt_id'] ?? null);
        if ($fromNode !== null) {
            return $fromNode;
        }

        return $this->loadPrompt($this->settings->getBoundPromptId(self::VOCABULARY_HOOK));
    }

    private function loadPrompt(mixed $promptId): ?SeoPrompt
    {
        $id = $this->positiveInt($promptId);
        if ($id === null) {
            return null;
        }

        $prompt = SeoPrompt::query()->find($id);

        return $prompt instanceof SeoPrompt ? $prompt : null;
    }

    /**
     * Canonical Vocabulary inputs: {{input}} subject + outline from structure step.
     * Does not depend on prior Outline conversation history.
     *
     * @param  array<string, mixed>  $variables
     * @param  array<string, mixed>  $contextExtras
     * @return array<string, mixed>
     */
    public function bindVocabularyVariables(array $variables, array $contextExtras, string $outlineMarkdown): array
    {
        $outlineMarkdown = trim($outlineMarkdown);
        if ($outlineMarkdown === '') {
            return ['__error' => 'Vocabulary generation failed: missing required outline.'];
        }

        $input = trim((string) ($variables['input'] ?? ''));
        if ($input === '') {
            $input = trim((string) ($variables['focus_keyword'] ?? $variables['keyword'] ?? ''));
        }
        if ($input === '') {
            $input = trim((string) ($variables['post_title'] ?? $variables['title'] ?? ''));
        }
        if ($input === '') {
            $articleId = (int) ($contextExtras['article_id'] ?? 0);
            if ($articleId > 0) {
                $fromArticle = SeoArticle::query()->whereKey($articleId)->value('title');
                $input = trim((string) $fromArticle);
            }
        }

        if ($input === '') {
            return ['__error' => 'Vocabulary generation failed: missing required input.'];
        }

        $out = $variables;
        $out['input'] = $input;
        $out['outline'] = $outlineMarkdown;

        $postTitle = trim((string) ($out['post_title'] ?? $out['title'] ?? ''));
        if ($postTitle === '') {
            $out['post_title'] = $input;
            $out['title'] = $input;
        }

        $focus = trim((string) ($out['focus_keyword'] ?? $out['keyword'] ?? ''));
        if ($focus !== '') {
            $out['focus_keyword'] = $focus;
            $out['keyword'] = $focus;
        }

        if (trim((string) ($out['language'] ?? '')) === '') {
            $out['language'] = 'Tiếng Việt';
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $contextExtras
     * @return array<string, mixed>
     */
    private function enrichContext(array $contextExtras, string $subtask): array
    {
        $context = $contextExtras;
        $context['outline_subtask'] = $subtask;

        return $context;
    }

    private function validateDirectBody(string $body, string $label): ?string
    {
        $len = mb_strlen($body);
        if ($len < self::MIN_DIRECT_BODY_LENGTH) {
            return "{$label} generation failed: output shorter than minimum_length ({$len} chars < ".self::MIN_DIRECT_BODY_LENGTH.').';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $outlineResult
     * @param  array<string, mixed>|null  $vocabularyResult
     * @param  array<string, string>  $sections
     * @return array<string, mixed>
     */
    private function fail(
        string $message,
        array $outlineResult = [],
        ?array $vocabularyResult = null,
        array $sections = [],
        ?string $outlineSubtask = null,
        bool $outlineAiInvoked = false,
        bool $vocabularyAiInvoked = false,
        int $durationMs = 0,
    ): array {
        $resultIds = array_values(array_filter([
            isset($outlineResult['prompt_result_id']) ? (int) $outlineResult['prompt_result_id'] : null,
            isset($vocabularyResult['prompt_result_id']) ? (int) $vocabularyResult['prompt_result_id'] : null,
        ], static fn (?int $id): bool => $id !== null && $id > 0));

        $hasOutline = trim((string) ($sections['outline'] ?? $outlineResult['output'] ?? '')) !== ''
            && ! isset($outlineResult['error']);
        $subtask = $outlineSubtask;
        if ($subtask === null) {
            $subtask = ($vocabularyResult !== null || str_starts_with($message, 'Vocabulary'))
                ? 'vocabulary_failed'
                : 'outline_failed';
        }

        return [
            'status' => 'failed',
            'message' => $message,
            'output' => '',
            'ports' => [],
            'sections' => $sections,
            'outline_result' => $outlineResult,
            'vocabulary_result' => $vocabularyResult,
            'prompt_result_ids' => $resultIds,
            'hook_key' => self::OUTLINE_STRUCTURE_HOOK,
            'hook_version' => '0.1.0',
            'execution_source' => 'split_outline_vocabulary',
            'correlation_id' => (string) ($outlineResult['correlation_id'] ?? ''),
            'ai_model' => $outlineResult['model'] ?? null,
            'duration_ms' => $durationMs,
            'outline_subtask' => $subtask,
            'outline_ai_invoked' => $outlineAiInvoked,
            'vocabulary_ai_invoked' => $vocabularyAiInvoked,
            'outline_status' => $hasOutline ? 'completed' : 'failed',
            'vocabulary_status' => 'failed',
        ];
    }

    private function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    private function exceptionPromptResultId(\Throwable $exception): ?int
    {
        if ($exception instanceof \Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException) {
            return $this->positiveInt($exception->context['prompt_result_id'] ?? null);
        }

        if ($exception instanceof PromptHookFailure) {
            return $this->positiveInt($exception->promptResultId());
        }

        return null;
    }
}
