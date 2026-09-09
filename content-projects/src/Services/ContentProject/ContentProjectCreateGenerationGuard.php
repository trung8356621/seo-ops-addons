<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemIdentity;
use Omnichannel\Addons\ContentProjects\Support\ProjectTaskOriginVariables;
use Omnichannel\Addons\ContentProjects\Support\TaskTestContext;

/**
 * Hard stop before first AI/prompt call for Content Project CREATE.
 *
 * Domain authority is the item/task site (SeoProjectTask.site_id), not SeoProject.site_id.
 * Invariant: article.site_id === task.site_id (expected_site_id).
 */
final class ContentProjectCreateGenerationGuard
{
    public const CODE_MISSING_PROJECT = 'create_generation_missing_project';

    public const CODE_MISSING_CANONICAL_SITE = 'create_generation_missing_canonical_site';

    public const CODE_SITE_CONTEXT_MISMATCH = 'create_generation_site_context_mismatch';

    public const CODE_MISSING_LOCAL_ARTICLE = 'create_generation_missing_local_article';

    public const CODE_ARTICLE_WRONG_SITE = 'create_generation_article_wrong_site';

    public const CODE_TASK_ARTICLE_MISMATCH = 'create_generation_task_article_mismatch';

    public const CODE_CONTEXT_ARTICLE_MISMATCH = 'create_generation_context_article_mismatch';

    public const CODE_FOCUS_KEYWORD_MISMATCH = 'create_generation_focus_keyword_mismatch';

    /**
     * @param  array{
     *     type?: string,
     *     project_id?: int,
     *     task_site_id?: int,
     *     expected_site_id?: int,
     *     canonical_site_id?: int,
     *     task_article_id?: int,
     *     article_id?: int,
     *     article_site_id?: int,
     *     context_article_id?: int,
     *     task_keyword?: string,
     *     prompt_focus_keyword?: string
     * }  $state
     *
     * @throws \InvalidArgumentException
     */
    public static function assertState(array $state): void
    {
        $type = SeoProjectTask::normalizeType((string) ($state['type'] ?? SeoProjectTask::TYPE_CREATE));
        if ($type !== SeoProjectTask::TYPE_CREATE) {
            return;
        }

        $projectId = (int) ($state['project_id'] ?? 0);
        if ($projectId <= 0) {
            throw new \InvalidArgumentException(self::CODE_MISSING_PROJECT);
        }

        $taskSiteId = (int) ($state['task_site_id'] ?? 0);
        $expectedSiteId = (int) ($state['expected_site_id'] ?? 0);
        if ($expectedSiteId <= 0) {
            $expectedSiteId = $taskSiteId;
        }
        if ($expectedSiteId <= 0) {
            throw new \InvalidArgumentException(self::CODE_MISSING_CANONICAL_SITE);
        }

        $canonicalSiteId = (int) ($state['canonical_site_id'] ?? 0);
        if ($canonicalSiteId > 0 && $canonicalSiteId !== $expectedSiteId) {
            throw new \InvalidArgumentException(self::CODE_SITE_CONTEXT_MISMATCH);
        }

        $articleId = (int) ($state['article_id'] ?? 0);
        if ($articleId <= 0) {
            throw new \InvalidArgumentException(self::CODE_MISSING_LOCAL_ARTICLE);
        }

        $articleSiteId = (int) ($state['article_site_id'] ?? 0);
        if ($articleSiteId !== $expectedSiteId) {
            throw new \InvalidArgumentException(self::CODE_ARTICLE_WRONG_SITE);
        }

        $taskArticleId = (int) ($state['task_article_id'] ?? 0);
        if ($taskArticleId !== $articleId) {
            throw new \InvalidArgumentException(self::CODE_TASK_ARTICLE_MISMATCH);
        }

        $contextArticleId = (int) ($state['context_article_id'] ?? 0);
        if ($contextArticleId !== $articleId) {
            throw new \InvalidArgumentException(self::CODE_CONTEXT_ARTICLE_MISMATCH);
        }

        $taskKeyword = ContentProjectItemIdentity::normalize(
            isset($state['task_keyword']) ? (string) $state['task_keyword'] : null,
        );
        if ($taskKeyword === '') {
            return;
        }

        $promptKeyword = ContentProjectItemIdentity::normalize(
            isset($state['prompt_focus_keyword']) ? (string) $state['prompt_focus_keyword'] : null,
        );
        if ($promptKeyword !== $taskKeyword) {
            throw new \InvalidArgumentException(self::CODE_FOCUS_KEYWORD_MISMATCH);
        }
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function assertBeforeAi(TaskTestContext $context, int $canonicalSiteId): void
    {
        $type = SeoProjectTask::normalizeType((string) ($context->projectTaskType ?? ''));
        if ($type !== SeoProjectTask::TYPE_CREATE) {
            return;
        }

        $originId = ProjectTaskOriginVariables::read($context->variables);
        $task = $originId !== null ? SeoProjectTask::query()->find($originId) : null;
        if (! $task instanceof SeoProjectTask) {
            throw new \InvalidArgumentException(self::CODE_MISSING_PROJECT);
        }

        $task->loadMissing('project');
        $project = $task->project;
        if (! $project instanceof SeoProject) {
            throw new \InvalidArgumentException(self::CODE_MISSING_PROJECT);
        }

        // Item/task domain is authoritative. Never compare against SeoProject.site_id.
        $taskSiteId = (int) ($task->site_id ?? 0);
        // Compat for genuinely old tasks with no task.site_id: use execution canonical only.
        // Never fall back to project.site_id (stale legacy project domain must not override).
        $expectedSiteId = $taskSiteId > 0 ? $taskSiteId : $canonicalSiteId;
        if ($expectedSiteId <= 0) {
            throw new \InvalidArgumentException(self::CODE_MISSING_CANONICAL_SITE);
        }

        if ($taskSiteId > 0 && $canonicalSiteId > 0 && $canonicalSiteId !== $taskSiteId) {
            throw new \InvalidArgumentException(self::CODE_SITE_CONTEXT_MISMATCH);
        }

        $article = $context->article;
        $articleId = $article instanceof SeoArticle ? (int) $article->getKey() : 0;
        $articleSiteId = $article instanceof SeoArticle ? (int) ($article->site_id ?? 0) : 0;

        self::assertState([
            'type' => $type,
            'project_id' => (int) $project->getKey(),
            'task_site_id' => $taskSiteId,
            'expected_site_id' => $expectedSiteId,
            'canonical_site_id' => $canonicalSiteId,
            'task_article_id' => (int) ($task->article_id ?? 0),
            'article_id' => $articleId,
            'article_site_id' => $articleSiteId,
            'context_article_id' => $articleId,
            'task_keyword' => (string) ($task->keyword ?? ''),
            'prompt_focus_keyword' => (string) ($context->variables['focus_keyword'] ?? ''),
        ]);
    }
}
