<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;

/**
 * Snapshot of the first usable AI Center route used to derive generation shape.
 * Prefer this candidate first at execution; do not disable normal fallback.
 * Shape is immutable for the run once stamped with route_cost_auto.
 */
final class ArticlePrimaryRoutingSnapshot
{
    public function __construct(
        public readonly string $primaryModel,
        public readonly ?int $primaryModelId,
        public readonly ?int $primaryConnectionId,
        public readonly bool $primaryIsFree,
        public readonly ArticleGenerationShape $generationShape,
        public readonly string $generationShapeSource = ArticleGenerationShape::SOURCE_ROUTE_COST_AUTO,
        public readonly bool $freeOnlyPolicy = false,
        public readonly string $shapeDecisionLogicalModel = '',
        public readonly string $shapeDecisionPhysicalRoute = '',
        public readonly string $shapeDecisionProvider = '',
        public readonly string $shapeDecisionConnectionName = '',
        public readonly string $shapeDecisionCostClass = '',
    ) {}

    public static function fromCandidate(
        RoutedAiCandidate $primary,
        ArticleGenerationShape $shape,
        bool $freeOnlyPolicy = false,
        string $shapeSource = ArticleGenerationShape::SOURCE_ROUTE_COST_AUTO,
    ): self {
        return new self(
            primaryModel: $primary->model,
            primaryModelId: $primary->seoAiModelId,
            primaryConnectionId: (int) $primary->connection->id > 0
                ? (int) $primary->connection->id
                : null,
            primaryIsFree: $primary->isFree,
            generationShape: $shape,
            generationShapeSource: $shapeSource,
            freeOnlyPolicy: $freeOnlyPolicy,
            shapeDecisionLogicalModel: $primary->logicalModelKey(),
            shapeDecisionPhysicalRoute: $primary->physicalRouteKey(),
            shapeDecisionProvider: $primary->provider,
            shapeDecisionConnectionName: (string) ($primary->connection->name ?? ''),
            shapeDecisionCostClass: $primary->isFree ? 'free' : 'paid',
        );
    }

    public static function fromDecision(
        GenerationShapeDecision $decision,
        bool $freeOnlyPolicy = false,
    ): self {
        return new self(
            primaryModel: $decision->model,
            primaryModelId: $decision->modelId,
            primaryConnectionId: $decision->connectionId,
            primaryIsFree: $decision->isFree,
            generationShape: $decision->shape,
            generationShapeSource: $decision->source,
            freeOnlyPolicy: $freeOnlyPolicy,
            shapeDecisionLogicalModel: $decision->logicalModel,
            shapeDecisionPhysicalRoute: $decision->physicalRoute,
            shapeDecisionProvider: $decision->provider,
            shapeDecisionConnectionName: $decision->connectionName,
            shapeDecisionCostClass: $decision->costClass,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'primary_model' => $this->primaryModel,
            'primary_model_id' => $this->primaryModelId,
            'primary_connection_id' => $this->primaryConnectionId,
            'primary_is_free' => $this->primaryIsFree,
            'generation_shape' => $this->generationShape->value,
            'generation_shape_source' => $this->generationShapeSource,
            'free_only_policy' => $this->freeOnlyPolicy,
            'shape_decision_logical_model' => $this->shapeDecisionLogicalModel,
            'shape_decision_physical_route' => $this->shapeDecisionPhysicalRoute,
            'shape_decision_provider' => $this->shapeDecisionProvider,
            'shape_decision_connection_id' => $this->primaryConnectionId,
            'shape_decision_connection_name' => $this->shapeDecisionConnectionName,
            'shape_decision_cost_class' => $this->shapeDecisionCostClass !== ''
                ? $this->shapeDecisionCostClass
                : ($this->primaryIsFree ? 'free' : 'paid'),
            // Compat mirrors for History / existing strategy fields.
            'generation_strategy' => $this->generationShape->value,
            'resolved_generation_strategy' => $this->generationShape->value,
            '_item_generation_strategy' => $this->generationShape->value,
            'strategy_resolved' => $this->generationShape->value,
            'strategy_source' => $this->generationShapeSource,
            'generation_strategy_override' => null,
            'strategy_override' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public function mergeIntoVariables(array $variables): array
    {
        foreach ($this->toArray() as $key => $value) {
            $variables[$key] = $value;
        }

        // Runtime primary audit only — never fabricate a user model override.
        // `_item_model_override_id` is reserved for explicit per-item user selection.
        if ($this->primaryModelId !== null && $this->primaryModelId > 0) {
            $variables['_article_primary_model_id'] = (string) $this->primaryModelId;
        }

        return $variables;
    }
}
