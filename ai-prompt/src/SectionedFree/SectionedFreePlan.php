<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\SectionedFree;

/**
 * Planning result for sectioned_free — units + budget metadata.
 *
 * @phpstan-type PlanMeta array{
 *   article_target_words: int,
 *   planned_unit_count: int,
 *   minimum_units_by_budget: int,
 *   max_section_target_words: int,
 *   insufficient_outline_material: bool,
 *   insufficient_reason: ?string,
 *   per_section_targets: list<array{section_id: string, target_words: int, label: string}>
 * }
 */
final class SectionedFreePlan
{
    /**
     * @param  list<SectionedFreeSectionUnit>  $units
     * @param  PlanMeta  $meta
     */
    public function __construct(
        public readonly array $units,
        public readonly array $meta,
    ) {}

    public function plannedUnitCount(): int
    {
        return count($this->units);
    }

    public function minimumUnitsByBudget(): int
    {
        return (int) ($this->meta['minimum_units_by_budget'] ?? 1);
    }

    public function articleTargetWords(): int
    {
        return (int) ($this->meta['article_target_words'] ?? 0);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'units' => array_map(
                static fn (SectionedFreeSectionUnit $unit): array => $unit->toArray(),
                $this->units,
            ),
            'meta' => $this->meta,
        ];
    }
}
