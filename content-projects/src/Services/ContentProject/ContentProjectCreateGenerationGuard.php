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
 */
final class ContentProjectCreateGenerationGuard
{
    public const CODE_MISSING_PROJECT = 'create_generation_missing_project';

    public const CODE_MISSING_CANONICAL_SITE = 'create_generation_missing_canonical_site';

    public const CODE_MISSING_LOCAL_ARTICLE = 'create_generation_missing_local_article';

    public const CODE_ARTICLE_WRONG_SITE = 'create_generation_article_wrong_site';

    public const CODE_TASK_ARTICLE_MISMATCH = 'create_generation_task_article_mismatch';

    public const CODE_CONTEXT_ARTICLE_MISMATCH = 'create_generation_context_article_mismatch';

    public const CODE_FOCUS_KEYWORD_MISMATCH = 'create_generation_focus_keyword_mismatch';

    /**
     * @param  array{
     *     type?: string,
     *     project_id?: int,
     *     project_site_id?: int,
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

        $projectSiteId = (int) ($state['project_site_id'] ?? 0);
        if ($projectSiteId <= 0) {
            throw new \InvalidArgumentException(self::CODE_MISSING_CANONICAL_SITE);
        }

        $articleId = (int) ($state['article_id'] ?? 0);
        if ($articleId <= 0) {
            throw new \InvalidArgumentException(self::CODE_MISSING_LOCAL_ARTICLE);
        }

        $articleSiteId = (int) ($state['article_site_id'] ?? 0);
        if ($articleSiteId !== $projectSiteId) {
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

        $projectSiteId = (int) ($project->site_id ?? 0);
        if ($projectSiteId <= 0) {
            $projectSiteId = $canonicalSiteId;
        }

        $article = $context->article;
        $articleId = $article instanceof SeoArticle ? (int) $article->getKey() : 0;
        $articleSiteId = $article instanceof SeoArticle ? (int) ($article->site_id ?? 0) : 0;

        self::assertState([
            'type' => $type,
            'project_id' => (int) $project->getKey(),
            'project_site_id' => $projectSiteId,
            'task_article_id' => (int) ($task->article_id ?? 0),
            'article_id' => $articleId,
            'article_site_id' => $articleSiteId,
            'context_article_id' => $articleId,
            'task_keyword' => (string) ($task->keyword ?? ''),
            'prompt_focus_keyword' => (string) ($context->variables['focus_keyword'] ?? ''),
        ]);
    }
}
