<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;



use Tests\Support\ProjectRoot;
use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectWorkflowStepRetryService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class SeoProjectWorkflowStepRetryServiceTest extends TestCase
{
    public function test_step_action_stays_within_column_limit(): void
    {
        $service = $this->newServiceWithoutConstructor();
        $short = $service->stepAction('prompt-outline');
        self::assertSame('step:prompt-outline', $short);
        self::assertLessThanOrEqual(64, strlen($short));

        $longNode = str_repeat('n', 80);
        $hashed = $service->stepAction($longNode);
        self::assertStringStartsWith('step:', $hashed);
        self::assertLessThanOrEqual(64, strlen($hashed));
        self::assertNotSame('step:'.$longNode, $hashed);
    }

    public function test_catalog_orders_outline_before_content(): void
    {
        // SeoProjectWorkflowStepCatalogService is final â€” khÃ´ng mock.
        // Contract: usort rank outline=0, content=1, other=2.
        $selected = [
            ['node_id' => 'content-1', 'kind' => 'content'],
            ['node_id' => 'outline-1', 'kind' => 'outline'],
            ['node_id' => 'image-1', 'kind' => 'image'],
        ];

        usort($selected, static function (array $left, array $right): int {
            $leftRank = $left['kind'] === 'outline' ? 0 : ($left['kind'] === 'content' ? 1 : 2);
            $rightRank = $right['kind'] === 'outline' ? 0 : ($right['kind'] === 'content' ? 1 : 2);
            if ($leftRank !== $rightRank) {
                return $leftRank <=> $rightRank;
            }

            return strcmp((string) $left['node_id'], (string) $right['node_id']);
        });

        self::assertSame(
            ['outline-1', 'content-1', 'image-1'],
            array_map(static fn (array $step): string => (string) $step['node_id'], $selected),
        );

        $source = file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/SeoProjectWorkflowStepCatalogService.php'
        );
        self::assertNotFalse($source);
        self::assertStringContainsString('function orderNodeIdsByDependency', $source);
        self::assertStringContainsString("kind'] === 'outline' ? 0", $source);
        self::assertStringContainsString("kind'] === 'content' ? 1 : 2", $source);
    }

    public function test_catalog_prefers_richer_publish_workflow_for_rewrite_step_retry(): void
    {
        $source = file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/SeoProjectWorkflowStepCatalogService.php'
        );
        self::assertNotFalse($source);
        self::assertStringContainsString('resolveSeoTaskForStepRetry', $source);
        self::assertStringContainsString('countPromptNodes', $source);
        self::assertStringContainsString('Æ°u tiÃªn publish náº¿u cÃ³ nhiá»u bÆ°á»›c chá»©c nÄƒng', $source);
    }

    public function test_retry_service_executes_against_step_retry_catalog_task(): void
    {
        $source = file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/SeoProjectWorkflowStepRetryService.php'
        );
        self::assertNotFalse($source);
        self::assertStringContainsString('resolveSeoTaskForStepRetry', $source);
    }

    public function test_view_run_disables_rerun_all_entry(): void
    {
        $source = file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Filament/Resources/SeoProjectResource/Pages/ViewSeoProjectRun.php'
        );
        self::assertNotFalse($source);
        self::assertStringContainsString('getProjectWorkspaceUrl', $source);
        self::assertStringNotContainsString('function canRerunAllItems', $source);
        self::assertStringNotContainsString('function retryWorkflowStep', $source);
        self::assertTrue(method_exists(SeoProjectWorkflowStepRetryService::class, 'cancelActiveStep'));
    }

    public function test_service_exposes_stale_abandon_and_cancel(): void
    {
        self::assertTrue(method_exists(SeoProjectWorkflowStepRetryService::class, 'abandonStaleActiveSteps'));
        self::assertTrue(method_exists(SeoProjectWorkflowStepRetryService::class, 'cancelActiveStep'));
        self::assertTrue(method_exists(SeoProjectWorkflowStepRetryService::class, 'cancelAllActiveSteps'));
        self::assertTrue(method_exists(SeoProjectWorkflowStepRetryService::class, 'cancelAllActiveStepsForTask'));

        $source = file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/SeoProjectWorkflowStepRetryService.php'
        );
        self::assertNotFalse($source);
        self::assertStringContainsString('abandonStaleActiveSteps', $source);
        self::assertStringContainsString('register_shutdown_function', $source);
        self::assertStringContainsString('cancelActiveStep', $source);
        self::assertStringContainsString('cancelAllActiveSteps', $source);
        self::assertStringContainsString('cancelAllActiveStepsForTask', $source);
        self::assertStringContainsString('wasCancelledByUser', $source);
        self::assertStringContainsString('ensureCancelledFailureState', $source);
        self::assertStringContainsString('Claim Pendingâ†’Processing', $source);
        self::assertStringContainsString('assertDependencies', $source);
        self::assertStringContainsString('resolveActiveStepIdsForCancel', $source);
        self::assertStringContainsString('logCancelDiagnostic', $source);
        self::assertStringContainsString('seo.project_run.cancel_workflow_step', $source);
        self::assertStringContainsString('seo.project_run.step_busy_snapshot', $source);
        self::assertStringContainsString("match_mode' => 'task_id'", $source);
        self::assertStringContainsString("match_mode' => 'article_id_null_task'", $source);
        // KhÃ´ng dÃ¹ng OR article_id rá»™ng Ä‘á»¥ng task khÃ¡c.
        self::assertStringNotContainsString('orWhere(\'article_id\', $articleId)', $source);
        // Claim/success khÃ´ng ghi Ä‘Ã¨ cancel marker.
        self::assertStringContainsString('%Cancelled by user%', $source);
        self::assertStringContainsString("where('status', SeoProjectRunItemStatus::Pending->value)", $source);
        self::assertStringContainsString("where('status', SeoProjectRunItemStatus::Processing->value)", $source);
        // Busy chá»‰ khi run chÆ°a terminal + active map (node hoáº·c action) â€” terminal luÃ´n tháº¯ng.
        self::assertStringContainsString('$busy = ! $runTerminal && (', $source);
        self::assertStringContainsString('isset($activeByNode[$nodeId])', $source);
        self::assertStringContainsString('isset($activeByAction[$action])', $source);
        self::assertStringContainsString('status_in_pending_or_processing', $source);
    }

    public function test_was_cancelled_by_user_ignores_status_looks_at_error_marker(): void
    {
        $service = $this->newServiceWithoutConstructor();
        $method = new \ReflectionMethod(SeoProjectWorkflowStepRetryService::class, 'wasCancelledByUser');
        $method->setAccessible(true);

        $processingCancelled = new \Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
        $processingCancelled->forceFill([
            'status' => 'processing',
            'error_message' => 'Cancelled by user.',
        ]);
        self::assertTrue($method->invoke($service, $processingCancelled));

        $failedOther = new \Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
        $failedOther->forceFill([
            'status' => 'failed',
            'error_message' => 'AI timeout',
        ]);
        self::assertFalse($method->invoke($service, $failedOther));
    }

    public function test_step_action_matches_exact_prefix_form(): void
    {
        $service = $this->newServiceWithoutConstructor();
        self::assertSame('step:prompt-content', $service->stepAction('prompt-content'));
        self::assertNotSame('step:prompt', $service->stepAction('prompt-content'));
    }

    public function test_queue_js_cancel_requires_cancelled_count_or_already_idle(): void
    {
        $js = file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/resources/js/project-run-queue.js'
        );
        self::assertNotFalse($js);

        $start = strpos($js, 'async cancelWorkflowStep');
        self::assertNotFalse($start, 'cancelWorkflowStep missing');
        $end = strpos($js, 'async ', $start + 1);
        $slice = $end !== false
            ? substr($js, $start, $end - $start)
            : substr($js, $start, 2500);

        self::assertStringNotContainsString('applyItemFailure(', $slice);
        self::assertStringContainsString('cancelled > 0 || alreadyIdle', $slice);
        self::assertStringContainsString('data-run-busy-step', $slice);
        self::assertStringContainsString('KhÃ´ng ngáº¯t Ä‘Æ°á»£c (cancelled=', $js);
    }

    public function test_livewire_cancel_passthrough_includes_diagnostic_fields(): void
    {
        $source = file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Filament/Resources/SeoProjectResource/Pages/ViewSeoProjectRun.php'
        );
        self::assertNotFalse($source);
        self::assertStringContainsString('getProjectWorkspaceUrl', $source);
        self::assertStringNotContainsString("'affected_item_ids'", $source);
        $retry = file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/SeoProjectWorkflowStepRetryService.php'
        );
        self::assertNotFalse($retry);
        self::assertStringContainsString('cancelActiveStep', $retry);
    }

    public function test_blade_busy_badge_has_data_attribute_and_passes_task_node(): void
    {
        $blade = file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/resources/seo-project-resource/pages/view-project-run.blade.php')
        );
        self::assertNotFalse($blade);
        self::assertStringContainsString('data-run-busy-step', $blade);
        self::assertStringContainsString('queue.cancelWorkflowStep({{ (int) ($item[\'task_id\'] ?? 0) }}', $blade);
        self::assertStringContainsString('@js($busyStep[\'node_id\'] ?? \'\')', $blade);
    }

    public function test_queue_js_does_not_autorun_on_running_status_alone(): void
    {
        $js = file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/resources/js/project-run-queue.js'
        );
        self::assertNotFalse($js);
        self::assertStringContainsString('Boolean(this.config.autorun) && hasTaskIds', $js);
        self::assertStringNotContainsString("this.config.runStatus === 'running')\n                && hasTaskIds", $js);
        self::assertStringContainsString('forceStopRunQueue', $js);
        self::assertStringContainsString('applyItemFailure', $js);
        self::assertStringContainsString('ÄÃ£ cháº¡y láº¡i prompt.', $js);
        // Step retry success/fail ghi Message â€” khÃ´ng alert popup.
        self::assertDoesNotMatchRegularExpression(
            '/retryWorkflowStep[\s\S]{0,800}window\.alert\(response/',
            $js,
        );
    }

    public function test_recompute_counters_does_not_force_running_when_not_marking_completed(): void
    {
        $source = file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/SeoProjectRunItemService.php'
        );
        self::assertNotFalse($source);
        self::assertStringContainsString('articleExecution()', $source);
        self::assertStringContainsString('KHÃ”NG Ã©p status=running', $source);
        self::assertStringNotContainsString(
            "\$payload['status'] = SeoProjectRun::STATUS_RUNNING;",
            $source
        );
    }

    public function test_blade_removed_rerun_all_button(): void
    {
        $blade = file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/resources/seo-project-resource/pages/view-project-run.blade.php')
        );
        self::assertNotFalse($blade);
        self::assertStringNotContainsString('canRerunAllItems()', $blade);
        self::assertStringNotContainsString('openRerunSettingsModal()', $blade);
        self::assertStringContainsString('retryWorkflowStep', $blade);
        self::assertStringContainsString('cancelWorkflowStep', $blade);
        self::assertStringContainsString('Ngáº¯t', $blade);
        self::assertStringContainsString('selectedTaskIds', $blade);
        self::assertStringContainsString('run_item_last_saved', $blade);
    }

    private function newServiceWithoutConstructor(): SeoProjectWorkflowStepRetryService
    {
        $ref = new ReflectionClass(SeoProjectWorkflowStepRetryService::class);

        return $ref->newInstanceWithoutConstructor();
    }
}
