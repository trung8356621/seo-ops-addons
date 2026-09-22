<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\Models\ApiConnection;
use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;
use Omnichannel\Addons\AiPrompt\Services\AiCandidatePlanner;
use Omnichannel\Addons\AiPrompt\Support\AiCostPolicy;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionRoutingMode;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionTransport;
use Omnichannel\Addons\AiPrompt\Support\AiRoutingPolicy;
use Omnichannel\Addons\AiPrompt\Services\EffectiveAiRoutingPolicyResolver;
use Omnichannel\Addons\AiPrompt\Services\PromptRoutingPolicyResolver;
use Omnichannel\Addons\AiPrompt\Support\InteractiveAiAttemptBudget;
use PHPUnit\Framework\TestCase;

/**
 * Routing policy composition over manual-priority candidates (no provider HTTP).
 */
final class AiRoutingPolicyPlannerTest extends TestCase
{
    public function test_normal_preserves_free_first_then_paid_stream_when_first_usable_is_free(): void
    {
        [$plan, $ordered] = $this->plan(
            AiRoutingPolicy::Normal,
            [$this->free('free-a', 1), $this->free('free-b', 2), $this->paid('paid-c', 3)],
        );

        $this->assertSame(AiRoutingPolicy::Normal->value, $plan->meta['routing_policy']);
        $models = array_map(static fn (RoutedAiCandidate $c): string => $c->model, $ordered);
        $this->assertSame(['free-a', 'free-b'], array_values(array_filter(
            $models,
            static fn (string $m): bool => str_starts_with($m, 'free-'),
        )));
        $this->assertContains('paid-c', $models);
    }

    public function test_free_only_never_includes_paid(): void
    {
        [$plan, $ordered] = $this->plan(
            AiRoutingPolicy::FreeOnly,
            [$this->free('free-a', 1), $this->paid('paid-c', 2), $this->free('free-b', 3)],
        );

        $this->assertSame(AiRoutingPolicy::FreeOnly->value, $plan->meta['routing_policy']);
        foreach ($ordered as $candidate) {
            $this->assertTrue($candidate->isFree, $candidate->model.' must be free');
        }
        $models = array_map(static fn (RoutedAiCandidate $c): string => $c->model, $ordered);
        $this->assertSame(['free-a', 'free-b'], $models);
    }

    public function test_quick_free_caps_free_budget_to_one(): void
    {
        [$plan] = $this->plan(
            AiRoutingPolicy::QuickFree,
            [$this->free('free-a', 1), $this->free('free-b', 2), $this->paid('paid-c', 3)],
            maxFreeAttempts: 5,
        );

        $this->assertSame(1, (int) ($plan->budget['max_free_attempts'] ?? 0));
        $this->assertLessThanOrEqual(1, (int) ($plan->budget['free_budget'] ?? 0));
    }

    public function test_quick_free_with_no_free_uses_paid_only(): void
    {
        [$plan, $ordered] = $this->plan(
            AiRoutingPolicy::QuickFree,
            [$this->paid('paid-x', 1), $this->paid('paid-y', 2)],
        );

        $this->assertSame(['paid-x', 'paid-y'], array_map(
            static fn (RoutedAiCandidate $c): string => $c->model,
            $ordered,
        ));
        $this->assertFalse((bool) ($plan->meta['free_first_secondary_paid'] ?? false));
    }

    public function test_global_free_only_overrides_quick_free(): void
    {
        $resolved = (new EffectiveAiRoutingPolicyResolver())->resolve(
            prompt: null,
            hookKey: 'seeding.comment.generate',
            explicit: AiRoutingPolicy::QuickFree,
            context: new AiRoutingContext(
                freeOnly: true,
                costPolicy: AiCostPolicy::FreeOnly,
                hookKey: 'seeding.comment.generate',
            ),
        );

        $this->assertSame(AiRoutingPolicy::QuickFree, $resolved['requested']);
        $this->assertSame(AiRoutingPolicy::FreeOnly, $resolved['effective']);
        $this->assertTrue($resolved['global_free_only']);
    }

    public function test_hook_default_seeding_comment_is_quick_free(): void
    {
        $policy = (new PromptRoutingPolicyResolver())->hookDefault('seeding.comment.generate');
        $this->assertSame(AiRoutingPolicy::QuickFree, $policy);
    }

    public function test_missing_policy_defaults_to_normal(): void
    {
        $policy = (new PromptRoutingPolicyResolver())->hookDefault('article.content.generate');
        $this->assertSame(AiRoutingPolicy::Normal, $policy);
    }

    public function test_interactive_budget_is_bounded(): void
    {
        $budget = (new InteractiveAiAttemptBudget())->resolve(AiRoutingPolicy::QuickFree);
        $this->assertSame(3, $budget['max_ai_attempts']);
        $this->assertSame(1, $budget['max_free_attempts']);
    }

    public function test_manual_free_priority_preserved_under_free_only(): void
    {
        [, $ordered] = $this->plan(
            AiRoutingPolicy::FreeOnly,
            [$this->free('free-b', 2), $this->free('free-a', 1), $this->paid('paid-c', 3)],
        );

        // Planner keeps input relative order among eligible free rows.
        $this->assertSame(['free-b', 'free-a'], array_map(
            static fn (RoutedAiCandidate $c): string => $c->model,
            $ordered,
        ));
    }

    /**
     * @param  list<RoutedAiCandidate>  $candidates
     * @return array{0: \Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingPlan, 1: list<RoutedAiCandidate>}
     */
    private function plan(
        AiRoutingPolicy $policy,
        array $candidates,
        int $maxFreeAttempts = 3,
    ): array {
        $context = new AiRoutingContext(
            hookKey: 'test.hook',
            routingMode: $policy === AiRoutingPolicy::FreeOnly
                ? AiExecutionRoutingMode::FreeOnly
                : AiExecutionRoutingMode::FreeFirstWithPaidFallback,
            routingPolicy: $policy,
            routingPolicyRequested: $policy,
            routingPolicyEffective: $policy,
            executionTransport: AiExecutionTransport::Interactive,
            freeOnly: $policy === AiRoutingPolicy::FreeOnly,
            costPolicy: $policy === AiRoutingPolicy::FreeOnly ? AiCostPolicy::FreeOnly : AiCostPolicy::Default,
            maxAiAttempts: 4,
            maxFreeAttempts: $maxFreeAttempts,
        );

        $paid = array_values(array_filter($candidates, static fn (RoutedAiCandidate $c): bool => ! $c->isFree));

        return (new AiCandidatePlanner())->plan(
            profile: 'text.fast',
            context: $context,
            candidates: $candidates,
            maxAiAttempts: 4,
            maxFreeAttempts: $maxFreeAttempts,
            healthSkipReason: static fn (RoutedAiCandidate $c): ?string => null,
            modelArea: 'fast_text',
            secondaryCandidates: $paid,
            primaryArea: 'fast_text',
            secondaryArea: 'fast_text',
        );
    }

    private function free(string $model, int $priority): RoutedAiCandidate
    {
        return $this->candidate($model, $priority, true);
    }

    private function paid(string $model, int $priority): RoutedAiCandidate
    {
        return $this->candidate($model, $priority, false);
    }

    private function candidate(string $model, int $priority, bool $isFree): RoutedAiCandidate
    {
        $connection = new ApiConnection();
        $connection->forceFill([
            'id' => $priority + ($isFree ? 100 : 200),
            'provider' => $isFree ? 'openrouter' : 'deepseek',
            'name' => $model.'-conn',
            'status' => 'active',
        ]);

        return new RoutedAiCandidate(
            profile: 'text.fast',
            connection: $connection,
            provider: (string) $connection->provider,
            model: $model,
            capabilities: ['text.generate'],
            priority: $priority,
            options: [],
            seoAiModelId: $priority,
            isFree: $isFree,
        );
    }
}
