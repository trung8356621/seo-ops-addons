<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data;

final readonly class AgentProposedPlanStep
{
    /**
     * @param  array<string, mixed>  $input
     * @param  list<int>  $dependsOn
     * @param  array<string, string>  $outputBindings
     */
    public function __construct(
        public int $index,
        public string $skillKey,
        public array $input = [],
        public array $dependsOn = [],
        public array $outputBindings = [],
        public string $title = '',
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $depends = [];
        foreach ($data['depends_on'] ?? [] as $dep) {
            if (is_numeric($dep)) {
                $depends[] = (int) $dep;
            }
        }

        $bindings = [];
        foreach ($data['output_bindings'] ?? [] as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $bindings[$k] = $v;
            }
        }

        return new self(
            index: (int) ($data['index'] ?? 0),
            skillKey: (string) ($data['skill_key'] ?? ''),
            input: is_array($data['input'] ?? null) ? $data['input'] : [],
            dependsOn: $depends,
            outputBindings: $bindings,
            title: (string) ($data['title'] ?? ''),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'index' => $this->index,
            'skill_key' => $this->skillKey,
            'input' => $this->input,
            'depends_on' => $this->dependsOn,
            'output_bindings' => $this->outputBindings,
            'title' => $this->title,
        ];
    }
}
