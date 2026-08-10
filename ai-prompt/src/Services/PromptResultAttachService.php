<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\Seo\Contracts\PromptResultAttacher;
use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use InvalidArgumentException;

/**
 * Domain attach PromptResult → article|project_task|project.
 * Used by Business Action prompt_result.attach — not by Hook Engine.
 */
final class PromptResultAttachService implements PromptResultAttacher
{
    public const TARGET_ARTICLE = 'article';

    public const TARGET_PROJECT_TASK = 'project_task';

    public const TARGET_PROJECT = 'project';

    /** @var list<string> */
    public const ALLOWED_TARGETS = [
        self::TARGET_ARTICLE,
        self::TARGET_PROJECT_TASK,
        self::TARGET_PROJECT,
    ];

    public function __construct(
        private readonly PromptResultLinkService $links,
    ) {}

    /**
     * @param  array<string, mixed>  $meta
     * @return array{attached: bool, deduplicated: bool, prompt_result_id: int, target_type: string, target_id: int}
     */
    public function attach(
        int $promptResultId,
        string $targetType,
        int $targetId,
        int $siteId,
        string $purpose = 'manual',
        array $meta = [],
    ): array {
        if ($promptResultId <= 0 || $targetId <= 0) {
            throw new InvalidArgumentException('prompt_result_id and target_id must be positive.');
        }

        $targetType = trim($targetType);
        if (! in_array($targetType, self::ALLOWED_TARGETS, true)) {
            throw new InvalidArgumentException("target_type [{$targetType}] is not allowed.");
        }

        $result = PromptResult::query()->find($promptResultId);
        if (! $result instanceof PromptResult) {
            throw new InvalidArgumentException("PromptResult [{$promptResultId}] not found.");
        }

        return match ($targetType) {
            self::TARGET_ARTICLE => $this->attachArticle($result, $targetId, $siteId, $purpose, $meta),
            self::TARGET_PROJECT_TASK => $this->attachProjectTask($result, $targetId, $siteId, $purpose, $meta),
            self::TARGET_PROJECT => $this->attachProject($result, $targetId, $siteId, $purpose, $meta),
            default => throw new InvalidArgumentException("Unsupported target_type [{$targetType}]."),
        };
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{attached: bool, deduplicated: bool, prompt_result_id: int, target_type: string, target_id: int}
     */
    private function attachArticle(
        PromptResult $result,
        int $articleId,
        int $siteId,
        string $purpose,
        array $meta,
    ): array {
        $article = SeoArticle::query()->find($articleId);
        if (! $article instanceof SeoArticle) {
            throw new InvalidArgumentException("Article [{$articleId}] not found.");
        }
        if ($siteId > 0 && (int) ($article->site_id ?? 0) !== $siteId) {
            throw new InvalidArgumentException('Article site_id mismatch (wrong context).');
        }

        $promptResultId = (int) $result->id;
        $existed = \Omnichannel\Addons\AiPrompt\Models\SeoPromptResultLink::query()
            ->where('prompt_result_id', $promptResultId)
            ->where('article_id', $articleId)
            ->where('source', $purpose)
            ->exists();

        $snapshot = is_array($result->input_snapshot) ? $result->input_snapshot : [];
        $snapshot['article_id'] = $articleId;
        if (isset($meta['hook_key'])) {
            $snapshot['hook_key'] = $meta['hook_key'];
        }
        $result->update(['input_snapshot' => $snapshot]);

        $this->links->linkPromptResult(
            promptResultId: $promptResultId,
            articleId: $articleId,
            source: $purpose !== '' ? $purpose : 'prompt_result.attach',
            workflowStepTitle: isset($meta['workflow_step_title'])
                ? (string) $meta['workflow_step_title']
                : null,
            meta: array_merge($meta, [
                'target_type' => self::TARGET_ARTICLE,
                'target_id' => $articleId,
                'site_id' => $siteId > 0 ? $siteId : (int) ($article->site_id ?? 0),
            ]),
        );

        return [
            'attached' => true,
            'deduplicated' => $existed,
            'prompt_result_id' => $promptResultId,
            'target_type' => self::TARGET_ARTICLE,
            'target_id' => $articleId,
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{attached: bool, deduplicated: bool, prompt_result_id: int, target_type: string, target_id: int}
     */
    private function attachProjectTask(
        PromptResult $result,
        int $taskId,
        int $siteId,
        string $purpose,
        array $meta,
    ): array {
        $task = SeoProjectTask::query()->find($taskId);
        if (! $task instanceof SeoProjectTask) {
            throw new InvalidArgumentException("Project task [{$taskId}] not found.");
        }
        if ($siteId > 0 && (int) ($task->site_id ?? 0) !== $siteId) {
            throw new InvalidArgumentException('Project task site_id mismatch (wrong context).');
        }

        $articleId = (int) ($task->article_id ?? 0);
        if ($articleId <= 0) {
            throw new InvalidArgumentException('Project task has no article_id to attach PromptResult.');
        }

        $out = $this->attachArticle($result, $articleId, $siteId, $purpose, array_merge($meta, [
            'project_task_id' => $taskId,
        ]));
        $out['target_type'] = self::TARGET_PROJECT_TASK;
        $out['target_id'] = $taskId;

        return $out;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{attached: bool, deduplicated: bool, prompt_result_id: int, target_type: string, target_id: int}
     */
    private function attachProject(
        PromptResult $result,
        int $projectId,
        int $siteId,
        string $purpose,
        array $meta,
    ): array {
        $project = SeoProject::query()->find($projectId);
        if (! $project instanceof SeoProject) {
            throw new InvalidArgumentException("Project [{$projectId}] not found.");
        }
        if ($siteId > 0 && (int) ($project->site_id ?? 0) !== $siteId) {
            throw new InvalidArgumentException('Project site_id mismatch (wrong context).');
        }

        // Project-level link still requires an article column today — store via meta on a sentinel article if present.
        // Without article, persist opaque snapshot only (idempotent metadata stamp).
        $promptResultId = (int) $result->id;
        $snapshot = is_array($result->input_snapshot) ? $result->input_snapshot : [];
        $already = (int) ($snapshot['attached_project_id'] ?? 0) === $projectId
            && (string) ($snapshot['attach_purpose'] ?? '') === $purpose;

        $snapshot['attached_project_id'] = $projectId;
        $snapshot['attach_purpose'] = $purpose;
        $snapshot['attach_site_id'] = $siteId;
        $result->update(['input_snapshot' => array_merge($snapshot, $meta)]);

        return [
            'attached' => true,
            'deduplicated' => $already,
            'prompt_result_id' => $promptResultId,
            'target_type' => self::TARGET_PROJECT,
            'target_id' => $projectId,
        ];
    }
}
