<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

/**
 * Snapshot generation strategy provenance for History / debug.
 */
final class ArticleGenerationStrategySnapshot
{
    public const SOURCE_TASK_OVERRIDE = 'seo_project_tasks.generation_strategy_override';

    public const SOURCE_VARIABLES = 'workflow_variables';

    public const SOURCE_DEFAULT = 'default';

    public function __construct(
        public readonly ?string $strategyOverride,
        public readonly string $strategyResolved,
        public readonly string $strategySource,
    ) {}

    /**
     * @param  array<string, mixed>  $variables
     * @param  string|null  $taskOverride  Explicit task column value (null = column NULL / none).
     *                                    Pass false to ignore task column and read variables only.
     */
    public static function fromVariables(array $variables, string|false|null $taskOverride = false): self
    {
        $overrideRaw = null;
        if ($taskOverride !== false) {
            $overrideRaw = is_string($taskOverride) && trim($taskOverride) !== ''
                ? trim($taskOverride)
                : null;
        } elseif (isset($variables['generation_strategy_override'])) {
            $raw = trim((string) $variables['generation_strategy_override']);
            $overrideRaw = $raw !== '' ? $raw : null;
        }

        $override = ArticleGenerationStrategy::tryFromMixed($overrideRaw);

        if ($override !== null) {
            return new self(
                strategyOverride: $override->value,
                strategyResolved: $override->value,
                strategySource: self::SOURCE_TASK_OVERRIDE,
            );
        }

        $resolved = (new ArticleGenerationStrategyResolver())->resolve(array_filter([
            'generation_strategy' => $variables['generation_strategy'] ?? null,
            '_item_generation_strategy' => $variables['_item_generation_strategy'] ?? null,
            'resolved_generation_strategy' => $variables['resolved_generation_strategy'] ?? null,
        ], static fn (mixed $v): bool => $v !== null && $v !== ''));

        if (
            ArticleGenerationStrategy::tryFromMixed($variables['generation_strategy'] ?? null) !== null
            || ArticleGenerationStrategy::tryFromMixed($variables['_item_generation_strategy'] ?? null) !== null
            || ArticleGenerationStrategy::tryFromMixed($variables['resolved_generation_strategy'] ?? null) !== null
        ) {
            $source = self::SOURCE_VARIABLES;
        } else {
            $source = self::SOURCE_DEFAULT;
        }

        return new self(
            strategyOverride: null,
            strategyResolved: $resolved->value,
            strategySource: $source,
        );
    }

    /**
     * @return array{
     *   strategy_override: ?string,
     *   strategy_resolved: string,
     *   strategy_source: string,
     *   generation_strategy: string,
     *   resolved_generation_strategy: string
     * }
     */
    public function toArray(): array
    {
        return [
            'strategy_override' => $this->strategyOverride,
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
        // Explicit null when task has no override — History must show "Task override: none".
        $variables['generation_strategy_override'] = $this->strategyOverride;
        $variables['_item_generation_strategy'] = $this->strategyResolved;

        return $variables;
    }

    /**
     * Fields for PromptResult / run-step snapshots (parent writing execution).
     *
     * @return array{
     *   strategy_override: ?string,
     *   strategy_resolved: string,
     *   strategy_source: string,
     *   generation_strategy: string,
     *   resolved_generation_strategy: string,
     *   generation_strategy_override: ?string,
     *   task_id: ?int
     * }
     */
    public function toExecutionSnapshot(?int $taskId = null): array
    {
        return [
            'strategy_override' => $this->strategyOverride,
            'strategy_resolved' => $this->strategyResolved,
            'strategy_source' => $this->strategySource,
            'generation_strategy' => $this->strategyResolved,
            'resolved_generation_strategy' => $this->strategyResolved,
            'generation_strategy_override' => $this->strategyOverride,
            'task_id' => $taskId !== null && $taskId > 0 ? $taskId : null,
        ];
    }
}
