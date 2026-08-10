<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use Omnichannel\Addons\Content\Enums\ArticleWritingExecutionMode;
use Omnichannel\Addons\AiPrompt\Enums\ArticleWritingPromptOwnerType;
use Omnichannel\Addons\Content\Enums\ArticleWritingSourceType;
use Omnichannel\Addons\Content\Support\ArticleWritingExecutionContext;
use Omnichannel\Addons\Content\Support\ArticleWritingExecutionResult;
use Omnichannel\Addons\Content\Support\ArticleWritingInput;
use Omnichannel\Addons\ContentProjects\Support\TaskTestContext;
use App\Support\RuntimeLogger;
use Illuminate\Support\Facades\Log;

/**
 * DEPRECATED COMPATIBILITY ONLY.
 *
 * Legacy rewrite → existing_article → ArticleWritingExecutionService.
 * Callers: PromptHookExplicitBindingExecutor (rewrite hook), TaskWorkflowTestRunner (rewrite hook).
 * Không resolve Prompt/Workflow/Settings, không persist, không length.
 * DB rewrite_article_task_id: deprecated_since Phase 1.0, runtime_reads=0.
 */
final class ArticleWritingLegacyRewriteAdapter
{
    public const LEGACY_REWRITE_HOOK = 'article.content.rewrite';

    public const GENERATE_HOOK = 'article.content.generate';

    public function __construct(
        private readonly ArticleWritingInputFormatter $formatter,
    ) {}

    public function canonicalizeHookKey(string $hookKey): string
    {
        return trim($hookKey) === self::LEGACY_REWRITE_HOOK
            ? self::GENERATE_HOOK
            : trim($hookKey);
    }

    public function isLegacyRewriteHook(string $hookKey): bool
    {
        return trim($hookKey) === self::LEGACY_REWRITE_HOOK;
    }

    public function defaultSourceTypeForLegacyRewrite(): ArticleWritingSourceType
    {
        return ArticleWritingSourceType::ExistingArticle;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function toExistingArticleInput(
        string $bodyMarkdown,
        string $title = '',
        string $keyword = '',
        string $description = '',
        ?int $articleId = null,
        array $metadata = [],
    ): ArticleWritingInput {
        return ArticleWritingInput::fromExistingArticleBody(
            bodyMarkdown: $bodyMarkdown,
            title: $title,
            keyword: $keyword,
            description: $description,
            articleId: $articleId,
            metadata: array_merge(['legacy_rewrite_adapter' => true], $metadata),
        );
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public function applyExistingArticleToVariables(
        string $bodyMarkdown,
        array $variables,
        string $title = '',
        string $keyword = '',
        string $description = '',
        ?int $articleId = null,
    ): array {
        return $this->formatter->applyToVariables(
            $this->toExistingArticleInput($bodyMarkdown, $title, $keyword, $description, $articleId),
            $variables,
        );
    }

    public function logLegacyAdapterUsed(
        string $caller,
        ?int $articleId = null,
        ?int $runId = null,
        string $oldHook = self::LEGACY_REWRITE_HOOK,
        string $mappedSourceType = 'existing_article',
        string $destinationCapability = self::GENERATE_HOOK,
    ): void {
        $context = [
            'caller' => $caller,
            'article_id' => $articleId,
            'run_id' => $runId,
            'old_hook' => $oldHook,
            'mapped_source_type' => $mappedSourceType,
            'destination_capability' => $destinationCapability,
        ];
        if (app()->runningInConsole()) {
            Log::info('article_writing.legacy_adapter_used', $context);

            return;
        }
        RuntimeLogger::info('article_writing.legacy_adapter_used', $context);
    }

    public function executeViaWritingService(
        ArticleWritingExecutionService $execution,
        string $bodyMarkdown,
        TaskTestContext $taskContext,
        int $siteId,
        string $title = '',
        string $keyword = '',
        string $description = '',
    ): ArticleWritingExecutionResult {
        $this->logLegacyAdapterUsed(
            caller: self::class.'::executeViaWritingService',
            articleId: $taskContext->article !== null ? (int) $taskContext->article->getKey() : null,
        );

        return $execution->execute(
            $this->toExistingArticleInput(
                bodyMarkdown: $bodyMarkdown,
                title: $title,
                keyword: $keyword,
                description: $description,
                articleId: $taskContext->article !== null ? (int) $taskContext->article->getKey() : null,
                metadata: ['legacy_caller' => self::class.'::executeViaWritingService'],
            ),
            new ArticleWritingExecutionContext(
                mode: ArticleWritingExecutionMode::DirectGenerate,
                promptOwnerType: ArticleWritingPromptOwnerType::SettingsBinding,
                siteId: $siteId,
                taskContext: $taskContext,
                expectedUpdatedAt: $taskContext->article?->updated_at?->toIso8601String(),
            ),
        );
    }
}
