<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos;

/**
 * @phpstan-type TemplateVariable array{key: string, label: string, type: string, required?: bool, default?: mixed}
 */
final class AgentChatTemplate
{
    /**
     * @param  list<TemplateVariable>  $variables
     */
    public function __construct(
        public readonly string $key,
        public readonly string $title,
        public readonly string $description,
        public readonly string $promptTemplate,
        public readonly ?string $skillKey = null,
        public readonly array $variables = [],
        public readonly string $category = 'general',
        public readonly string $icon = 'heroicon-o-document-text',
        public readonly int $sortOrder = 100,
        public readonly bool $isFeatured = false,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $variables = $data['variables'] ?? [];
        if (! is_array($variables)) {
            $variables = [];
        }

        $skillKey = $data['skill_key'] ?? null;

        return new self(
            key: (string) ($data['key'] ?? ''),
            title: (string) ($data['title'] ?? ''),
            description: (string) ($data['description'] ?? ''),
            promptTemplate: (string) ($data['prompt_template'] ?? ''),
            skillKey: is_string($skillKey) && $skillKey !== '' ? $skillKey : null,
            variables: array_values($variables),
            category: (string) ($data['category'] ?? 'general'),
            icon: (string) ($data['icon'] ?? 'heroicon-o-document-text'),
            sortOrder: (int) ($data['sort_order'] ?? 100),
            isFeatured: (bool) ($data['is_featured'] ?? false),
        );
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function render(array $values): string
    {
        $prompt = $this->promptTemplate;
        foreach ($this->variables as $variable) {
            $key = (string) ($variable['key'] ?? '');
            if ($key === '') {
                continue;
            }

            // Keep {{placeholder}} when value missing — caller must resolve before submit.
            if (! array_key_exists($key, $values) && ! array_key_exists('default', $variable)) {
                continue;
            }

            $value = array_key_exists($key, $values)
                ? $values[$key]
                : ($variable['default'] ?? '');
            $prompt = str_replace('{{'.$key.'}}', (string) $value, $prompt);
        }

        return $prompt;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return list<string>
     */
    public function missingVariables(array $values): array
    {
        $missing = [];
        foreach ($this->variables as $variable) {
            $key = (string) ($variable['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $required = (bool) ($variable['required'] ?? true);
            if (! $required) {
                continue;
            }
            $value = $values[$key] ?? null;
            if ($value === null || (is_string($value) && trim($value) === '')) {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    public function hasUnresolvedPlaceholders(string $rendered): bool
    {
        return (bool) preg_match('/\{\{[a-zA-Z0-9_]+\}\}/', $rendered);
    }
}
