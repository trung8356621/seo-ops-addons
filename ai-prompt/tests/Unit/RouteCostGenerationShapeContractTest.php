<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\Models\ApiConnection;
use Omnichannel\Addons\AiPrompt\Contracts\FirstAttemptableAiRouteResolver;
use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;
use Omnichannel\Addons\AiPrompt\Exceptions\AiRoutingException;
use Omnichannel\Addons\AiPrompt\Services\ArticleGenerationExecutionPlanner;
use Omnichannel\Addons\AiPrompt\Services\GenerationShapeResolver;
use Omnichannel\Addons\AiPrompt\Support\ArticleGenerationShape;
use Omnichannel\Addons\AiPrompt\Support\GenerationShapeDecision;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Final SSOT: AI Center sortable → first usable route → FREE=SPLIT / PAID=SINGLE.
 */
final class RouteCostGenerationShapeContractTest extends TestCase
{
    public function test_a_paid_first_healthy_is_single(): void
    {
        $paid = $this->candidate('deepseek-chat', false, 1, 'deepseek', 'DeepSeek Direct');
        $free = $this->candidate('nemotron-free', true, 2);
        [$primary, $snap, $vars] = $this->planReturning($paid, [
            'writing_split_enabled' => true,
            'outline_split_enabled' => true,
        ]);

        self::assertSame($paid->model, $primary->model);
        self::assertSame(ArticleGenerationShape::SinglePass, $snap->generationShape);
        self::assertSame(ArticleGenerationShape::SOURCE_ROUTE_COST_AUTO, $snap->generationShapeSource);
        self::assertSame('single_pass', $vars['generation_shape']);
        self::assertSame('route_cost_auto', $vars['generation_shape_source']);
        self::assertSame('paid', $vars['shape_decision_cost_class']);
        self::assertFalse($vars['writing_split_enabled']);
        self::assertSame('single_pass', $vars['pass_mode']);
        unset($free);
    }

    public function test_b_free_first_healthy_is_split(): void
    {
        $free = $this->candidate('nemotron-free', true, 1);
        [$primary, $snap, $vars] = $this->planReturning($free);

        self::assertSame($free->model, $primary->model);
        self::assertSame(ArticleGenerationShape::Sectioned, $snap->generationShape);
        self::assertSame('sectioned', $vars['generation_shape']);
        self::assertSame('free', $vars['shape_decision_cost_class']);
        self::assertTrue($vars['writing_split_enabled']);
        self::assertSame('multiple_pass', $vars['pass_mode']);
        self::assertTrue($vars['outline_split_enabled']);
    }

    public function test_c_paid_cooldown_skips_to_free_split(): void
    {
        // resolveFirstAttemptable already applied health skip → returns free.
        $free = $this->candidate('nemotron-free', true, 2);
        [, $snap, $vars] = $this->planReturning($free);

        self::assertSame(ArticleGenerationShape::Sectioned, $snap->generationShape);
        self::assertSame('free', $vars['shape_decision_cost_class']);
        self::assertSame('nemotron-free', $vars['primary_model']);
    }

    public function test_d_free_cooldown_skips_to_paid_single(): void
    {
        $paid = $this->candidate('claude-sonnet', false, 2);
        [, $snap, $vars] = $this->planReturning($paid);

        self::assertSame(ArticleGenerationShape::SinglePass, $snap->generationShape);
        self::assertSame('paid', $vars['shape_decision_cost_class']);
    }

    public function test_e_free_only_policy_excludes_paid_yielding_split(): void
    {
        // Under FreeOnly, resolveFirstAttemptable filters to free — shape follows free.
        $free = $this->candidate('nemotron-free', true, 2);
        $router = $this->createMock(FirstAttemptableAiRouteResolver::class);
        $router->method('resolveFirstAttemptable')->willReturn($free);
        $planner = new ArticleGenerationExecutionPlanner($router, new GenerationShapeResolver($router));

        [, $snap, $vars] = $planner->plan(
            'text.longform',
            new AiRoutingContext(userId: 1, freeOnly: true),
            [],
        );

        self::assertSame(ArticleGenerationShape::Sectioned, $snap->generationShape);
        self::assertTrue($snap->freeOnlyPolicy || $vars['primary_is_free']);
        self::assertSame('free', $vars['shape_decision_cost_class']);
    }

    public function test_f_split_shape_immutable_when_later_primary_is_paid(): void
    {
        $free = $this->candidate('nemotron-free', true, 1);
        $paid = $this->candidate('deepseek-chat', false, 2, 'deepseek', 'DeepSeek Direct');

        [, , $vars] = $this->planReturning($free);
        self::assertSame('sectioned', $vars['generation_shape']);

        // Mid-run: first usable becomes paid (free failed) — shape must stay SPLIT.
        [, $snap2, $vars2] = $this->planReturning($paid, $vars);
        self::assertSame(ArticleGenerationShape::Sectioned, $snap2->generationShape);
        self::assertSame('sectioned', $vars2['generation_shape']);
        self::assertSame('route_cost_auto', $vars2['generation_shape_source']);
        self::assertSame('free', $vars2['shape_decision_cost_class']);
        self::assertSame('nemotron-free', $vars2['primary_model']);
    }

    public function test_g_single_shape_immutable_when_later_primary_is_free(): void
    {
        $paid = $this->candidate('deepseek-chat', false, 1, 'deepseek', 'DeepSeek Direct');
        $free = $this->candidate('nemotron-free', true, 2);

        [, , $vars] = $this->planReturning($paid);
        self::assertSame('single_pass', $vars['generation_shape']);

        [, $snap2, $vars2] = $this->planReturning($free, $vars);
        self::assertSame(ArticleGenerationShape::SinglePass, $snap2->generationShape);
        self::assertSame('single_pass', $vars2['generation_shape']);
        self::assertSame('paid', $vars2['shape_decision_cost_class']);
        self::assertSame('deepseek-chat', $vars2['primary_model']);
    }

    public function test_h_no_usable_route_is_routing_failure(): void
    {
        $router = $this->createMock(FirstAttemptableAiRouteResolver::class);
        $router->method('resolveFirstAttemptable')
            ->willThrowException(AiRoutingException::noCandidate('text.longform', 'text.generate'));

        $planner = new ArticleGenerationExecutionPlanner($router, new GenerationShapeResolver($router));

        $this->expectException(AiRoutingException::class);
        $planner->plan('text.longform', new AiRoutingContext(userId: 1), []);
    }

    public function test_i_legacy_writing_split_enabled_does_not_change_shape(): void
    {
        $paid = $this->candidate('deepseek-chat', false, 1);
        [, $snap, $vars] = $this->planReturning($paid, [
            'writing_split_enabled' => true,
        ]);

        self::assertSame(ArticleGenerationShape::SinglePass, $snap->generationShape);
        self::assertSame('route_cost_auto', $vars['generation_shape_source']);
        self::assertFalse($vars['writing_split_enabled']);

        $free = $this->candidate('nemotron-free', true, 1);
        [, $snapFree, $varsFree] = $this->planReturning($free, [
            'writing_split_enabled' => false,
        ]);
        self::assertSame(ArticleGenerationShape::Sectioned, $snapFree->generationShape);
        self::assertTrue($varsFree['writing_split_enabled']);
    }

    public function test_j_missing_outline_split_enabled_does_not_force_split(): void
    {
        $paid = $this->candidate('claude-sonnet', false, 1);
        [, $snap, $vars] = $this->planReturning($paid, []);

        self::assertSame(ArticleGenerationShape::SinglePass, $snap->generationShape);
        self::assertFalse($vars['outline_split_enabled']);
        self::assertArrayNotHasKey('outline_split_enabled_forced', $vars);
    }

    public function test_decision_exposes_observability_fields(): void
    {
        $paid = $this->candidate('deepseek-chat', false, 9, 'deepseek', 'DeepSeek Direct', 44);
        [, , $vars] = $this->planReturning($paid);

        self::assertSame('deepseek', $vars['shape_decision_provider']);
        self::assertSame('DeepSeek Direct', $vars['shape_decision_connection_name']);
        self::assertSame(44, $vars['shape_decision_connection_id']);
        self::assertSame('paid', $vars['shape_decision_cost_class']);
        self::assertNotSame('', $vars['shape_decision_physical_route']);
        self::assertNotSame('', $vars['shape_decision_logical_model']);
    }

    public function test_from_route_cost_class_helpers(): void
    {
        self::assertSame(
            ArticleGenerationShape::Sectioned,
            ArticleGenerationShape::fromRouteCostClass('free'),
        );
        self::assertSame(
            ArticleGenerationShape::SinglePass,
            ArticleGenerationShape::fromRouteCostClass('paid'),
        );
        self::assertSame(
            ArticleGenerationShape::Sectioned,
            ArticleGenerationShape::fromPrimaryIsFree(true),
        );
    }

    public function test_resolve_first_attemptable_throws_when_all_health_skipped(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(\Omnichannel\Addons\AiPrompt\Services\AiModelRouterService::class))->getFileName(),
        );
        $sliceStart = (int) strpos($src, 'function resolveFirstAttemptable');
        $slice = substr($src, $sliceStart, 1800);
        self::assertStringContainsString('skipReason', $slice);
        self::assertStringContainsString('noCandidate', $slice);
        self::assertStringNotContainsString('still return AI Center #1', $slice);
    }

    public function test_planner_source_is_route_cost_not_writing_split(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ArticleGenerationExecutionPlanner::class))->getFileName(),
        );
        self::assertStringContainsString('GenerationShapeResolver', $src);
        self::assertStringContainsString('SOURCE_ROUTE_COST_AUTO', $src);
        self::assertStringNotContainsString('WritingSplitPreference', $src);
        self::assertStringNotContainsString('fromWritingSplitEnabled', $src);
    }

    public function test_decision_try_from_variables_requires_route_cost_source(): void
    {
        self::assertNull(GenerationShapeDecision::tryFromVariables([
            'generation_shape' => 'sectioned',
            'generation_shape_source' => ArticleGenerationShape::SOURCE_WRITING_SPLIT_PREFERENCE,
        ]));
        self::assertInstanceOf(GenerationShapeDecision::class, GenerationShapeDecision::tryFromVariables([
            'generation_shape' => 'sectioned',
            'generation_shape_source' => ArticleGenerationShape::SOURCE_ROUTE_COST_AUTO,
            'shape_decision_cost_class' => 'free',
            'primary_model' => 'nemotron',
        ]));
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array{0: RoutedAiCandidate, 1: \Omnichannel\Addons\AiPrompt\Support\ArticlePrimaryRoutingSnapshot, 2: array<string, mixed>}
     */
    private function planReturning(RoutedAiCandidate $firstUsable, array $variables = []): array
    {
        $router = $this->createMock(FirstAttemptableAiRouteResolver::class);
        $router->method('resolveFirstAttemptable')->willReturn($firstUsable);
        $planner = new ArticleGenerationExecutionPlanner($router, new GenerationShapeResolver($router));

        return $planner->plan('text.longform', new AiRoutingContext(userId: 1), $variables);
    }

    private function candidate(
        string $model,
        bool $isFree,
        int $id,
        string $provider = 'openrouter',
        string $connectionName = 'OpenRouter',
        int $connectionId = 1,
    ): RoutedAiCandidate {
        $connection = new ApiConnection([
            'id' => $connectionId,
            'name' => $connectionName,
            'provider' => $provider,
            'paid_locked' => false,
        ]);
        $connection->id = $connectionId;

        return new RoutedAiCandidate(
            profile: 'text.longform',
            connection: $connection,
            provider: $provider,
            model: $model,
            capabilities: [],
            priority: $id,
            isFree: $isFree,
            seoAiModelId: $id,
        );
    }
}
