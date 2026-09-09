<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectCreateGenerationGuard;
use Omnichannel\Addons\ContentProjects\Services\CreateArticlesFromTaskService;
use Omnichannel\Addons\ContentProjects\Services\RunEngine\ContentProjectTaskExecutionService;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectWorkflowRunService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * CREATE generation site authority = task.site_id, never SeoProject.site_id.
 */
final class ContentProjectCreateGenerationSiteAuthorityTest extends TestCase
{
    private const SITE_A = 10;

    private const SITE_B = 20;

    public function test_a_legacy_project_site_does_not_override_task_site(): void
    {
        // Project site SITE_A (stale) is irrelevant; task+article are SITE_B.
        ContentProjectCreateGenerationGuard::assertState([
            'type' => SeoProjectTask::TYPE_CREATE,
            'project_id' => 21,
            'task_site_id' => self::SITE_B,
            'expected_site_id' => self::SITE_B,
            'canonical_site_id' => self::SITE_B,
            'task_article_id' => 100,
            'article_id' => 100,
            'article_site_id' => self::SITE_B,
            'context_article_id' => 100,
            'task_keyword' => 'keyword-b',
            'prompt_focus_keyword' => 'keyword-b',
        ]);
        $this->addToAssertionCount(1);
    }

    public function test_b_article_wrong_task_site_still_fails(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(ContentProjectCreateGenerationGuard::CODE_ARTICLE_WRONG_SITE);

        ContentProjectCreateGenerationGuard::assertState([
            'type' => SeoProjectTask::TYPE_CREATE,
            'project_id' => 21,
            'task_site_id' => self::SITE_B,
            'expected_site_id' => self::SITE_B,
            'canonical_site_id' => self::SITE_B,
            'task_article_id' => 101,
            'article_id' => 101,
            'article_site_id' => self::SITE_A,
            'context_article_id' => 101,
            'task_keyword' => 'keyword-b',
            'prompt_focus_keyword' => 'keyword-b',
        ]);
    }

    public function test_c_domain_neutral_project_passes(): void
    {
        ContentProjectCreateGenerationGuard::assertState([
            'type' => SeoProjectTask::TYPE_CREATE,
            'project_id' => 21,
            'task_site_id' => self::SITE_B,
            'expected_site_id' => self::SITE_B,
            'canonical_site_id' => self::SITE_B,
            'task_article_id' => 102,
            'article_id' => 102,
            'article_site_id' => self::SITE_B,
            'context_article_id' => 102,
            'task_keyword' => 'keyword-b',
            'prompt_focus_keyword' => 'keyword-b',
        ]);
        $this->addToAssertionCount(1);
    }

    public function test_d_multi_item_same_project_pass_independently(): void
    {
        ContentProjectCreateGenerationGuard::assertState([
            'type' => SeoProjectTask::TYPE_CREATE,
            'project_id' => 21,
            'task_site_id' => self::SITE_A,
            'expected_site_id' => self::SITE_A,
            'canonical_site_id' => self::SITE_A,
            'task_article_id' => 201,
            'article_id' => 201,
            'article_site_id' => self::SITE_A,
            'context_article_id' => 201,
            'task_keyword' => 'item-a',
            'prompt_focus_keyword' => 'item-a',
        ]);

        ContentProjectCreateGenerationGuard::assertState([
            'type' => SeoProjectTask::TYPE_CREATE,
            'project_id' => 21,
            'task_site_id' => self::SITE_B,
            'expected_site_id' => self::SITE_B,
            'canonical_site_id' => self::SITE_B,
            'task_article_id' => 202,
            'article_id' => 202,
            'article_site_id' => self::SITE_B,
            'context_article_id' => 202,
            'task_keyword' => 'item-b',
            'prompt_focus_keyword' => 'item-b',
        ]);

        $this->addToAssertionCount(2);
    }

    public function test_e_canonical_matches_task_site_passes(): void
    {
        ContentProjectCreateGenerationGuard::assertState([
            'type' => SeoProjectTask::TYPE_CREATE,
            'project_id' => 21,
            'task_site_id' => self::SITE_B,
            'expected_site_id' => self::SITE_B,
            'canonical_site_id' => self::SITE_B,
            'task_article_id' => 300,
            'article_id' => 300,
            'article_site_id' => self::SITE_B,
            'context_article_id' => 300,
            'task_keyword' => 'canonical-ok',
            'prompt_focus_keyword' => 'canonical-ok',
        ]);
        $this->addToAssertionCount(1);
    }

    public function test_e_canonical_mismatch_fails_without_using_project_site(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(ContentProjectCreateGenerationGuard::CODE_SITE_CONTEXT_MISMATCH);

        ContentProjectCreateGenerationGuard::assertState([
            'type' => SeoProjectTask::TYPE_CREATE,
            'project_id' => 21,
            'task_site_id' => self::SITE_B,
            'expected_site_id' => self::SITE_B,
            'canonical_site_id' => self::SITE_A,
            'task_article_id' => 301,
            'article_id' => 301,
            'article_site_id' => self::SITE_B,
            'context_article_id' => 301,
            'task_keyword' => 'canonical-bad',
            'prompt_focus_keyword' => 'canonical-bad',
        ]);
    }

    public function test_stale_project_site_id_key_is_ignored(): void
    {
        // Passing obsolete project_site_id must not become expected site authority.
        ContentProjectCreateGenerationGuard::assertState([
            'type' => SeoProjectTask::TYPE_CREATE,
            'project_id' => 21,
            'project_site_id' => self::SITE_A,
            'task_site_id' => self::SITE_B,
            'expected_site_id' => self::SITE_B,
            'canonical_site_id' => self::SITE_B,
            'task_article_id' => 400,
            'article_id' => 400,
            'article_site_id' => self::SITE_B,
            'context_article_id' => 400,
            'task_keyword' => 'ignore-project',
            'prompt_focus_keyword' => 'ignore-project',
        ]);
        $this->addToAssertionCount(1);
    }

    public function test_guard_source_never_reads_project_site_as_authority(): void
    {
        $src = $this->source(ContentProjectCreateGenerationGuard::class);
        self::assertStringContainsString('(int) ($task->site_id ?? 0)', $src);
        self::assertStringContainsString('expected_site_id', $src);
        self::assertStringContainsString('task_site_id', $src);
        self::assertStringContainsString(ContentProjectCreateGenerationGuard::CODE_SITE_CONTEXT_MISMATCH, $src);
        self::assertStringNotContainsString('$project->site_id', $src);
        self::assertStringNotContainsString('project_site_id', $src);
    }

    public function test_generation_path_keeps_task_site_authority(): void
    {
        $workflow = $this->source(SeoProjectWorkflowRunService::class);
        self::assertStringContainsString('$taskSiteId = (int) ($task->site_id ?? $projectSiteId)', $workflow);
        self::assertStringContainsString('runPublishWorkflowForContext($context, $taskSiteId)', $workflow);
        self::assertStringContainsString('articleScopeForProject($taskSiteId)', $workflow);

        $create = $this->source(CreateArticlesFromTaskService::class);
        self::assertStringContainsString('ContentProjectCreateGenerationGuard::assertBeforeAi', $create);
        self::assertStringContainsString('$resolvedSiteId = (int) ($context->siteId ?? $siteId)', $create);

        $exec = $this->source(ContentProjectTaskExecutionService::class);
        self::assertStringContainsString('$itemSiteId = (int) ($task->site_id ?? $project->site_id ?? 0)', $exec);
    }

    private function source(string $class): string
    {
        $ref = new ReflectionClass($class);
        $file = (string) $ref->getFileName();

        return (string) file_get_contents($file);
    }
}
