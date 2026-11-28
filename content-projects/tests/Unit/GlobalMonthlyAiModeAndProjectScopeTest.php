<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\Services\AiRoutingContextResolver;
use Omnichannel\Addons\AiPrompt\Services\EffectiveAiCostPolicyResolver;
use Omnichannel\Addons\AiPrompt\Support\AiCostPolicy;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionRoutingMode;
use Omnichannel\Addons\ContentProjects\Filament\Pages\GlobalMonthlyGeneratorPage;
use Omnichannel\Addons\ContentProjects\Models\SeoContentGlobalBatch;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\StartGlobalMonthlyBatchCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\StartGlobalMonthlyBatchHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly\GlobalAutoGenerationPolicy;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly\GlobalMonthlyAiPolicy;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly\GlobalMonthlyBatchOrchestrator;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly\GlobalMonthlyBatchProgressReadModel;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class GlobalMonthlyAiModeAndProjectScopeTest extends TestCase
{
    public function test_auto_cost_is_default_and_mode_options_cover_three_modes(): void
    {
        $page = new GlobalMonthlyGeneratorPage;
        self::assertSame(GlobalMonthlyAiPolicy::AUTO_COST, $page->aiCostPolicy);

        $options = GlobalMonthlyAiPolicy::modeOptions();
        self::assertSame(['auto_cost', 'free_only', 'default'], array_keys($options));
        self::assertSame('Auto Cost', $options['auto_cost']['label']);
        self::assertSame('Free Split', $options['free_only']['label']);
        self::assertSame('Paid Single', $options['default']['label']);
    }

    public function test_free_split_and_paid_single_isolation_rules_never_cross_fallback(): void
    {
        // Free Split: FreeOnly -> never allows Paid routes
        $freePolicy = GlobalMonthlyAiPolicy::normalize(AiCostPolicy::FreeOnly->value);
        $freeSettings = GlobalMonthlyAiPolicy::childRunSettings($freePolicy);
        $effectiveFree = (new EffectiveAiCostPolicyResolver)->resolve(variables: $freeSettings);
        self::assertTrue($effectiveFree->isFreeOnly());

        $freeCtx = new AiRoutingContext(
            costPolicy: $effectiveFree,
            freeOnly: $effectiveFree->isFreeOnly(),
            itemGenerationMode: $freeSettings['ai_generation_mode'],
        );
        self::assertSame(
            AiExecutionRoutingMode::FreeOnly,
            (new AiRoutingContextResolver)->resolveMode($freeCtx),
        );

        // Paid Single: Default + paid_preferred -> PaidPreferred (never falls back to Free)
        $paidPolicy = GlobalMonthlyAiPolicy::normalize(AiCostPolicy::Default->value);
        $paidSettings = array_merge(GlobalMonthlyAiPolicy::childRunSettings($paidPolicy), [
            '_item_generation_mode' => 'paid_preferred',
            'global_auto_cost_paid_only' => true,
        ]);
        $effectivePaid = (new EffectiveAiCostPolicyResolver)->resolve(variables: $paidSettings);
        self::assertFalse($effectivePaid->isFreeOnly());

        $paidCtx = new AiRoutingContext(
            costPolicy: $effectivePaid,
            freeOnly: false,
            itemGenerationMode: $paidSettings['_item_generation_mode'],
        );
        self::assertSame(
            AiExecutionRoutingMode::PaidPreferred,
            (new AiRoutingContextResolver)->resolveMode($paidCtx),
        );
        self::assertTrue((new GlobalAutoGenerationPolicy)->settingsEnforcePaidOnly($paidSettings));
    }

    public function test_project_selection_defaults_single_multi_and_empty_gate(): void
    {
        $page = new GlobalMonthlyGeneratorPage;
        $page->planningMonth = '2026-11';
        $page->preflight = [
            'projects' => [
                ['project_id' => 11, 'name' => 'Alpha', 'eligible_count' => 5, 'pending_count' => 4, 'failed_count' => 1],
                ['project_id' => 12, 'name' => 'Beta', 'eligible_count' => 3, 'pending_count' => 3, 'failed_count' => 0],
                ['project_id' => 13, 'name' => 'DoneProj', 'eligible_count' => 0, 'pending_count' => 0, 'failed_count' => 0],
            ],
            'eligible_count' => 8,
            'failed_count' => 1,
            'legacy_active_runs' => [],
            'can_start' => true,
        ];

        // Default: selects only projects with eligible_count > 0 (skips completed project 13).
        $page->syncSelectedProjectsFromPreflight();
        self::assertSame([11, 12], $page->selectedProjectIds);
        self::assertTrue($page->selectAllProjects);
        self::assertTrue($page->canStartBatch());
        self::assertSame([
            'project_count' => 2,
            'eligible_count' => 8,
            'pending_count' => 7,
            'failed_count' => 1,
        ], $page->selectedSummary());

        // Single project selection updates summary accurately.
        $page->selectedProjectIds = [12];
        $page->updatedSelectedProjectIds();
        self::assertFalse($page->selectAllProjects);
        self::assertTrue($page->canStartBatch());
        self::assertSame([
            'project_count' => 1,
            'eligible_count' => 3,
            'pending_count' => 3,
            'failed_count' => 0,
        ], $page->selectedSummary());

        // No project selected -> cannot generate.
        $page->updatedSelectAllProjects(false);
        self::assertSame([], $page->selectedProjectIds);
        self::assertFalse($page->canStartBatch());
        self::assertSame('Chưa chọn Project hợp lệ để Generate.', $page->startBlockReason());

        // Select All re-selects eligible projects only.
        $page->updatedSelectAllProjects(true);
        self::assertSame([11, 12], $page->selectedProjectIds);
        self::assertTrue($page->canStartBatch());
    }

    public function test_ai_mode_and_project_scope_propagate_through_command_handler_and_orchestrator(): void
    {
        $cmd = new StartGlobalMonthlyBatchCommand('2026-11', AiCostPolicy::FreeOnly->value, [11, 12]);
        self::assertSame('free_only', $cmd->aiCostPolicy);
        self::assertSame([11, 12], $cmd->selectedProjectIds);

        $handlerSrc = (string) file_get_contents(
            (string) (new ReflectionClass(StartGlobalMonthlyBatchHandler::class))->getFileName(),
        );
        self::assertStringContainsString('normalizeBatchPolicy', $handlerSrc);
        self::assertStringContainsString('selected_project_ids', $handlerSrc);

        $orchSrc = (string) file_get_contents(
            (string) (new ReflectionClass(GlobalMonthlyBatchOrchestrator::class))->getFileName(),
        );
        self::assertStringContainsString("options['selected_project_ids']", $orchSrc);
        self::assertStringContainsString("'requested_ai_mode' => \$policyToken", $orchSrc);
        self::assertStringContainsString("'disable_paid_fallback' => true", $orchSrc);
        self::assertStringContainsString("'global_auto_cost_paid_only' => true", $orchSrc);
    }

    public function test_progress_and_history_views_display_requested_actual_mode_and_scope(): void
    {
        $blade = (string) realpath(
            dirname(__DIR__, 3).'/seo-content-ai-compat/resources/views/filament/pages/global-monthly-generator.blade.php',
        );
        $src = (string) file_get_contents($blade);

        self::assertStringContainsString('Mode yêu cầu:', $src);
        self::assertStringContainsString('Thực tế:', $src);
        self::assertStringContainsString('Phạm vi:', $src);
        self::assertStringContainsString("batch['project_count']", $src);
        self::assertStringContainsString("row['project_count']", $src);
        self::assertStringContainsString("row['free_succeeded']", $src);
        self::assertStringContainsString("row['paid_fallback_succeeded']", $src);

        $rmSrc = (string) file_get_contents(
            (string) (new ReflectionClass(GlobalMonthlyBatchProgressReadModel::class))->getFileName(),
        );
        self::assertStringContainsString("'requested_mode_label'", $rmSrc);
        self::assertStringContainsString("'actual_mode_label'", $rmSrc);
        self::assertStringContainsString("'project_count'", $rmSrc);
    }
}
