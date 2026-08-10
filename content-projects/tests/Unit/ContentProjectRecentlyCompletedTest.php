<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;



use Tests\Support\ProjectRoot;
use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ViewSeoProject;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectGenerationReadStateStore;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectItemOperationsReadModel;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectOpsCounterTransitionMap;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectRecentlyCompletedDefinition;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectStatusBadgePresenter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ContentProjectRecentlyCompletedTest extends TestCase
{
    public function test_running_item_not_in_needs_review(): void
    {
        self::assertFalse(ContentProjectRecentlyCompletedDefinition::matches([
            'generation_status' => 'completed',
            'execution_status' => 'success',
            'is_genuinely_running' => true,
            'generation_completed_at' => '2026-08-01T10:00:00+00:00',
            'viewed_generation_completed_at' => null,
        ]));
    }

    public function test_successful_unread_item_counts_as_needs_review(): void
    {
        self::assertTrue(ContentProjectRecentlyCompletedDefinition::matches([
            'generation_status' => 'completed',
            'execution_status' => 'success',
            'is_genuinely_running' => false,
            'generation_completed_at' => '2026-08-01T10:00:00+00:00',
            'viewed_generation_completed_at' => null,
        ]));
    }

    public function test_viewed_same_completion_not_in_needs_review(): void
    {
        self::assertFalse(ContentProjectRecentlyCompletedDefinition::matches([
            'generation_status' => 'completed',
            'execution_status' => 'success',
            'is_genuinely_running' => false,
            'generation_completed_at' => '2026-08-01T10:00:00+00:00',
            'viewed_generation_completed_at' => '2026-08-01T10:00:00+00:00',
        ]));
    }

    public function test_rerun_after_view_returns_to_needs_review(): void
    {
        self::assertTrue(ContentProjectRecentlyCompletedDefinition::matches([
            'generation_status' => 'completed',
            'execution_status' => 'success',
            'is_genuinely_running' => false,
            'generation_completed_at' => '2026-08-02T12:00:00+00:00',
            'viewed_generation_completed_at' => '2026-08-01T10:00:00+00:00',
        ]));
    }

    public function test_failed_run_not_in_needs_review(): void
    {
        self::assertFalse(ContentProjectRecentlyCompletedDefinition::matches([
            'generation_status' => 'failed',
            'execution_status' => 'failed',
            'is_genuinely_running' => false,
            'generation_completed_at' => '2026-08-01T10:00:00+00:00',
            'viewed_generation_completed_at' => null,
        ]));
    }

    public function test_latest_failed_even_with_older_success_not_in_needs_review(): void
    {
        self::assertFalse(ContentProjectRecentlyCompletedDefinition::matches([
            'generation_status' => 'failed',
            'execution_status' => 'failed',
            'is_genuinely_running' => false,
            'generation_completed_at' => null,
            'viewed_generation_completed_at' => null,
        ]));

        self::assertFalse(ContentProjectRecentlyCompletedDefinition::matches([
            'generation_status' => 'completed',
            'execution_status' => 'failed',
            'is_genuinely_running' => false,
            'generation_completed_at' => '2026-08-01T10:00:00+00:00',
            'viewed_generation_completed_at' => null,
        ]));
    }

    public function test_filter_sort_newest_first(): void
    {
        $sorted = ContentProjectRecentlyCompletedDefinition::sortNewestFirst([
            ['task_id' => 1, 'generation_completed_at' => '2026-08-01T09:00:00+00:00'],
            ['task_id' => 2, 'generation_completed_at' => '2026-08-01T11:00:00+00:00'],
            ['task_id' => 3, 'generation_completed_at' => '2026-08-01T10:00:00+00:00'],
        ]);

        self::assertSame([2, 3, 1], array_map(
            static fn (array $row): int => (int) $row['task_id'],
            $sorted,
        ));
    }

    public function test_user_a_view_state_independent_of_user_b(): void
    {
        $completed = '2026-08-01T10:00:00+00:00';
        $forA = ContentProjectRecentlyCompletedDefinition::matches([
            'generation_status' => 'completed',
            'execution_status' => 'success',
            'is_genuinely_running' => false,
            'generation_completed_at' => $completed,
            'viewed_generation_completed_at' => $completed,
        ]);
        $forB = ContentProjectRecentlyCompletedDefinition::matches([
            'generation_status' => 'completed',
            'execution_status' => 'success',
            'is_genuinely_running' => false,
            'generation_completed_at' => $completed,
            'viewed_generation_completed_at' => null,
        ]);

        self::assertFalse($forA);
        self::assertTrue($forB);
    }

    public function test_normalize_summary_stats_maps_needs_review(): void
    {
        $summary = ContentProjectItemOperationsReadModel::normalizeSummaryStats([
            'pending' => 1,
            'recently_completed' => 6,
            'failed' => 2,
            'waiting_review' => 3,
            'approved' => 4,
            'waiting_publish' => 5,
            'published' => 7,
            'running' => 0,
        ]);

        self::assertSame([
            'total_items' => 0,
            'working_set' => 0,
            'publishing_queue' => 0,
            'normal' => 0,
            'draft' => 0,
            'pending' => 1,
            'needs_review' => 6,
            'failed' => 2,
            'review' => 3,
            'approved' => 4,
            'scheduled' => 5,
            'published' => 7,
            'running' => 0,
        ], $summary);
    }

    public function test_counter_transition_map_deltas(): void
    {
        self::assertSame(
            ['needs_review' => -1],
            ContentProjectOpsCounterTransitionMap::deltas(ContentProjectOpsCounterTransitionMap::ACTION_MARK_VIEWED),
        );
        self::assertSame(
            ['failed' => -1, 'pending' => 1],
            ContentProjectOpsCounterTransitionMap::deltas(ContentProjectOpsCounterTransitionMap::ACTION_RETRY),
        );
        self::assertSame(
            ['draft' => -1, 'pending' => 1],
            ContentProjectOpsCounterTransitionMap::deltas(ContentProjectOpsCounterTransitionMap::ACTION_ENQUEUE),
        );
        self::assertSame(
            ['review' => -1, 'approved' => 1],
            ContentProjectOpsCounterTransitionMap::deltas(ContentProjectOpsCounterTransitionMap::ACTION_APPROVE),
        );
        self::assertSame(
            ['needs_review' => -1, 'review' => 1],
            ContentProjectOpsCounterTransitionMap::deltas(ContentProjectOpsCounterTransitionMap::ACTION_CONTENT_MANAGER_HANDOFF),
        );
        self::assertSame([], ContentProjectOpsCounterTransitionMap::deltas('unknown'));
    }

    public function test_read_model_and_ui_wiring(): void
    {
        $readSrc = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectItemOperationsReadModel::class))->getFileName(),
        );
        self::assertStringContainsString('ContentProjectRecentlyCompletedDefinition', $readSrc);
        self::assertStringContainsString('recently_completed', $readSrc);
        self::assertStringContainsString('is_recently_completed', $readSrc);
        self::assertStringContainsString('viewer_user_id', $readSrc);
        self::assertStringContainsString('finished_at_iso', $readSrc);
        self::assertStringContainsString('unreadSuccessfulCompletions', $readSrc);
        self::assertStringContainsString('summaryForProject', $readSrc);
        self::assertStringContainsString('normalizeSummaryStats', $readSrc);
        self::assertStringContainsString("'needs_review'", $readSrc);

        $viewSrc = (string) file_get_contents(
            (string) (new ReflectionClass(ViewSeoProject::class))->getFileName(),
        );
        self::assertStringContainsString('markGenerationResultViewed', $viewSrc);
        self::assertStringContainsString('markAllRecentlyCompletedViewed', $viewSrc);
        self::assertStringContainsString('openArticleEditor', $viewSrc);
        self::assertStringContainsString('claimNeedsReviewItem', $viewSrc);
        self::assertStringContainsString('lazyRefreshOps', $viewSrc);
        self::assertStringContainsString('manualRefreshOps', $viewSrc);
        self::assertStringContainsString('persistOptimisticRemovals', $viewSrc);
        self::assertStringContainsString('optimisticHiddenTaskIds', $viewSrc);
        self::assertStringContainsString('applyOptimisticRowHides', $viewSrc);
        self::assertStringContainsString('fetchOpsSummaryOnly', $viewSrc);
        self::assertStringContainsString('operationId', $viewSrc);
        self::assertMethodBodyExcludesResetPage($viewSrc, 'resumeFromFailedStep');
        self::assertMethodBodyExcludesResetPage($viewSrc, 'rerunOne');
        self::assertMethodBodyExcludesResetPage($viewSrc, 'createOrRerunOne');
        self::assertStringContainsString('ContentProjectOpsCounterTransitionMap', $viewSrc);
        self::assertStringContainsString('cp-ops-item-transition', $viewSrc);
        self::assertStringNotContainsString("->title(\$result->success ? 'OK' : 'Failed')", $viewSrc);
        self::assertStringNotContainsString('ops_mark_all_viewed_done', $viewSrc);

        $blade = (string) file_get_contents(
            LegacyAddonPath::resolve('resources')
            .DIRECTORY_SEPARATOR.'views'
            .DIRECTORY_SEPARATOR.'filament'
            .DIRECTORY_SEPARATOR.'resources'
            .DIRECTORY_SEPARATOR.'seo-project-resource'
            .DIRECTORY_SEPARATOR.'pages'
            .DIRECTORY_SEPARATOR.'view-seo-project-operations.blade.php',
        );
        self::assertStringContainsString('ops_needs_review', $blade);
        self::assertStringNotContainsString('wire:poll', $blade);
        self::assertStringContainsString('openNeedsReviewArticle', $blade);
        self::assertStringContainsString('claimNeedsReviewArticle', $blade);
        self::assertStringContainsString('beginRowExit', $blade);
        self::assertStringContainsString('exitingRows', $blade);
        self::assertStringContainsString('pendingTransitions', $blade);
        self::assertStringContainsString('canonicalCounters', $blade);
        self::assertStringContainsString('acceptCanonicalSummary', $blade);
        self::assertStringContainsString('registerPendingTransition', $blade);
        self::assertStringContainsString('summaryRequestId', $blade);
        self::assertStringContainsString('persistOptimisticRemovals', $blade);
        self::assertStringContainsString('isRowVisible', $blade);
        self::assertStringContainsString('removedItemIds', $blade);
        self::assertStringNotContainsString('await $wire.finalizeOpsAfterOptimistic();\n                } catch (e) {}\n                this.resetOptimistic();', $blade);
        self::assertStringContainsString('cp-ops-row--exit', $blade);
        self::assertStringContainsString('prefers-reduced-motion', $blade);
        self::assertStringContainsString('claimBusy', $blade);
        self::assertStringContainsString('notifyOptimisticFailure', $blade);
        self::assertStringContainsString('data-ops-row', $blade);

        $meta = (string) file_get_contents(
            LegacyAddonPath::resolve('resources')
            .DIRECTORY_SEPARATOR.'views'
            .DIRECTORY_SEPARATOR.'components'
            .DIRECTORY_SEPARATOR.'content-project-item-meta.blade.php',
        );
        self::assertStringContainsString('claimNeedsReviewArticle', $meta);
        self::assertStringContainsString('target="_blank"', $meta);
        self::assertStringContainsString('show_reporting_chip', $meta);
        self::assertStringContainsString('reporting_badge', $meta);

        $langEn = (string) file_get_contents(
            LegacyAddonPath::resolve('lang').DIRECTORY_SEPARATOR.'en'.DIRECTORY_SEPARATOR.'filament.php',
        );
        self::assertStringContainsString("'ops_needs_review' => 'Needs Review'", $langEn);
        self::assertStringNotContainsString("'ops_needs_review' => 'AI Inbox'", $langEn);
        self::assertStringContainsString('ops_optimistic_update_failed', $langEn);

        $accent = ContentProjectStatusBadgePresenter::summaryAccent('recently_completed');
        self::assertSame('recently_completed', $accent['key']);
        self::assertSame('heroicon-o-inbox', $accent['icon']);
        self::assertStringContainsString('border-l-primary', $accent['ring']);
    }

    public function test_running_indicator_visible_only_when_count_positive(): void
    {
        $viewSrc = (string) file_get_contents(
            (string) (new ReflectionClass(ViewSeoProject::class))->getFileName(),
        );
        self::assertStringContainsString("visible(fn (): bool => \$this->runningCount > 0)", $viewSrc);
        self::assertStringContainsString('ops_running_items_indicator', $viewSrc);
    }

    public function test_migration_exists_for_read_states(): void
    {
        $path = ProjectRoot::addonsPath().'/content-projects'
            .DIRECTORY_SEPARATOR.'database'
            .DIRECTORY_SEPARATOR.'migrations'
            .DIRECTORY_SEPARATOR.'2026_08_01_110000_create_seo_content_project_item_generation_read_states_table.php';
        self::assertFileExists($path);
        $src = (string) file_get_contents($path);
        self::assertStringContainsString('seo_content_project_item_generation_read_states', $src);
        self::assertStringContainsString('viewed_generation_completed_at', $src);
        self::assertStringContainsString('user_id', $src);
        self::assertStringContainsString('project_item_id', $src);
        self::assertStringContainsString('omi_seo_ai', $src);
    }

    public function test_mark_all_scoped_to_project_and_user_in_source(): void
    {
        $viewSrc = (string) file_get_contents(
            (string) (new ReflectionClass(ViewSeoProject::class))->getFileName(),
        );
        self::assertStringContainsString('unreadSuccessfulCompletions($project, $userId)', $viewSrc);
        self::assertStringContainsString('markManyViewed($userId, (int) $project->getKey(), $unread)', $viewSrc);

        $storeSrc = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectGenerationReadStateStore::class))->getFileName(),
        );
        self::assertStringContainsString("'user_id' => \$userId", $storeSrc);
        self::assertStringContainsString("'project_id' => \$projectId", $storeSrc);
    }

    public function test_optimistic_open_and_lazy_refresh_contract_in_source(): void
    {
        $viewSrc = (string) file_get_contents(
            (string) (new ReflectionClass(ViewSeoProject::class))->getFileName(),
        );
        self::assertStringContainsString('function claimNeedsReviewItem(int $taskId, bool $expectNeedsReviewMark = false): array', $viewSrc);
        self::assertStringContainsString("return ['ok' => false]", $viewSrc);
        self::assertStringContainsString("'ok' => true", $viewSrc);
        self::assertStringContainsString('function lazyRefreshOps(): array', $viewSrc);
        self::assertStringContainsString("'changed' => false", $viewSrc);
        self::assertStringContainsString("'changed' => true", $viewSrc);
        self::assertMatchesRegularExpression('/function markGenerationResultViewed\(int \$taskId\): bool/', $viewSrc);
    }

    private static function assertMethodBodyExcludesResetPage(string $src, string $method): void
    {
        $pattern = '/function '.preg_quote($method, '/').'\(int \$taskId\): void\s*\{([\s\S]*?)\n    public function /';
        self::assertSame(1, preg_match($pattern, $src, $m), "Method {$method} body not found");
        self::assertStringNotContainsString(
            '$this->resetPage()',
            $m[1],
            "{$method} must not call resetPage after optimistic dispatch",
        );
    }
}
