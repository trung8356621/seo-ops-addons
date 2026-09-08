<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;

/**
 * Snapshot of the AI Center primary candidate used to derive generation shape.
 * Prefer this candidate first at execution; do not disable normal fallback.
 */
final class ArticlePrimaryRoutingSnapshot
{
    public function __construct(
        public readonly string $primaryModel,
        public readonly ?int $primaryModelId,
        public readonly ?int $primaryConnectionId,
        public readonly bool $primaryIsFree,
        public readonly ArticleGenerationShape $generationShape,
        public readonly string $generationShapeSource = ArticleGenerationShape::SOURCE_AI_CENTER_PRIMARY,
        public readonly bool $freeOnlyPolicy = false,
    ) {}

    public static function fromCandidate(
        RoutedAiCandidate $primary,
        ArticleGenerationShape $shape,
        bool $freeOnlyPolicy = false,
        string $shapeSource = ArticleGenerationShape::SOURCE_AI_CENTER_PRIMARY,
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

        // Prefer primary during execution without hard-pinning (fallback remains).
        if ($this->primaryModelId !== null && $this->primaryModelId > 0) {
            $variables['_article_primary_model_id'] = (string) $this->primaryModelId;
            if (! isset($variables['_item_model_override_id']) || trim((string) $variables['_item_model_override_id']) === '') {
                $variables['_item_model_override_id'] = (string) $this->primaryModelId;
                $variables['_item_model_override_mode'] = 'preferred';
            }
        }

        return $variables;
    }
}
