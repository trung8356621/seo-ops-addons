<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

/**
 * Canonical strategy resolution for article writing.
 *
 * For article.content.generate production flow, prefer
 * {@see \Omnichannel\Addons\AiPrompt\Services\ArticleGenerationExecutionPlanner}
 * (shape from first usable route cost_class). This resolver remains for legacy stamps / History.
 */
final class ArticleGenerationStrategyResolver
{
    /**
     * @param  array<string, mixed>  $variables
     */
    public function resolve(array $variables): ArticleGenerationStrategy
    {
        // Prefer runtime-derived generation_shape when present.
        $fromShape = ArticleGenerationShape::tryFromMixed($variables['generation_shape'] ?? null);
        if ($fromShape !== null) {
            return $fromShape->toLegacyStrategy();
        }

        foreach ([
            'generation_strategy',
            '_item_generation_strategy',
            'resolved_generation_strategy',
            'generation_strategy_override',
        ] as $key) {
            $strategy = ArticleGenerationStrategy::tryFromMixed($variables[$key] ?? null);
            if ($strategy !== null) {
                return $strategy->canonical();
            }
        }

        return ArticleGenerationStrategy::SinglePass;
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public function stamp(array $variables, ArticleGenerationStrategy $strategy): array
    {
        $canonical = $strategy->canonical();
        $variables['generation_strategy'] = $canonical->value;
        $variables['_item_generation_strategy'] = $canonical->value;
        $variables['resolved_generation_strategy'] = $canonical->value;
        $variables['generation_shape'] = $canonical->toShape()->value;

        return $variables;
    }
}
