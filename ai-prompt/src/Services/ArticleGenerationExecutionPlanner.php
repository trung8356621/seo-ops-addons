<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\Contracts\FirstAttemptableAiRouteResolver;
use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;
use Omnichannel\Addons\AiPrompt\Support\ArticleGenerationShape;
use Omnichannel\Addons\AiPrompt\Support\ArticlePrimaryRoutingSnapshot;
use Omnichannel\Addons\AiPrompt\Support\GenerationShapeDecision;

/**
 * Resolves first usable AI Center route, then derives Writing/Outline execution shape
 * from that route's cost_class (FREE→SPLIT, PAID→SINGLE). Shape is snapshotted once
 * per run and must not change during fallback.
 */
final class ArticleGenerationExecutionPlanner
{
    public function __construct(
        private readonly FirstAttemptableAiRouteResolver $router,
        private readonly ?GenerationShapeResolver $shapeResolver = null,
    ) {}

    /**
     * @param  array<string, mixed>  $variables
     * @return array{0: RoutedAiCandidate, 1: ArticlePrimaryRoutingSnapshot, 2: array<string, mixed>}
     */
    public function plan(string $profile, AiRoutingContext $routingContext, array $variables = []): array
    {
        $resolver = $this->shapeResolver ?? new GenerationShapeResolver($this->router);
        [$primary, $decision] = $resolver->resolve($profile, $routingContext, $variables);

        // Generation FreeOnly only — connection paid_locked is a separate constraint.
        $freeOnlyPolicy = (new EffectiveAiCostPolicyResolver())->resolveForContext(
            $routingContext,
            $variables,
        )->isFreeOnly();

        // When reusing an immutable shape snapshot, keep decision fields from $decision
        // but prefer the live first-usable candidate for prefer-primary routing metadata
        // only when shape was freshly resolved from that candidate.
        $snapshot = $this->buildSnapshot($primary, $decision, $freeOnlyPolicy, $variables);

        $merged = $snapshot->mergeIntoVariables($variables);
        foreach ($decision->toVariableFields() as $key => $value) {
            // Decision fields win for shape authority; do not let legacy preference keys
            // in $variables override after mergeIntoVariables.
            $merged[$key] = $value;
        }

        // Re-apply prefer-primary model id from live first-usable when shape was frozen
        // from a prior decision but routing still needs a current primary pointer.
        if ($primary->seoAiModelId !== null && $primary->seoAiModelId > 0) {
            $merged['_article_primary_model_id'] = (string) $primary->seoAiModelId;
        }

        return [$primary, $snapshot, $merged];
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    private function buildSnapshot(
        RoutedAiCandidate $primary,
        GenerationShapeDecision $decision,
        bool $freeOnlyPolicy,
        array $variables,
    ): ArticlePrimaryRoutingSnapshot {
        $reused = GenerationShapeDecision::tryFromVariables($variables) !== null;

        if ($reused) {
            return ArticlePrimaryRoutingSnapshot::fromDecision($decision, $freeOnlyPolicy);
        }

        // Fresh decision is always from $primary cost class.
        return ArticlePrimaryRoutingSnapshot::fromCandidate(
            $primary,
            $decision->shape,
            $freeOnlyPolicy,
            ArticleGenerationShape::SOURCE_ROUTE_COST_AUTO,
        );
    }

}
