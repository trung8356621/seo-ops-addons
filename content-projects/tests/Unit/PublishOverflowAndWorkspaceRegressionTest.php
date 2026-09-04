<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectSeoAuditPlanner;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Concerns\InteractsWithDraftSplit;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\EditSeoProject;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ViewSeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\SplitDraftContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectProjectGenerationGate;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\SplitDraftContentProjectService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectExecutionLimits;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectWriterAllocator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Regression: Publish 53→30+23 overflow, show vs edit for null-site execution, Generate UX.
 */
final class PublishOverflowAndWorkspaceRegressionTest extends TestCase
{
    public function test_overflow_chunk_sizes_for_required_cases(): void
    {
        $cases = [
            29 => [29],
            30 => [30],
            31 => [30, 1],
            53 => [30, 23],
            61 => [30, 30, 1],
        ];

        foreach ($cases as $total => $expectedChunks) {
            $allocated = ContentProjectWriterAllocator::allocate(range(1, $total), [9001]);
            self::assertSame(0, $allocated['unallocated_count'], 'total='.$total);
            self::assertSame($total, $allocated['assigned_items'], 'total='.$total);
            self::assertSame(
                $expectedChunks,
                array_map('count', $allocated['allocations'][0]['project_chunks'] ?? []),
                'total='.$total,
            );
            self::assertSame(
                $total,
                array_sum($expectedChunks),
                'invariant sum chunks === requested for total='.$total,
            );
        }

        self::assertSame(30, ContentProjectExecutionLimits::MAX_EXECUTION_PROJECT_ITEMS);
    }

    public function test_packing_service_plan_pack_creates_overflow_bins(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(
                \Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectExecutionPackingService::class,
            ))->getFileName(),
        );

        self::assertStringContainsString('while ($remaining !== [])', $src);
        self::assertStringContainsString('array_splice($remaining, 0, $max)', $src);
        self::assertStringContainsString("'reused' => false", $src);
    }

    public function test_publish_from_planner_defaults_to_mode_all_not_first_n_capped_at_30(): void
    {
        $planner = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectSeoAuditPlanner::class))->getFileName(),
        );
        self::assertStringContainsString(
            'openDraftSplitModal(SplitDraftContentProjectCommand::MODE_ALL)',
            $planner,
        );

        $trait = (string) file_get_contents(
            (string) (new ReflectionClass(InteractsWithDraftSplit::class))->getFileName(),
        );
        self::assertStringContainsString('$this->draftSplitQuantity = max(1, $reviewed)', $trait);
        self::assertStringNotContainsString(
            'ContentProjectExecutionLimits::MAX_EXECUTION_PROJECT_ITEMS,\n            $reviewed',
            $trait,
        );
        self::assertSame('all', SplitDraftContentProjectCommand::MODE_ALL);
    }

    public function test_can_view_allows_domain_neutral_execution_not_only_draft(): void
    {
        $method = new ReflectionMethod(SeoProjectResource::class, 'canView');
        $source = $this->readMethodSource($method);

        self::assertStringContainsString('canAccessPlannerFeatures', $source);
        self::assertStringContainsString('canAccessSite', $source);
        // Regression: must NOT gate null site_id solely on isDraftPlanning().
        self::assertStringNotContainsString('return $record->isDraftPlanning();', $source);
        self::assertStringContainsString('if ($siteId <= 0) {', $source);
        self::assertStringContainsString('return true;', $source);
    }

    public function test_project_record_url_prefers_canonical_view_workspace(): void
    {
        $method = new ReflectionMethod(SeoProjectResource::class, 'projectRecordUrl');
        $source = $this->readMethodSource($method);

        self::assertStringContainsString("getUrl('view'", $source);
        self::assertStringContainsString('canView($record)', $source);
        $viewPreferPos = strpos($source, 'if (static::canView($record))');
        $editFallbackPos = strpos($source, 'if (static::canEdit($record))');
        self::assertNotFalse($viewPreferPos);
        self::assertNotFalse($editFallbackPos);
        self::assertLessThan($editFallbackPos, $viewPreferPos);
    }

    public function test_view_and_edit_routes_both_registered(): void
    {
        $pages = SeoProjectResource::getPages();
        self::assertArrayHasKey('view', $pages);
        self::assertArrayHasKey('edit', $pages);

        $view = (string) file_get_contents((string) (new ReflectionClass(ViewSeoProject::class))->getFileName());
        self::assertStringContainsString('abort_unless($project instanceof SeoProject, 404)', $view);
        self::assertStringContainsString('SeoProjectResource::canView', $view);
        self::assertStringContainsString('getRecordRouteBindingEloquentQuery', $view);

        $resource = (string) file_get_contents((string) (new ReflectionClass(SeoProjectResource::class))->getFileName());
        self::assertStringContainsString("getUrl('view', ['record' => \$project])", $resource);
        self::assertStringContainsString('function getProjectWorkspaceUrl', $resource);
    }


    public function test_edit_has_view_link_not_generate_primary(): void
    {
        $edit = (string) file_get_contents((string) (new ReflectionClass(EditSeoProject::class))->getFileName());
        $view = (string) file_get_contents((string) (new ReflectionClass(ViewSeoProject::class))->getFileName());

        self::assertStringContainsString('makeGeneratePendingItemsAction', $view);
        self::assertStringNotContainsString('makeGeneratePendingItemsAction', $edit);
        self::assertStringContainsString('open_project_workspace', $edit);
        self::assertStringContainsString('getProjectWorkspaceUrl', $edit);
        self::assertStringContainsString('view_project_short', $edit);
    }

    public function test_status_pending_is_not_a_generate_hard_block_in_gate(): void
    {
        $gate = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectProjectGenerationGate::class))->getFileName(),
        );
        self::assertStringContainsString('isDraftPlanning()', $gate);
        self::assertStringNotContainsString('STATUS_PENDING', $gate);
        self::assertStringNotContainsString('status_pending', $gate);
    }

    private function readMethodSource(ReflectionMethod $method): string
    {
        $file = (string) $method->getFileName();
        $start = (int) $method->getStartLine();
        $end = (int) $method->getEndLine();
        $lines = file($file);
        self::assertIsArray($lines);

        return implode('', array_slice($lines, $start - 1, $end - $start + 1));
    }
}
