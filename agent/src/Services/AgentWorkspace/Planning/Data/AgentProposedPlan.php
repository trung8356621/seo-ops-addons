<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data;

final readonly class AgentProposedPlan
{
    /**
     * @param  list<AgentProposedPlanStep>  $steps
     */
    public function __construct(
        public array $steps = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $steps = [];
        foreach ($data['steps'] ?? [] as $row) {
            if (is_array($row)) {
                $steps[] = AgentProposedPlanStep::fromArray($row);
            }
        }

        return new self(steps: $steps);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'steps' => array_map(
                static fn (AgentProposedPlanStep $s): array => $s->toArray(),
                $this->steps,
            ),
        ];
    }
}
