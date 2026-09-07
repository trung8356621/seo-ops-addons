<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

/**
 * Canonical strategy resolution for article writing.
 * Single place — Controllers/hooks must not re-implement ad-hoc.
 */
final class ArticleGenerationStrategyResolver
{
    /**
     * @param  array<string, mixed>  $variables
     */
    public function resolve(array $variables): ArticleGenerationStrategy
    {
        foreach ([
            'generation_strategy',
            '_item_generation_strategy',
            'resolved_generation_strategy',
            'generation_strategy_override',
        ] as $key) {
            $strategy = ArticleGenerationStrategy::tryFromMixed($variables[$key] ?? null);
            if ($strategy !== null) {
                return $strategy;
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
        $variables['generation_strategy'] = $strategy->value;
        $variables['_item_generation_strategy'] = $strategy->value;
        $variables['resolved_generation_strategy'] = $strategy->value;

        return $variables;
    }
}
