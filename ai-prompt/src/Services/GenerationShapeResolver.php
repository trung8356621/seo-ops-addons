<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\Contracts\FirstAttemptableAiRouteResolver;
use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;
use Omnichannel\Addons\AiPrompt\Support\ArticleGenerationShape;
use Omnichannel\Addons\AiPrompt\Support\GenerationShapeDecision;

/**
 * SSOT for article execution shape.
 *
 * AI Center sortable → first usable physical route → FREE=SPLIT / PAID=SINGLE.
 * Ignores writing_split_enabled, outline_split_enabled, Economy/BestQuality, provider identity.
 */
final class GenerationShapeResolver
{
    public function __construct(
        private readonly FirstAttemptableAiRouteResolver $router,
    ) {}

    /**
     * Resolve once per run. Reuses an existing route_cost_auto snapshot (immutable mid-run).
     *
     * @param  array<string, mixed>  $variables
     * @return array{0: RoutedAiCandidate, 1: GenerationShapeDecision}
     */
    public function resolve(string $profile, AiRoutingContext $routingContext, array $variables = []): array
    {
        $existing = GenerationShapeDecision::tryFromVariables($variables);
        $primary = $this->router->resolveFirstAttemptable($profile, $routingContext);

        if ($existing !== null) {
            // Shape frozen for this run — still return current first-usable for prefer-primary routing.
            return [$primary, $existing];
        }

        return [$primary, GenerationShapeDecision::fromCandidate(
            $primary,
            ArticleGenerationShape::SOURCE_ROUTE_COST_AUTO,
        )];
    }

    /**
     * Shape only (outline gate / observability). Throws normal routing failure when no usable route.
     *
     * @param  array<string, mixed>  $variables
     */
    public function resolveDecision(string $profile, AiRoutingContext $routingContext, array $variables = []): GenerationShapeDecision
    {
        [, $decision] = $this->resolve($profile, $routingContext, $variables);

        return $decision;
    }
}