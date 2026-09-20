<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

/**
 * Snapshot generation strategy provenance for History / debug.
 *
 * New-run authority is generation_shape (route_cost_auto). Override fields are
 * recorded as null on new stamps; resolveFromHistory may still read old rows.
 */
final class ArticleGenerationStrategySnapshot
{
    public const SOURCE_TASK_OVERRIDE = 'seo_project_tasks.generation_strategy_override';

    public const SOURCE_VARIABLES = 'workflow_variables';

    public const SOURCE_DEFAULT = 'default';

    public const SOURCE_ROUTE_COST_AUTO = 'route_cost_auto';

    public function __construct(
        public readonly ?string $strategyOverride,
        public readonly string $strategyResolved,
        public readonly string $strategySource,
    ) {}

    /**
     * @param  array<string, mixed>  $variables
     * @param  string|false|null  $taskOverride  Ignored for new-run authority (kept for call-site compat).
     */
    public static function fromVariables(array $variables, string|false|null $taskOverride = false): self
    {
        unset($taskOverride);

        $resolved = (new ArticleGenerationStrategyResolver())->resolve([
            'generation_shape' => $variables['generation_shape'] ?? null,
            'generation_strategy' => $variables['generation_strategy'] ?? null,
            '_item_generation_strategy' => $variables['_item_generation_strategy'] ?? null,
            'resolved_generation_strategy' => $variables['resolved_generation_strategy'] ?? null,
        ]);

        $shapeSource = trim((string) ($variables['generation_shape_source'] ?? ''));
        if ($shapeSource !== '') {
            $source = $shapeSource;
        } elseif (
            ArticleGenerationShape::tryFromMixed($variables['generation_shape'] ?? null) !== null
            || ArticleGenerationStrategy::tryFromMixed($variables['generation_strategy'] ?? null) !== null
            || ArticleGenerationStrategy::tryFromMixed($variables['_item_generation_strategy'] ?? null) !== null
            || ArticleGenerationStrategy::tryFromMixed($variables['resolved_generation_strategy'] ?? null) !== null
        ) {
            $source = self::SOURCE_VARIABLES;
        } else {
            $source = self::SOURCE_DEFAULT;
        }

        return new self(
            strategyOverride: null,
            strategyResolved: $resolved->canonical()->value,
            strategySource: $source,
        );
    }

    /**
     * @return array{
     *   strategy_override: null,
     *   strategy_resolved: string,
     *   strategy_source: string,
     *   generation_strategy: string,
     *   resolved_generation_strategy: string
     * }
     */
    public function toArray(): array
    {
        return [
            'strategy_override' => null,
            'strategy_resolved' => $this->strategyResolved,
            'strategy_source' => $this->strategySource,
            'generation_strategy' => $this->strategyResolved,
            'resolved_generation_strategy' => $this->strategyResolved,
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
        $variables['generation_strategy_override'] = null;
        $variables['_item_generation_strategy'] = $this->strategyResolved;

        return $variables;
    }

    /**
     * Fields for PromptResult / run-step snapshots (parent writing execution).
     *
     * @return array{
     *   strategy_override: null,
     *   strategy_resolved: string,
     *   strategy_source: string,
     *   generation_strategy: string,
     *   resolved_generation_strategy: string,
     *   generation_strategy_override: null,
     *   task_id: ?int
     * }
     */
    public function toExecutionSnapshot(?int $taskId = null): array
    {
        return [
            'strategy_override' => null,
            'strategy_resolved' => $this->strategyResolved,
            'strategy_source' => $this->strategySource,
            'generation_strategy' => $this->strategyResolved,
            'resolved_generation_strategy' => $this->strategyResolved,
            'generation_strategy_override' => null,
            'task_id' => $taskId !== null && $taskId > 0 ? $taskId : null,
        ];
    }
}
