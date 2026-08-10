<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos;

/**
 * Presentation/application mapping for an Agent Skill.
 * Not a second capability registry — capability availability comes from CanonicalCapabilityRegistry.
 *
 * @phpstan-type FormField array{key: string, label: string, type: string, required?: bool, options?: list<array{value: string, label: string}>, default?: mixed, help?: string}
 * @phpstan-type AvailabilityPolicy array{requires_context?: list<string>, provider?: string|null, extension?: string|null, feature_flag?: string|null, min_role?: string|null, status_override?: string|null}
 */
final class AgentSkillDefinition
{
    /**
     * @param  list<string>  $aliases
     * @param  array<string, mixed>  $inputSchema
     * @param  list<FormField>  $formSchema
     * @param  list<string>  $examplePrompts
     * @param  list<string>  $requiredScopes
     * @param  AvailabilityPolicy  $availabilityPolicy
     * @param  array<string, mixed>  $resultPresentation
     */
    public function __construct(
        public readonly string $key,
        public readonly string $slashCommand,
        public readonly string $name,
        public readonly string $description,
        public readonly string $category,
        public readonly string $capability,
        public readonly string $icon = 'heroicon-o-sparkles',
        public readonly array $aliases = [],
        public readonly array $inputSchema = [],
        public readonly array $formSchema = [],
        public readonly array $examplePrompts = [],
        public readonly array $requiredScopes = [],
        public readonly string $confirmationPolicy = 'none',
        public readonly array $availabilityPolicy = [],
        public readonly array $resultPresentation = [],
        public readonly int $sortOrder = 100,
        public readonly bool $isFeatured = false,
        public readonly bool $isHidden = false,
        public readonly bool $isComingSoon = false,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $aliases = $data['aliases'] ?? [];
        if (! is_array($aliases)) {
            $aliases = [];
        }

        $formSchema = $data['form_schema'] ?? [];
        if (! is_array($formSchema)) {
            $formSchema = [];
        }

        $examplePrompts = $data['example_prompts'] ?? [];
        if (! is_array($examplePrompts)) {
            $examplePrompts = [];
        }

        $requiredScopes = $data['required_scopes'] ?? [];
        if (! is_array($requiredScopes)) {
            $requiredScopes = [];
        }

        $availabilityPolicy = $data['availability_policy'] ?? [];
        if (! is_array($availabilityPolicy)) {
            $availabilityPolicy = [];
        }

        $resultPresentation = $data['result_presentation'] ?? [];
        if (! is_array($resultPresentation)) {
            $resultPresentation = [];
        }

        $inputSchema = $data['input_schema'] ?? [];
        if (! is_array($inputSchema)) {
            $inputSchema = [];
        }

        return new self(
            key: (string) ($data['key'] ?? ''),
            slashCommand: (string) ($data['slash_command'] ?? ''),
            name: (string) ($data['name'] ?? ''),
            description: (string) ($data['description'] ?? ''),
            category: (string) ($data['category'] ?? 'general'),
            capability: (string) ($data['capability'] ?? ''),
            icon: (string) ($data['icon'] ?? 'heroicon-o-sparkles'),
            aliases: array_values(array_filter(array_map('strval', $aliases))),
            inputSchema: $inputSchema,
            formSchema: array_values($formSchema),
            examplePrompts: array_values(array_filter(array_map('strval', $examplePrompts))),
            requiredScopes: array_values(array_filter(array_map('strval', $requiredScopes))),
            confirmationPolicy: (string) ($data['confirmation_policy'] ?? 'none'),
            availabilityPolicy: $availabilityPolicy,
            resultPresentation: $resultPresentation,
            sortOrder: (int) ($data['sort_order'] ?? 100),
            isFeatured: (bool) ($data['is_featured'] ?? false),
            isHidden: (bool) ($data['is_hidden'] ?? false),
            isComingSoon: (bool) ($data['is_coming_soon'] ?? false),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'slash_command' => $this->slashCommand,
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category,
            'capability' => $this->capability,
            'icon' => $this->icon,
            'aliases' => $this->aliases,
            'input_schema' => $this->inputSchema,
            'form_schema' => $this->formSchema,
            'example_prompts' => $this->examplePrompts,
            'required_scopes' => $this->requiredScopes,
            'confirmation_policy' => $this->confirmationPolicy,
            'availability_policy' => $this->availabilityPolicy,
            'result_presentation' => $this->resultPresentation,
            'sort_order' => $this->sortOrder,
            'is_featured' => $this->isFeatured,
            'is_hidden' => $this->isHidden,
            'is_coming_soon' => $this->isComingSoon,
        ];
    }
}
