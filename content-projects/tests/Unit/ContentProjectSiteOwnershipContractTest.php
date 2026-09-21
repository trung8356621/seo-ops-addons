<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\Agent\Automation\Actions\Project\AttachArticleToProjectTaskAction;
use Omnichannel\Addons\Agent\Automation\Migration\ProjectTaskCallerBridge;
use Omnichannel\Addons\ContentProjects\Console\RepairContentProjectSiteLinksCommand;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\EditSeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\SelectExistingArticleForProjectItemHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\UpdateContentProjectHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectExistingArticleReconciler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectSiteLinkRepairService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\LocalArticleAssociationGuard;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectRunItemService;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectTaskSyncService;
use Omnichannel\Addons\ContentProjects\Support\ProjectTaskSourceKeyGenerator;
use Omnichannel\Addons\ContentProjects\Support\SeoProjectTaskSyncDataNormalizer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ContentProjectSiteOwnershipContractTest extends TestCase
{
    public function test_persisted_task_site_wins_over_legacy_project_site(): void
    {
        $generator = new ProjectTaskSourceKeyGenerator;
        $normalizer = new class($generator) extends SeoProjectTaskSyncDataNormalizer
        {
            protected function allowedSiteIds(): array
            {
                return [10, 20];
            }

            protected function persistedTaskSiteMap(array $tasksData): array
            {
                return [1 => 20];
            }
        };

        $project = new SeoProject;
        $project->id = 11;
        $project->site_id = 10;

        $rows = $normalizer->normalize($project, [[
            'id' => 1,
            'type' => SeoProjectTask::TYPE_CREATE,
            'keyword' => 'túi canvas',
            'title' => 'Túi canvas giá rẻ',
            'site_id' => 10,
        ]], 10);

        self::assertCount(1, $rows);
        self::assertSame(20, $rows[0]->siteId);
        self::assertSame(10, $normalizer->legacyFallbackSiteId($project, 20));
    }

    public function test_new_row_uses_explicit_row_site_before_project_fallback(): void
    {
        $generator = new ProjectTaskSourceKeyGenerator;
        $normalizer = new class($generator) extends SeoProjectTaskSyncDataNormalizer
        {
            protected function allowedSiteIds(): array
            {
                return [10, 20];
            }
        };

        $project = new SeoProject;
        $project->id = 11;
        $project->site_id = 10;

        $rows = $normalizer->normalize($project, [[
            'type' => SeoProjectTask::TYPE_CREATE,
            'keyword' => 'túi canvas',
            'title' => 'Túi canvas giá rẻ',
            'site_id' => 20,
        ]], 10);

        self::assertCount(1, $rows);
        self::assertSame(20, $rows[0]->siteId);
    }

    public function test_new_row_falls_back_to_legacy_project_site_when_row_site_missing(): void
    {
        $generator = new ProjectTaskSourceKeyGenerator;
        $normalizer = new class($generator) extends SeoProjectTaskSyncDataNormalizer
        {
            protected function allowedSiteIds(): array
            {
                return [10, 20];
            }
        };

        $project = new SeoProject;
        $project->id = 1;
        $project->site_id = 10;

        $rows = $normalizer->normalize($project, [[
            'type' => SeoProjectTask::TYPE_CREATE,
            'keyword' => 'kw',
            'title' => 'Title same',
        ]], 10);

        self::assertSame(10, $rows[0]->siteId);
    }

    public function test_repair_policy_cases(): void
    {
        $case1 = ContentProjectSiteLinkRepairService::decide([
            'project_site_id' => 10,
            'task_site_id' => 20,
            'article_id' => 100,
            'article_site_id' => 10,
        ]);
        self::assertSame(ContentProjectSiteLinkRepairService::DECISION_REPAIR_TASK_SITE, $case1['decision']);

        $case2 = ContentProjectSiteLinkRepairService::decide([
            'project_site_id' => 10,
            'task_site_id' => 20,
            'article_id' => 0,
            'article_site_id' => 0,
        ]);
        self::assertSame(ContentProjectSiteLinkRepairService::DECISION_REPAIR_TASK_SITE, $case2['decision']);

        $case3 = ContentProjectSiteLinkRepairService::decide([
            'project_site_id' => 10,
            'task_site_id' => 20,
            'article_id' => 200,
            'article_site_id' => 30,
        ]);
        self::assertSame(ContentProjectSiteLinkRepairService::DECISION_DETACH_AND_RECONCILE, $case3['decision']);
        self::assertSame('attached_article_wrong_site', $case3['problem']);

        $ok = ContentProjectSiteLinkRepairService::decide([
            'project_site_id' => 10,
            'task_site_id' => 10,
            'article_id' => 100,
            'article_site_id' => 10,
        ]);
        self::assertSame(ContentProjectSiteLinkRepairService::DECISION_OK, $ok['decision']);

        $neutralOk = ContentProjectSiteLinkRepairService::decide([
            'project_site_id' => 0,
            'task_site_id' => 7,
            'article_id' => 0,
            'article_site_id' => 0,
        ]);
        self::assertSame(ContentProjectSiteLinkRepairService::DECISION_OK, $neutralOk['decision']);
    }

    public function test_repair_command_defaults_to_dry_run(): void
    {
        $command = new RepairContentProjectSiteLinksCommand;
        self::assertSame('seo:content-project:repair-site-links', $command->getName());
        self::assertTrue($command->getDefinition()->hasOption('dry-run'));
        self::assertTrue($command->getDefinition()->hasOption('apply'));

        $src = $this->source(ContentProjectSiteLinkRepairService::class);
        self::assertStringContainsString("\$locked->article_id = null", $src);
        self::assertStringContainsString('Never mutates SeoArticle.site_id', $src);
        self::assertStringNotContainsString("->update(['site_id'", $src);
        self::assertStringContainsString('auto_reconcile=NO', $src);
        self::assertStringNotContainsString('previewDetachAndReconcile', $src);
        self::assertStringNotContainsString('reconcileTask($locked, $projectSiteId', $src);
    }

    public function test_cross_site_article_cannot_be_attached(): void
    {
        $attach = $this->source(AttachArticleToProjectTaskAction::class);
        self::assertStringContainsString('LocalArticleAssociationGuard::resolveLocalArticleId', $attach);
        self::assertStringContainsString('article_wrong_site', $attach);
        self::assertStringContainsString('(int) ($task->site_id ?? 0)', $attach);

        $bridge = $this->source(ProjectTaskCallerBridge::class);
        self::assertStringContainsString('same site as this item (task.site_id)', $bridge);

        $select = $this->source(SelectExistingArticleForProjectItemHandler::class);
        self::assertStringContainsString('articleBelongsToSite', $select);
        self::assertStringContainsString('(int) ($task->site_id ?? 0)', $select);
        self::assertStringNotContainsString('(int) ($project->site_id ?? 0)', $select);
    }

    public function test_rewrite_title_lookup_is_site_scoped(): void
    {
        $sync = $this->source(SeoProjectTaskSyncService::class);
        self::assertStringContainsString("->where('site_id', \$siteId)", $sync);
        self::assertStringContainsString('function resolveArticleIdByTitle', $sync);
        self::assertStringContainsString("'site_id' => (int) (\$task->site_id ?? 0) ?: null", $sync);
    }

    public function test_global_domain_context_cannot_override_project_site(): void
    {
        $normalizer = $this->source(SeoProjectTaskSyncDataNormalizer::class);
        self::assertStringContainsString('legacyFallbackSiteId', $normalizer);
        self::assertStringContainsString('persistedTaskSiteMap', $normalizer);
        self::assertStringNotContainsString('globalSiteId', $normalizer);

        $resource = $this->source(SeoProjectResource::class);
        self::assertStringContainsString('Existing project: never fall back to global Domain Context', $resource);
        self::assertStringContainsString('Domain Context / ?domain=all is UI-only', $resource);
        self::assertStringContainsString('applyProjectTenantScope', $resource);
        self::assertStringNotContainsString('where(\'site_id\', (int) SeoAccessControl::globalSiteId())', $resource);

        $project = $this->source(SeoProject::class);
        self::assertStringContainsString('hasLinkedOrGeneratedArticles', $project);

        $update = $this->source(UpdateContentProjectHandler::class);
        self::assertStringContainsString('site_change_blocked_linked_articles', $update);

        $edit = $this->source(EditSeoProject::class);
        self::assertStringContainsString('normalizeProjectSiteId($data, $record)', $edit);
        self::assertStringContainsString('existing task.site_id is preserved', $edit);
    }

    public function test_reconciler_and_bind_prefer_task_site(): void
    {
        $reconciler = $this->source(ContentProjectExistingArticleReconciler::class);
        self::assertStringContainsString('$fromTask = (int) ($task->site_id ?? 0)', $reconciler);
        self::assertTrue(
            strpos($reconciler, '$fromTask = (int) ($task->site_id ?? 0)')
            < strpos($reconciler, '$fromProject = (int) ($task->project?->site_id ?? 0)'),
        );

        $bind = $this->source(SeoProjectRunItemService::class);
        self::assertStringContainsString('$taskSiteId = (int) ($task->site_id ?? 0)', $bind);
        self::assertStringContainsString('ContentProjectBindArticleAuthority::resolveSiteId', $bind);
    }

    /**
     * @param  class-string  $class
     */
    private function source(string $class): string
    {
        $path = (string) (new ReflectionClass($class))->getFileName();

        return (string) file_get_contents($path);
    }
}
