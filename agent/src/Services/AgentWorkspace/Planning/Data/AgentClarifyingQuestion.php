<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data;

final readonly class AgentClarifyingQuestion
{
    /**
     * @param  list<array{value: string, label: string}>  $options
     */
    public function __construct(
        public string $key,
        public string $question,
        public string $inputType = 'text',
        public bool $required = true,
        public array $options = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $options = [];
        foreach ($data['options'] ?? [] as $opt) {
            if (! is_array($opt)) {
                continue;
            }
            $options[] = [
                'value' => (string) ($opt['value'] ?? ''),
                'label' => (string) ($opt['label'] ?? ($opt['value'] ?? '')),
            ];
        }

        return new self(
            key: (string) ($data['key'] ?? ''),
            question: (string) ($data['question'] ?? ''),
            inputType: (string) ($data['input_type'] ?? 'text'),
            required: (bool) ($data['required'] ?? true),
            options: $options,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'question' => $this->question,
            'input_type' => $this->inputType,
            'required' => $this->required,
            'options' => $this->options,
        ];
    }
}
