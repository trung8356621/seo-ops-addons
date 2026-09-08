<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\Models\ApiConnection;
use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\DataTransfer\ModelContextCapability;
use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;
use Omnichannel\Addons\AiPrompt\PromptBudget\DirectFitStrategy;
use Omnichannel\Addons\AiPrompt\PromptBudget\LongFormArticleSplitStrategy;
use Omnichannel\Addons\AiPrompt\PromptBudget\PromptSplitStrategyRegistry;
use Omnichannel\Addons\AiPrompt\Services\AiModelRouterService;
use Omnichannel\Addons\AiPrompt\Services\AiRuntimeHealthService;
use Omnichannel\Addons\AiPrompt\Services\ArticleGenerationExecutionPlanner;
use Omnichannel\Addons\AiPrompt\Services\ModelContextCapabilityResolver;
use Omnichannel\Addons\AiPrompt\Services\PromptRunnerService;
use Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\OpenAiCompatibleProtocolAdapter;
use Omnichannel\Addons\AiPrompt\Support\ArticleContentGenerationHooks;
use Omnichannel\Addons\AiPrompt\Support\ArticleGenerationShape;
use Omnichannel\Addons\AiPrompt\Support\ArticleGenerationStrategy;
use Omnichannel\Addons\AiPrompt\Support\ArticleGenerationStrategyResolver;
use Omnichannel\Addons\AiPrompt\Support\ArticleOutboundCeilingPolicy;
use Omnichannel\Addons\AiPrompt\Support\ArticlePrimaryRoutingSnapshot;
use ReflectionClass;
use Tests\TestCase;

/**
 * Regression suite: AI Center model authority + prompt shape + no output throttling.
 */
final class ArticleGenerationModelAuthorityRegressionTest extends TestCase
{
    public function test_a_paid_primary_derives_single_pass_shape(): void
    {
        $primary = $this->candidate('anthropic/claude-sonnet', isFree: false, id: 1);
        $shape = ArticleGenerationShape::fromPrimaryIsFree($primary->isFree);
        $snap = ArticlePrimaryRoutingSnapshot::fromCandidate($primary, $shape);

        self::assertSame(ArticleGenerationShape::SinglePass, $shape);
        self::assertSame('single_pass', $snap->generationShape->value);
        self::assertSame('anthropic/claude-sonnet', $snap->primaryModel);
        self::assertFalse($snap->primaryIsFree);
        self::assertSame(ArticleGenerationShape::SOURCE_AI_CENTER_PRIMARY, $snap->generationShapeSource);
    }

    public function test_b_free_primary_derives_sectioned_shape(): void
    {
        $primary = $this->candidate('nvidia/nemotron-free', isFree: true, id: 2);
        $shape = ArticleGenerationShape::fromPrimaryIsFree($primary->isFree);

        self::assertSame(ArticleGenerationShape::Sectioned, $shape);
        self::assertTrue($shape->isSectioned());
    }

    public function test_c_first_attemptable_preserves_ai_center_order(): void
    {
        // Contract: resolveFirstAttemptable exists and walks AI Center order (source inspection).
        $src = file_get_contents((string) (new ReflectionClass(AiModelRouterService::class))->getFileName()) ?: '';
        self::assertStringContainsString('function resolveFirstAttemptable', $src);
        self::assertStringContainsString('skipReason', $src);
        self::assertStringNotContainsString('capability_mismatch', substr(
            $src,
            (int) strpos($src, 'function resolveFirstAttemptable'),
            1200,
        ));
    }

    public function test_d_article_execute_planned_route_quarantines_budget(): void
    {
        $src = file_get_contents((string) (new ReflectionClass(PromptRunnerService::class))->getFileName()) ?: '';
        self::assertStringContainsString('article_budget_quarantined', $src);
        self::assertStringContainsString('omit_application_output_ceiling', $src);
        self::assertStringNotContainsString("'max_output' => 1200", $src);
    }

    public function test_e_openrouter_adapter_omits_max_tokens_for_article(): void
    {
        $src = file_get_contents((string) (new ReflectionClass(OpenAiCompatibleProtocolAdapter::class))->getFileName()) ?: '';
        self::assertStringContainsString('ArticleOutboundCeilingPolicy::shouldOmitApplicationCeiling', $src);
        self::assertTrue(ArticleOutboundCeilingPolicy::shouldOmitApplicationCeiling([
            'hook_key' => ArticleContentGenerationHooks::GENERATE,
            'max_output' => 1100,
        ]));
        self::assertFalse(ArticleOutboundCeilingPolicy::shouldOmitApplicationCeiling([
            'hook_key' => 'keyword.discovery',
            'max_output' => 1100,
        ]));
    }

    public function test_f_reasoning_does_not_mutate_max_output(): void
    {
        $src = file_get_contents((string) (new ReflectionClass(ModelContextCapabilityResolver::class))->getFileName()) ?: '';
        self::assertStringNotContainsString('0.55', $src);
        self::assertStringContainsString('Do NOT mutate max output for reasoning models', $src);
        // Configured 8192 must remain 8192 — no * 0.55 path in resolver.
        self::assertStringNotContainsString('floor($maxOut *', $src);
    }

    public function test_g_free_only_is_independent_of_sectioned_shape(): void
    {
        $src = file_get_contents((string) (new ReflectionClass(PromptRunnerService::class))->getFileName()) ?: '';
        self::assertStringContainsString('freeOnly: false', $src);
        self::assertStringContainsString("isolationMode: 'sectioned_generation'", $src);

        $orch = file_get_contents(
            (string) (new ReflectionClass(\Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeHookOrchestrator::class))->getFileName(),
        ) ?: '';
        self::assertStringContainsString('freeOnly: false', $orch);
        self::assertStringNotContainsString('freeOnly: true', $orch);
    }

    public function test_h_null_override_does_not_force_single_pass_for_free_primary(): void
    {
        $resolver = new ArticleGenerationStrategyResolver();
        // Empty variables alone default single_pass — planner must stamp shape from primary.
        self::assertSame(ArticleGenerationStrategy::SinglePass, $resolver->resolve([]));

        $primary = $this->candidate('nvidia/nemotron', isFree: true, id: 5);
        $shape = ArticleGenerationShape::fromPrimaryIsFree($primary->isFree);
        $vars = ArticlePrimaryRoutingSnapshot::fromCandidate($primary, $shape)->mergeIntoVariables([
            'generation_strategy_override' => null,
        ]);

        self::assertSame('sectioned', $vars['generation_shape']);
        self::assertSame(ArticleGenerationStrategy::Sectioned, $resolver->resolve($vars));
        self::assertNull($vars['generation_strategy_override']);
    }

    public function test_sectioned_free_alias_maps_to_sectioned_shape(): void
    {
        self::assertSame(
            ArticleGenerationShape::Sectioned,
            ArticleGenerationShape::tryFromMixed('sectioned_free'),
        );
        self::assertTrue(ArticleGenerationStrategy::resolve('sectioned_free')->isSectioned());
        self::assertSame('sectioned', ArticleGenerationStrategy::resolve('sectioned_free')->canonical()->value);
    }

    public function test_long_form_strategy_no_longer_owns_article_generate(): void
    {
        $registry = new PromptSplitStrategyRegistry();
        $strategy = $registry->forHook('article.content.generate');
        self::assertInstanceOf(DirectFitStrategy::class, $strategy);
        self::assertNotInstanceOf(LongFormArticleSplitStrategy::class, $strategy);
        self::assertFalse($strategy->supportsSplit());
    }

    public function test_planner_stamps_primary_preference(): void
    {
        $primary = $this->candidate('nvidia/nemotron', isFree: true, id: 42, connectionId: 7);
        $shape = ArticleGenerationShape::fromPrimaryIsFree($primary->isFree);
        $vars = ArticlePrimaryRoutingSnapshot::fromCandidate($primary, $shape)->mergeIntoVariables([]);

        self::assertTrue($shape->isSectioned());
        self::assertSame('42', $vars['_article_primary_model_id']);
        self::assertSame(42, $vars['primary_model_id']);
        self::assertSame(ArticleGenerationShape::SOURCE_AI_CENTER_PRIMARY, $vars['generation_shape_source']);
        self::assertArrayNotHasKey('_item_model_override_id', $vars);
    }

    public function test_tracked_call_allows_paid_section_fallback(): void
    {
        $src = file_get_contents(
            (string) (new ReflectionClass(\Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeTrackedProviderCall::class))->getFileName(),
        ) ?: '';
        self::assertStringNotContainsString('SECTIONED_FREE_NON_FREE_MODEL_SELECTED', $src);
    }

    /**
     * @return RoutedAiCandidate
     */
    private function candidate(string $model, bool $isFree, int $id, int $priority = 1, int $connectionId = 1): RoutedAiCandidate
    {
        $connection = new ApiConnection([
            'id' => $connectionId,
            'name' => 'conn-'.$connectionId,
            'provider' => 'openrouter',
            'paid_locked' => false,
        ]);
        $connection->id = $connectionId;

        return new RoutedAiCandidate(
            profile: 'article.generate',
            connection: $connection,
            provider: 'openrouter',
            model: $model,
            capabilities: [],
            priority: $priority,
            isFree: $isFree,
            seoAiModelId: $id,
        );
    }
}
