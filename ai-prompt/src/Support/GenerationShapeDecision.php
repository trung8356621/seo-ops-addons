<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;

/**
 * Immutable per-run snapshot: first usable route cost_class → execution shape.
 */
final class GenerationShapeDecision
{
    public function __construct(
        public readonly ArticleGenerationShape $shape,
        public readonly string $source,
        public readonly string $logicalModel,
        public readonly string $physicalRoute,
        public readonly string $provider,
        public readonly ?int $connectionId,
        public readonly string $connectionName,
        public readonly string $costClass,
        public readonly string $model,
        public readonly ?int $modelId,
        public readonly bool $isFree,
    ) {}

    public static function fromCandidate(
        RoutedAiCandidate $candidate,
        string $source = ArticleGenerationShape::SOURCE_ROUTE_COST_AUTO,
    ): self {
        $isFree = $candidate->isFree;

        return new self(
            shape: $isFree ? ArticleGenerationShape::Sectioned : ArticleGenerationShape::SinglePass,
            source: $source,
            logicalModel: $candidate->logicalModelKey(),
            physicalRoute: $candidate->physicalRouteKey(),
            provider: $candidate->provider,
            connectionId: (int) $candidate->connection->id > 0
                ? (int) $candidate->connection->id
                : null,
            connectionName: (string) ($candidate->connection->name ?? ''),
            costClass: $isFree ? 'free' : 'paid',
            model: $candidate->model,
            modelId: $candidate->seoAiModelId,
            isFree: $isFree,
        );
    }

    /**
     * Rehydrate from an already-snapshotted run (shape must not change mid-run).
     *
     * @param  array<string, mixed>  $variables
     */
    public static function tryFromVariables(array $variables): ?self
    {
        $source = trim((string) ($variables['generation_shape_source'] ?? ''));
        $shape = ArticleGenerationShape::tryFromMixed($variables['generation_shape'] ?? null);
        if ($shape === null || $source !== ArticleGenerationShape::SOURCE_ROUTE_COST_AUTO) {
            return null;
        }

        $costClass = strtolower(trim((string) ($variables['shape_decision_cost_class'] ?? '')));
        if ($costClass === '') {
            $costClass = ! empty($variables['primary_is_free']) ? 'free' : 'paid';
        }
        if (! in_array($costClass, ['free', 'paid'], true)) {
            $costClass = $shape->isSectioned() ? 'free' : 'paid';
        }

        $connectionId = isset($variables['shape_decision_connection_id'])
            ? (int) $variables['shape_decision_connection_id']
            : (isset($variables['primary_connection_id']) ? (int) $variables['primary_connection_id'] : null);
        if ($connectionId !== null && $connectionId <= 0) {
            $connectionId = null;
        }

        $modelId = isset($variables['primary_model_id']) ? (int) $variables['primary_model_id'] : null;
        if ($modelId !== null && $modelId <= 0) {
            $modelId = null;
        }

        return new self(
            shape: $shape,
            source: ArticleGenerationShape::SOURCE_ROUTE_COST_AUTO,
            logicalModel: trim((string) ($variables['shape_decision_logical_model'] ?? $variables['primary_model'] ?? '')),
            physicalRoute: trim((string) ($variables['shape_decision_physical_route'] ?? '')),
            provider: trim((string) ($variables['shape_decision_provider'] ?? '')),
            connectionId: $connectionId,
            connectionName: trim((string) ($variables['shape_decision_connection_name'] ?? '')),
            costClass: $costClass,
            model: trim((string) ($variables['primary_model'] ?? $variables['shape_decision_logical_model'] ?? '')),
            modelId: $modelId,
            isFree: $costClass === 'free',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toVariableFields(): array
    {
        $split = $this->shape->isSectioned();

        return [
            'generation_shape' => $this->shape->value,
            'generation_shape_source' => $this->source,
            'generation_strategy' => $this->shape->value,
            'resolved_generation_strategy' => $this->shape->value,
            '_item_generation_strategy' => $this->shape->value,
            'strategy_resolved' => $this->shape->value,
            'strategy_source' => $this->source,
            'generation_strategy_override' => null,
            'strategy_override' => null,
            'primary_model' => $this->model,
            'primary_model_id' => $this->modelId,
            'primary_connection_id' => $this->connectionId,
            'primary_is_free' => $this->isFree,
            'shape_decision_logical_model' => $this->logicalModel,
            'shape_decision_physical_route' => $this->physicalRoute,
            'shape_decision_provider' => $this->provider,
            'shape_decision_connection_id' => $this->connectionId,
            'shape_decision_connection_name' => $this->connectionName,
            'shape_decision_cost_class' => $this->costClass,
            // Derived mirrors for legacy readers — NOT runtime authority.
            'writing_split_enabled' => $split,
            'pass_mode' => $split ? 'multiple_pass' : 'single_pass',
            'writing_scope' => $split
                ? WritingSectionScopeInstructions::SCOPE_SECTION
                : WritingSectionScopeInstructions::SCOPE_ARTICLE,
            'outline_split_enabled' => $split,
        ];
    }
}
