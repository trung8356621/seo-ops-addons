<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use Omnichannel\Addons\Seo\Contracts\ResolvesSettingsPromptHook;
use Omnichannel\Addons\Content\Contracts\SeoCreateArticleSettingsReader;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;
use Omnichannel\Addons\ContentProjects\Enums\WorkflowCapability;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Models\SeoTask;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookEditorCatalog;
use Omnichannel\Addons\ContentProjects\Services\WorkflowRoles\WorkflowAssignmentValidator;
use Omnichannel\Addons\ContentProjects\Services\ArticleGenerationInputResolver;

/**
 * Phase 1.0 Stable Gate — PASS / WARN / FAIL cho Article Writing contract.
 */
final class ArticleWritingStableHealthService
{
    public const STATUS_PASS = 'PASS';

    public const STATUS_WARN = 'WARN';

    public const STATUS_FAIL = 'FAIL';

    public function __construct(
        private readonly SeoCreateArticleSettingsReader $settings,
        private readonly WorkflowAssignmentValidator $assignmentValidator,
        private readonly ResolvesSettingsPromptHook $promptBindings,
        private readonly PromptHookEditorCatalog $hookCatalog,
    ) {}

    /**
     * @return array{
     *     status: string,
     *     fails: list<string>,
     *     warns: list<string>,
     *     passes: list<string>,
     *     legacy: array<string, int|string>
     * }
     */
    public function evaluate(): array
    {
        $fails = [];
        $warns = [];
        $passes = [];

        $legacy = $this->legacyInventory();

        // --- FAIL checks ---
        $publishId = $this->settings->getPublishArticleTaskId();
        if ($publishId !== null && $publishId > 0) {
            $task = SeoTask::query()->find($publishId);
            if (! $task instanceof SeoTask) {
                $fails[] = 'Publish Workflow #'.$publishId.' không tồn tại.';
            } else {
                $roleErrors = $this->assignmentValidator->validateTaskForCapability(
                    $task,
                    WorkflowCapability::PublishArticle,
                );
                foreach ($roleErrors as $err) {
                    $fails[] = $err;
                }
                if ($roleErrors === []) {
                    $passes[] = 'Publish Workflow đủ outline + content role.';
                }
            }
        } else {
            $warns[] = 'Chưa gán Publish article Workflow (optional nếu chưa dùng CP first-run).';
        }

        if ($this->runtimeStillReadsRewriteTaskId()) {
            $fails[] = 'Runtime caller còn đọc rewrite_article_task_id / getRewriteArticleTaskId.';
        } else {
            $passes[] = 'No runtime reader rewrite_article_task_id.';
        }

        if ($this->runtimeStillHasTitleHeuristic()) {
            $fails[] = 'Workflow runtime còn title/haystack heuristic.';
        } else {
            $passes[] = 'No runtime title heuristic (runner/resolver/catalog).';
        }

        if ($this->retryFallsBackToLiveNode()) {
            $fails[] = 'Retry còn fallback live contentNodeId khi thiếu snapshot.';
        } else {
            $passes[] = 'Retry snapshot strict.';
        }

        $generatePrompt = null;
        try {
            $generatePrompt = $this->promptBindings->resolveSettingsHook(
                ArticleWritingExecutionService::HOOK_KEY,
            );
        } catch (\Throwable) {
            $generatePrompt = null;
        }
        if (! $generatePrompt instanceof SeoPrompt) {
            $fails[] = 'Thiếu Settings binding article.content.generate.';
        } else {
            $passes[] = 'article.content.generate binding OK (#'.$generatePrompt->id.').';
        }

        $improvePrompt = null;
        try {
            $improvePrompt = $this->promptBindings->resolveSettingsHook(
                ArticleImproveExecutionService::HOOK_KEY,
            );
        } catch (\Throwable) {
            $improvePrompt = null;
        }
        if (! $improvePrompt instanceof SeoPrompt) {
            $fails[] = 'Thiếu Settings binding article.content.improve.';
        } else {
            $passes[] = 'article.content.improve binding OK (#'.$improvePrompt->id.').';
        }

        if ($this->hookCatalogAllowsNewRewrite()) {
            $fails[] = 'Hook selector vẫn cho tạo mới article.content.rewrite.';
        } else {
            $passes[] = 'Hook selector khóa article.content.rewrite cho Prompt mới.';
        }

        // --- WARN ---
        if ((int) ($legacy['rewrite_task_id_populated'] ?? 0) > 0) {
            $warns[] = 'DB rewrite_article_task_id còn populated (giữ để rollback).';
        }
        if ((int) ($legacy['legacy_prompt_count'] ?? 0) > 0) {
            $warns[] = 'Prompt legacy article.content.rewrite còn tồn tại: '.$legacy['legacy_prompt_count'];
        }
        if ((int) ($legacy['legacy_workflow_nodes'] ?? 0) > 0) {
            $warns[] = 'Workflow node còn Prompt hook rewrite (compat): '.$legacy['legacy_workflow_nodes'];
        }

        $status = self::STATUS_PASS;
        if ($fails !== []) {
            $status = self::STATUS_FAIL;
        } elseif ($warns !== []) {
            $status = self::STATUS_WARN;
        } else {
            $passes[] = 'Stable contract enabled.';
        }

        return [
            'status' => $status,
            'fails' => $fails,
            'warns' => $warns,
            'passes' => $passes,
            'legacy' => $legacy,
        ];
    }

    /**
     * @return array{
     *     rewrite_task_id_populated: int,
     *     legacy_prompt_count: int,
     *     legacy_workflow_nodes: int,
     *     note: string
     * }
     */
    public function legacyInventory(): array
    {
        $rewriteId = 0;
        try {
            $raw = $this->settings->getSettings()[SeoCreateArticleSettingsService::KEY_REWRITE_ARTICLE] ?? null;
            $rewriteId = (int) $raw > 0 ? 1 : 0;
        } catch (\Throwable) {
            $rewriteId = 0;
        }

        $promptCount = 0;
        $nodeCount = 0;
        try {
            if (SeoPrompt::getConnectionResolver() !== null) {
                $promptCount = (int) SeoPrompt::query()
                    ->where('hook_key', ArticleWritingLegacyRewriteAdapter::LEGACY_REWRITE_HOOK)
                    ->count();
                foreach (SeoTask::query()->orderBy('id')->get(['id', 'flow_data']) as $task) {
                    $flow = is_array($task->flow_data) ? $task->flow_data : [];
                    foreach (is_array($flow['nodes'] ?? null) ? $flow['nodes'] : [] as $node) {
                        if (! is_array($node)) {
                            continue;
                        }
                        $promptId = isset($node['data']['promptId']) ? (int) $node['data']['promptId'] : 0;
                        if ($promptId <= 0) {
                            continue;
                        }
                        $prompt = SeoPrompt::query()->find($promptId);
                        if (
                            $prompt instanceof SeoPrompt
                            && trim((string) ($prompt->hook_key ?? '')) === ArticleWritingLegacyRewriteAdapter::LEGACY_REWRITE_HOOK
                        ) {
                            $nodeCount++;
                        }
                    }
                }
            }
        } catch (\Throwable) {
            // unit / no DB
        }

        return [
            'rewrite_task_id_populated' => $rewriteId,
            'legacy_prompt_count' => $promptCount,
            'legacy_workflow_nodes' => $nodeCount,
            'note' => 'Adapter executions: xem log article_writing.legacy_adapter_used (24h/7d/30d). '
                .'DB field populated không blocking.',
        ];
    }

    public function runtimeStillReadsRewriteTaskId(): bool
    {
        $files = [
            dirname(__DIR__).'/Services/CreateArticlesFromTaskService.php',
            dirname(__DIR__).'/Services/ArticleWritingExecutionService.php',
            dirname(__DIR__).'/Services/ArticleWritingLegacyRewriteAdapter.php',
            dirname(__DIR__).'/Services/SeoProjectWorkflowStepCatalogService.php',
            dirname(__DIR__).'/Services/TaskWorkflowTestRunner.php',
        ];
        foreach ($files as $file) {
            if (! is_file($file)) {
                continue;
            }
            $src = (string) file_get_contents($file);
            if (str_contains($src, 'getRewriteArticleTaskId(')) {
                return true;
            }
        }

        return false;
    }

    public function runtimeStillHasTitleHeuristic(): bool
    {
        $patterns = [
            dirname(__DIR__).'/Services/TaskWorkflowTestRunner.php' => "str_contains(\$haystack, 'dàn ý')",
            dirname(__DIR__).'/Services/ArticleGenerationInputResolver.php' => "str_contains(\$haystack, 'dàn ý')",
            dirname(__DIR__).'/Services/SeoProjectWorkflowStepCatalogService.php' => 'str_contains($haystack',
            dirname(__DIR__).'/Services/WorkflowExistingAiOutputService.php' => "str_contains(\$name, 'dàn ý')",
        ];
        foreach ($patterns as $file => $needle) {
            if (! is_file($file)) {
                continue;
            }
            if (str_contains((string) file_get_contents($file), $needle)) {
                return true;
            }
        }

        return false;
    }

    public function retryFallsBackToLiveNode(): bool
    {
        $file = dirname(__DIR__).'/Services/ArticleWritingExecutionService.php';
        if (! is_file($file)) {
            return true;
        }
        $src = (string) file_get_contents($file);

        return (bool) preg_match(
            '/contentNodeId:\s*\$contentNodeId !== \'\' \? \$contentNodeId : \$context->contentNodeId/',
            $src,
        );
    }

    public function hookCatalogAllowsNewRewrite(): bool
    {
        return array_key_exists(
            ArticleWritingLegacyRewriteAdapter::LEGACY_REWRITE_HOOK,
            $this->hookCatalog->selectOptions(),
        );
    }
}
