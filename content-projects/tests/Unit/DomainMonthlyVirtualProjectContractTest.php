<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ViewDomainMonthlyProject;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ViewSeoProject;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ApproveProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectItemOperationsReadModel;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectVirtualMonthCommandPartitioner;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectVirtualMonthTaskResolver;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SitePlanning\SitePlanningActiveUnitAggregator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;

final class DomainMonthlyVirtualProjectContractTest extends TestCase
{
    public function test_read_model_exposes_for_site_month_using_aggregator_legacy_exclusion(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectItemOperationsReadModel::class))->getFileName(),
        );
        self::assertStringContainsString('function forSiteMonth', $src);
        self::assertStringContainsString('forSiteMonth($siteId, $month, true)', $src);
        $start = strpos($src, 'function forSiteMonth');
        self::assertNotFalse($start);
        $end = strpos($src, 'private function appendReturnUrl', $start);
        self::assertNotFalse($end);
        $chunk = substr($src, $start, $end - $start);
        self::assertStringNotContainsString('inContentProjectWorkingSet', $chunk);
        self::assertStringContainsString('excludeGlobalLegacy', (string) file_get_contents(
            (string) (new ReflectionClass(SitePlanningActiveUnitAggregator::class))->getFileName(),
        ));
    }

    public function test_matrix_cell_count_contract_documented_in_for_site_month(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectItemOperationsReadModel::class))->getFileName(),
        );
        self::assertStringContainsString("\$stats['planning_total'] = \$planningTotal", $src);
        self::assertStringContainsString("\$stats['total_items'] = \$planningTotal", $src);
        self::assertStringContainsString("'project_name'", $src);
        self::assertStringContainsString("'writer_name'", $src);
    }

    public function test_virtual_page_route_and_no_whole_project_header_actions(): void
    {
        $resource = (string) file_get_contents(
            (string) (new ReflectionClass(SeoProjectResource::class))->getFileName(),
        );
        self::assertStringContainsString("ViewDomainMonthlyProject::route('/domain/{site}/month/{month}')", $resource);
        self::assertStringContainsString('function getDomainMonthlyVirtualUrl', $resource);

        $page = (string) file_get_contents(
            (string) (new ReflectionClass(ViewDomainMonthlyProject::class))->getFileName(),
        );
        self::assertStringContainsString('virtualDomainMonth = true', $page);
        self::assertStringContainsString('resolveOwnedTask', $page);
        self::assertStringContainsString('partitionTaskIdsByProject', $page);
        self::assertStringNotContainsString('archive_project', $page);
        self::assertStringNotContainsString('emergency_stop', $page);
        self::assertStringNotContainsString('GenerateProjectItemsCommand', $page);
    }

    public function test_virtual_mount_allows_null_record_and_reads_site_month_from_route(): void
    {
        $params = (new ReflectionClass(ViewDomainMonthlyProject::class))
            ->getMethod('mount')
            ->getParameters();

        self::assertCount(1, $params);
        self::assertSame('record', $params[0]->getName());
        self::assertTrue($params[0]->allowsNull(), 'domain-month route has no {record}');

        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ViewDomainMonthlyProject::class))->getFileName(),
        );
        self::assertStringContainsString("request()->route('site')", $src);
        self::assertStringContainsString("request()->route('month')", $src);
        self::assertStringNotContainsString('?int $site', $src);
        self::assertStringNotContainsString('?string $month', $src);
    }

    public function test_base_page_helpers_used_by_virtual_page_are_protected(): void
    {
        $base = new ReflectionClass(ViewSeoProject::class);
        foreach ([
            'syncArticleGenerationModePreference',
            'resolvedWorkflowFilter',
            'applyOptimisticRowHides',
            'invalidateOpsCache',
            'buildOpsCacheKey',
            'resetClientOptimisticHints',
        ] as $method) {
            self::assertTrue($base->getMethod($method)->isProtected(), $method.' must be protected for virtual subclass');
        }
    }

    public function test_monthly_planning_cells_link_to_virtual_route(): void
    {
        $blade = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/components/content-project-list-monthly-planning.blade.php'),
        );
        self::assertStringContainsString('getDomainMonthlyVirtualUrl', $blade);
        self::assertStringContainsString('$siteId', $blade);
        self::assertStringContainsString('$planned > 0', $blade);
    }

    public function test_resolver_and_partitioner_contracts(): void
    {
        $resolver = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectVirtualMonthTaskResolver::class))->getFileName(),
        );
        self::assertStringContainsString('->forSiteMonth($siteId', $resolver);
        self::assertStringContainsString('true)', $resolver);

        $partitioner = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectVirtualMonthCommandPartitioner::class))->getFileName(),
        );
        self::assertStringContainsString('partitionTaskIdsByProject', $partitioner);
        self::assertStringContainsString('rescopeCommand', $partitioner);
    }

    public function test_ops_blade_virtual_mode_hides_project_workspace_chrome(): void
    {
        $ops = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/resources/seo-project-resource/pages/view-seo-project-operations.blade.php'),
        );
        self::assertStringContainsString('$virtualDomainMonth', $ops);
        self::assertStringContainsString('getVirtualDomainOptions', $ops);
        self::assertStringContainsString('! $virtualDomainMonth && $this->settingsOpen', $ops);
    }

  /** @dataProvider virtualItemActionMethodsProvider */
    public function test_virtual_item_actions_resolve_task_not_bare_require_project(
        string $method,
        bool $expectsResolveOwnedTask,
        bool $expectsCommandProjectRef,
    ): void {
        $chunk = $this->extractViewSeoProjectMethodBody($method);
        if ($expectsResolveOwnedTask) {
            self::assertStringContainsString('resolveOwnedTask', $chunk, $method);
        }
        if ($expectsCommandProjectRef) {
            self::assertStringContainsString('commandProjectRefForItemRefs', $chunk, $method);
        }
        self::assertStringNotContainsString(
            "where('project_id', (int) \$project",
            $chunk,
            $method.' must not scope task load via requireProject()',
        );
    }

    /**
     * @return array<string, array{0: string, 1: bool, 2: bool}>
     */
    public static function virtualItemActionMethodsProvider(): array
    {
        return [
            'createOrRerunOne' => ['createOrRerunOne', true, false],
            'resumeFromFailedStep' => ['resumeFromFailedStep', true, true],
            'skipGenerationOne' => ['skipGenerationOne', true, true],
            'changeItemStatus' => ['changeItemStatus', true, false],
            'skipSeoAuditOne' => ['skipSeoAuditOne', true, false],
            'dispatchArchive' => ['dispatchArchive', false, true],
            'confirmRecreateMissingArticle' => ['confirmRecreateMissingArticle', true, false],
            'skipGenerationSelected' => ['skipGenerationSelected', true, true],
        ];
    }

    public function test_require_project_honors_command_context_on_base_page(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ViewSeoProject::class))->getFileName(),
        );
        self::assertStringContainsString('projectContextForCommand instanceof SeoProject', $src);
    }

    public function test_dead_duplicate_virtual_page_implementations_removed(): void
    {
        $paths = [
            'content-projects/src/Filament/Pages/DomainMonthlyVirtualProjectPage.php',
            'content-projects/src/Services/ContentProject/SitePlanning/ContentProjectDomainMonthlyVirtualReadModel.php',
            'content-projects/src/Services/ContentProject/Application/ContentProjectCrossProjectItemActionDispatcher.php',
            'seo-content-ai-compat/resources/views/filament/pages/domain-monthly-virtual-project.blade.php',
        ];
        foreach ($paths as $relative) {
            self::assertFileDoesNotExist(dirname(__DIR__, 3).'/'.$relative, $relative);
        }
    }

    public function test_partitioner_rescopes_approve_project_items_command(): void
    {
        $partitioner = new ContentProjectVirtualMonthCommandPartitioner;
        $command = new ApproveProjectItemsCommand(55, [10, 20]);
        $scoped = $partitioner->rescopeCommand($command, 77, [20]);

        self::assertInstanceOf(ApproveProjectItemsCommand::class, $scoped);
        self::assertSame(77, $scoped->projectRef);
        self::assertSame([20], $scoped->itemRefs);
        self::assertSame(55, $command->projectRef);
    }

    public function test_virtual_page_overrides_mark_all_recently_completed(): void
    {
        $virtual = (string) file_get_contents(
            (string) (new ReflectionClass(ViewDomainMonthlyProject::class))->getFileName(),
        );
        self::assertStringContainsString('function markAllRecentlyCompletedViewed', $virtual);
        self::assertStringContainsString('markManyViewed', $virtual);
    }

    public function test_virtual_page_soft_resolve_planner_project_avoids_require_project_abort(): void
    {
        $method = (new ReflectionClass(ViewDomainMonthlyProject::class))->getMethod('resolvePlannerProject');
        self::assertSame(ViewDomainMonthlyProject::class, $method->getDeclaringClass()->getName());
        $src = (string) file_get_contents((string) $method->getFileName());
        $start = strpos($src, 'function resolvePlannerProject');
        self::assertNotFalse($start);
        $chunk = substr($src, $start, 280);
        self::assertStringNotContainsString('requireProject()', $chunk);
    }

    private function extractViewSeoProjectMethodBody(string $method): string
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ViewSeoProject::class))->getFileName(),
        );
        $needle = 'function '.$method;
        $start = strpos($src, $needle);
        self::assertNotFalse($start, $method);

        $rest = substr($src, $start);
        if (preg_match('/\n    (?:public|protected|private) function /', $rest, $m, PREG_OFFSET_CAPTURE, strlen($needle))) {
            return substr($rest, 0, $m[0][1]);
        }

        return $rest;
    }
}