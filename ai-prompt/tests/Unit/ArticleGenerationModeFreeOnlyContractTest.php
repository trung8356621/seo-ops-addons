<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;
use Omnichannel\Addons\AiPrompt\Services\AiCandidatePlanner;
use Omnichannel\Addons\AiPrompt\Services\AiRoutingContextResolver;
use Omnichannel\Addons\AiPrompt\Services\ArticleGenerationExecutionPlanner;
use Omnichannel\Addons\AiPrompt\Services\EffectiveAiCostPolicyResolver;
use Omnichannel\Addons\AiPrompt\Services\GenerationShapeResolver;
use Omnichannel\Addons\AiPrompt\Contracts\FirstAttemptableAiRouteResolver;
use Omnichannel\Addons\AiPrompt\Support\AiCostPolicy;
use Omnichannel\Addons\AiPrompt\Support\AiCostPolicyScope;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionRoutingMode;
use Omnichannel\Addons\AiPrompt\Support\ArticleGenerationModePreference;
use Omnichannel\Addons\AiPrompt\Support\ArticleGenerationShape;
use Omnichannel\Addons\AiPrompt\Support\PromptTaskFreeOnlyPolicy;
use Omnichannel\Addons\ContentProjects\Support\ContentProjectRunSettings;
use Omnichannel\Addons\Seo\Livewire\GlobalSeoBar;
use App\Models\ApiConnection;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\ProjectRoot;

/**
 * User-facing article generation mode NORMAL | FREE_ONLY — policy only, not shape algorithm.
 */
final class ArticleGenerationModeFreeOnlyContractTest extends TestCase
{
    public function test_a_normal_free_first_split_paid_still_eligible(): void
    {
        $free = $this->candidate('nemotron-free', true, 1);
        $paid = $this->candidate('deepseek-chat', false, 2);
        $ctx = new AiRoutingContext(userId: 1, costPolicy: AiCostPolicy::Default);
        $mode = (new AiRoutingContextResolver())->resolveMode($ctx);
        self::assertSame(AiExecutionRoutingMode::FreeFirstWithPaidFallback, $mode);

        [$plan] = (new AiCandidatePlanner())->plan(
            'text.longform',
            $ctx,
            [$free, $paid],
            8,
            8,
            static fn (): ?string => null,
        );

        self::assertTrue($plan->mode->allowsPaidRoutes());
        self::assertSame('nemotron-free', $plan->executionOrder[0]->candidate->model ?? null);
        self::assertNotEmpty(array_filter(
            $plan->executionOrder,
            static fn ($r): bool => ! $r->candidate->isFree,
        ));

        [, $snap] = $this->planShape($free, $ctx);
        self::assertSame(ArticleGenerationShape::Sectioned, $snap->generationShape);
        self::assertFalse($snap->freeOnlyPolicy);
    }

    public function test_b_normal_paid_first_single(): void
    {
        $paid = $this->candidate('claude-sonnet', false, 1);
        $ctx = new AiRoutingContext(userId: 1, costPolicy: AiCostPolicy::Default);
        [, $snap] = $this->planShape($paid, $ctx);
        self::assertSame(ArticleGenerationShape::SinglePass, $snap->generationShape);
        self::assertFalse($snap->freeOnlyPolicy);
    }

    public function test_c_free_only_paid_sorted_first_policy_excluded_free_split(): void
    {
        $paid = $this->candidate('deepseek-chat', false, 1);
        $free = $this->candidate('nemotron-free', true, 2);
        $ctx = new AiRoutingContext(userId: 1, costPolicy: AiCostPolicy::FreeOnly);
        $enriched = (new AiRoutingContextResolver())->enrich($ctx);

        [$plan, $survivors] = (new AiCandidatePlanner())->plan(
            'text.longform',
            $enriched,
            [$paid, $free],
            8,
            8,
            static fn (): ?string => null,
        );

        self::assertSame(AiExecutionRoutingMode::FreeOnly, $plan->mode);
        self::assertNotEmpty($plan->paidPhase);
        self::assertSame('policy_free_only', $plan->paidPhase[0]->staticEligibility['reason'] ?? null);
        self::assertFalse($plan->paidPhase[0]->staticEligibility['eligible'] ?? true);
        self::assertCount(1, $survivors);
        self::assertTrue($survivors[0]->isFree);
        self::assertSame('nemotron-free', $survivors[0]->model);
        // Order preserved: paid remains earlier in configured order but filtered by policy.
        self::assertSame(1, $paid->priority);
        self::assertSame(2, $free->priority);

        [, $snap] = $this->planShape($free, $ctx);
        self::assertSame(ArticleGenerationShape::Sectioned, $snap->generationShape);
        self::assertTrue($snap->freeOnlyPolicy);
    }

    public function test_d_free_only_no_usable_free_is_routing_failure_zero_paid_attempts(): void
    {
        $paid = $this->candidate('claude-sonnet', false, 1);
        $ctx = (new AiRoutingContextResolver())->enrich(
            new AiRoutingContext(userId: 1, costPolicy: AiCostPolicy::FreeOnly),
        );

        [$plan, $survivors] = (new AiCandidatePlanner())->plan(
            'text.longform',
            $ctx,
            [$paid],
            8,
            8,
            static fn (): ?string => null,
        );

        self::assertSame([], $survivors);
        self::assertFalse($plan->mode->allowsPaidRoutes());
        self::assertSame(0, (int) ($plan->budget['required_paid_fallback_reserve'] ?? 0));
    }

    public function test_e_free_only_healthy_paid_still_policy_excluded(): void
    {
        $paid = $this->candidate('deepseek-chat', false, 1);
        $free = $this->candidate('nemotron-free', true, 2);
        $ctx = (new AiRoutingContextResolver())->enrich(
            new AiRoutingContext(userId: 1, costPolicy: AiCostPolicy::FreeOnly),
        );

        [$plan] = (new AiCandidatePlanner())->plan(
            'text.longform',
            $ctx,
            [$paid, $free],
            8,
            8,
            // Healthy paid — still excluded by policy, not health.
            static fn (): ?string => null,
        );

        $paidPlanned = $plan->paidPhase[0];
        self::assertFalse($paidPlanned->candidate->isFree);
        self::assertSame('policy_free_only', $paidPlanned->staticEligibility['reason'] ?? null);
        self::assertNull($paidPlanned->staticEligibility['runtime_health_skip'] ?? null);
    }

    public function test_f_normal_after_free_only_new_run_allows_paid(): void
    {
        $freeOnlySnap = ContentProjectRunSettings::snapshotForRun([
            AiCostPolicy::SETTING_KEY => AiCostPolicy::FreeOnly->value,
            'task_ids' => [1],
        ]);
        self::assertSame(AiCostPolicy::FreeOnly->value, $freeOnlySnap[AiCostPolicy::SETTING_KEY]);

        $normalSnap = ContentProjectRunSettings::snapshotForRun([
            AiCostPolicy::SETTING_KEY => AiCostPolicy::Default->value,
            'task_ids' => [2],
        ]);
        self::assertSame(AiCostPolicy::Default->value, $normalSnap[AiCostPolicy::SETTING_KEY]);

        $paid = $this->candidate('claude-sonnet', false, 1);
        $ctx = new AiRoutingContext(
            userId: 1,
            costPolicy: AiCostPolicy::tryFromMixed($normalSnap[AiCostPolicy::SETTING_KEY]),
        );
        [, $snap] = $this->planShape($paid, $ctx);
        self::assertSame(ArticleGenerationShape::SinglePass, $snap->generationShape);
        self::assertFalse($snap->freeOnlyPolicy);
    }

    public function test_g_mode_immutable_mid_run_via_snapshot(): void
    {
        $variables = ArticleGenerationModePreference::stampIntoVariables([
            AiCostPolicy::SETTING_KEY => AiCostPolicy::FreeOnly->value,
        ], 9);
        self::assertSame(AiCostPolicy::FreeOnly->value, $variables[AiCostPolicy::SETTING_KEY]);

        // Live preference change must not rewrite an already-stamped run bag.
        $again = ArticleGenerationModePreference::stampIntoVariables($variables, 9);
        self::assertSame(AiCostPolicy::FreeOnly->value, $again[AiCostPolicy::SETTING_KEY]);

        $effectiveDuringRun = (new EffectiveAiCostPolicyResolver())->resolve(
            contextPolicy: AiCostPolicy::Default, // UI flipped to NORMAL
            variables: $again,
        );
        self::assertTrue($effectiveDuringRun->isFreeOnly());
    }

    public function test_h_explicit_micro_task_free_only_survives_normal_mode(): void
    {
        self::assertTrue(PromptTaskFreeOnlyPolicy::requires('article.comment.generate'));

        $effective = (new EffectiveAiCostPolicyResolver())->resolve(
            contextPolicy: AiCostPolicy::Default,
            hookKey: 'article.comment.generate',
            variables: [AiCostPolicy::SETTING_KEY => AiCostPolicy::Default->value],
        );
        self::assertTrue($effective->isFreeOnly());
    }

    public function test_i_connection_constraint_independent_of_generation_mode(): void
    {
        $planner = (string) file_get_contents(
            (string) (new ReflectionClass(ArticleGenerationExecutionPlanner::class))->getFileName(),
        );
        // Generation freeOnlyPolicy must not call connection paid_locked helper.
        self::assertStringNotContainsString('connectionFreeOnlyPolicy', $planner);
        self::assertStringNotContainsString('$connection->paid_locked', $planner);
        self::assertStringContainsString('EffectiveAiCostPolicyResolver', $planner);

        $form = (string) file_get_contents(
            ProjectRoot::addonsPath().'/ai-prompt/src/Support/ApiConnectionFormSchema.php',
        );
        self::assertStringContainsString('free_only_form_label', $form);
        self::assertStringContainsString("Toggle::make('paid_locked')", $form);
    }

    public function test_j_stage_independence_outline_split_content_single(): void
    {
        $reasoningFree = $this->candidate('nemotron-free', true, 1);
        $longformPaid = $this->candidate('deepseek-chat', false, 1);
        $normal = new AiRoutingContext(userId: 1, costPolicy: AiCostPolicy::Default);

        [, $outlineSnap] = $this->planShape($reasoningFree, $normal);
        [, $contentSnap] = $this->planShape($longformPaid, $normal);

        self::assertSame(ArticleGenerationShape::Sectioned, $outlineSnap->generationShape);
        self::assertSame(ArticleGenerationShape::SinglePass, $contentSnap->generationShape);
    }

    public function test_ui_lives_in_global_seo_bar_tao_bai_bang_ai(): void
    {
        $bar = (string) file_get_contents((string) (new ReflectionClass(GlobalSeoBar::class))->getFileName());
        $blade = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo/resources/views/livewire/global-seo-bar.blade.php',
        );

        self::assertStringContainsString('articleGenerationMode', $bar);
        self::assertStringContainsString('ArticleGenerationModePreference', $bar);
        self::assertStringContainsString('wire:model.live="articleGenerationMode"', $blade);
        self::assertStringContainsString('generation_mode_normal', $blade);
        self::assertStringContainsString('generation_mode_free_only', $blade);
        self::assertStringContainsString('settings.ai.free_single_split', $blade);
        self::assertStringContainsString('ai_generation_heading', $blade);
    }

    public function test_scope_run_snapshots_policy_without_live_preference_read(): void
    {
        $ran = false;
        AiCostPolicyScope::run(AiCostPolicy::FreeOnly, function () use (&$ran): void {
            $ran = true;
            $effective = (new EffectiveAiCostPolicyResolver())->resolve(
                contextPolicy: AiCostPolicyScope::current(),
            );
            self::assertTrue($effective->isFreeOnly());
        });
        self::assertTrue($ran);
        self::assertSame(AiCostPolicy::Default, AiCostPolicyScope::current());
    }

    /**
     * @return array{0: RoutedAiCandidate, 1: \Omnichannel\Addons\AiPrompt\Support\ArticlePrimaryRoutingSnapshot}
     */
    private function planShape(RoutedAiCandidate $primary, AiRoutingContext $ctx): array
    {
        $router = $this->createMock(FirstAttemptableAiRouteResolver::class);
        $router->method('resolveFirstAttemptable')->willReturn($primary);
        $planner = new ArticleGenerationExecutionPlanner($router, new GenerationShapeResolver($router));
        [$p, $snap] = $planner->plan('text.longform', $ctx, []);

        return [$p, $snap];
    }

    private function candidate(
        string $model,
        bool $isFree,
        int $priority,
        string $provider = 'openrouter',
        string $connectionName = 'OR',
    ): RoutedAiCandidate {
        $connection = new ApiConnection([
            'id' => 10 + $priority,
            'name' => $connectionName,
            'provider' => $provider,
            'paid_locked' => false,
        ]);
        $connection->id = 10 + $priority;

        return new RoutedAiCandidate(
            profile: 'text.longform',
            connection: $connection,
            provider: $provider,
            model: $model,
            capabilities: [],
            priority: $priority,
            seoAiModelId: 100 + $priority,
            isFree: $isFree,
        );
    }
}
