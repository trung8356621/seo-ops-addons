<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\PromptHookFailure;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookExplicitBindingExecutor;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Services\ArticleGenerationInputResolver;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;

/**
 * Split outline logical step: two provider calls (structure + vocabulary),
 * then assemble legacy marker artifact for downstream compatibility.
 */
final class ArticleOutlineVocabularySplitExecutor
{
    public const OUTLINE_STRUCTURE_HOOK = 'article.outline.structure.generate';

    public const VOCABULARY_HOOK = 'article.vocabulary.generate';

    public function __construct(
        private readonly PromptHookExplicitBindingExecutor $hookBindingExecutor,
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
     *   vocabulary_result?: array<string, mixed>,
     *   prompt_result_ids: list<int>,
     *   hook_key: string,
     *   hook_version: string,
     *   execution_source: string,
     *   correlation_id: string,
     *   ai_model: ?string,
     *   duration_ms: int
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

        $baseContext = $this->enrichContext($contextExtras, 'outline');
        $outlineStarted = (int) round(microtime(true) * 1000);

        try {
            $outlineResult = $this->hookBindingExecutor->execute(
                $outlinePrompt,
                $variables,
                $baseContext,
            );
        } catch (PromptHookFailure $exception) {
            return $this->fail(
                'Outline generation failed: '.$exception->getMessage(),
                outlineResult: ['error' => $exception->getMessage(), 'result_id' => $exception->promptResultId()],
            );
        }

        $outlineMarkdown = trim((string) ($outlineResult['output'] ?? ''));
        if ($outlineMarkdown === '') {
            return $this->fail(
                'Outline generation failed: empty output.',
                outlineResult: $outlineResult,
            );
        }

        $vocabContext = $this->enrichContext($contextExtras, 'vocabulary');
        $vocabularyVariables = $this->bindVocabularyVariables($variables, $contextExtras, $outlineMarkdown);
        if (isset($vocabularyVariables['__error'])) {
            return $this->fail(
                (string) $vocabularyVariables['__error'],
                outlineResult: $outlineResult,
            );
        }

        try {
            $vocabularyResult = $this->hookBindingExecutor->execute(
                $vocabularyPrompt,
                $vocabularyVariables,
                $vocabContext,
            );
        } catch (PromptHookFailure $exception) {
            return $this->fail(
                'Vocabulary generation failed: '.$exception->getMessage(),
                outlineResult: $outlineResult,
                vocabularyResult: ['error' => $exception->getMessage(), 'result_id' => $exception->promptResultId()],
            );
        }

        $vocabularyMarkdown = trim((string) ($vocabularyResult['output'] ?? ''));
        if ($vocabularyMarkdown === '') {
            return $this->fail(
                'Vocabulary generation failed: empty output.',
                outlineResult: $outlineResult,
                vocabularyResult: $vocabularyResult,
            );
        }

        $ports = $this->assemblePorts($outlineMarkdown, $vocabularyMarkdown);
        $durationMs = (int) round(microtime(true) * 1000) - $outlineStarted;
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
        ];
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
    private function resolveOutlinePrompt(array $nodeData): ?SeoPrompt
    {
        $fromNode = $this->loadPrompt($nodeData['outline_prompt_id'] ?? null);
        if ($fromNode !== null) {
            return $fromNode;
        }

        return $this->loadPrompt($this->settings->getBoundPromptId(self::OUTLINE_STRUCTURE_HOOK));
    }

    /**
     * @param  array<string, mixed>  $nodeData
     */
    private function resolveVocabularyPrompt(array $nodeData): ?SeoPrompt
    {
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
     * Canonical Vocabulary inputs: current article title + outline from structure step.
     * Prompt history is never the source of post_title.
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

        $postTitle = trim((string) ($variables['post_title'] ?? $variables['title'] ?? ''));
        if ($postTitle === '') {
            $articleId = (int) ($contextExtras['article_id'] ?? 0);
            if ($articleId > 0) {
                $fromArticle = SeoArticle::query()->whereKey($articleId)->value('title');
                $postTitle = trim((string) $fromArticle);
            }
        }

        if ($postTitle === '') {
            return ['__error' => 'Vocabulary generation failed: missing required post_title.'];
        }

        $out = $variables;
        $out['post_title'] = $postTitle;
        $out['title'] = $postTitle;
        $out['outline'] = $outlineMarkdown;

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

    /**
     * @param  array<string, mixed>  $outlineResult
     * @param  array<string, mixed>|null  $vocabularyResult
     * @return array<string, mixed>
     */
    private function fail(
        string $message,
        array $outlineResult = [],
        ?array $vocabularyResult = null,
    ): array {
        $resultIds = array_values(array_filter([
            isset($outlineResult['prompt_result_id']) ? (int) $outlineResult['prompt_result_id'] : null,
            isset($vocabularyResult['prompt_result_id']) ? (int) $vocabularyResult['prompt_result_id'] : null,
        ], static fn (?int $id): bool => $id !== null && $id > 0));

        return [
            'status' => 'failed',
            'message' => $message,
            'output' => '',
            'ports' => [],
            'sections' => [],
            'outline_result' => $outlineResult,
            'vocabulary_result' => $vocabularyResult,
            'prompt_result_ids' => $resultIds,
            'hook_key' => self::OUTLINE_STRUCTURE_HOOK,
            'hook_version' => '0.1.0',
            'execution_source' => 'split_outline_vocabulary',
            'correlation_id' => (string) ($outlineResult['correlation_id'] ?? ''),
            'ai_model' => $outlineResult['model'] ?? null,
            'duration_ms' => 0,
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
}
