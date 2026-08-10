<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data;

final readonly class AgentProposedIntent
{
    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $missingFields
     */
    public function __construct(
        public string $skillKey,
        public array $input = [],
        public array $missingFields = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $missing = $data['missing_fields'] ?? [];
        if (! is_array($missing)) {
            $missing = [];
        }

        return new self(
            skillKey: (string) ($data['skill_key'] ?? ''),
            input: is_array($data['input'] ?? null) ? $data['input'] : [],
            missingFields: array_values(array_filter(array_map('strval', $missing))),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'skill_key' => $this->skillKey,
            'input' => $this->input,
            'missing_fields' => $this->missingFields,
        ];
    }
}
