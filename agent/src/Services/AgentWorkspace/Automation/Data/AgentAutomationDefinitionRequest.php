<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Data;

final readonly class AgentAutomationDefinitionRequest
{
    /**
     * @param  array<string, mixed>  $trigger
     * @param  list<array<string, mixed>>  $workflow
     * @param  array<string, mixed>|null  $condition
     * @param  array<string, mixed>|null  $notification
     * @param  array<string, mixed>|null  $policy
     */
    public function __construct(
        public string $name,
        public string $type,
        public array $trigger,
        public array $workflow,
        public ?string $description = null,
        public string $scopeType = 'site',
        public ?string $scopeRef = null,
        public ?array $condition = null,
        public ?array $notification = null,
        public ?array $policy = null,
        public bool $enabled = true,
        public ?string $timezone = null,
        public ?int $conversationId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromArray(array $raw): self
    {
        $workflow = is_array($raw['workflow'] ?? null) ? array_values($raw['workflow']) : [];
        $trigger = is_array($raw['trigger'] ?? null) ? $raw['trigger'] : [];

        return new self(
            name: trim((string) ($raw['name'] ?? '')),
            type: trim((string) ($raw['type'] ?? '')),
            trigger: $trigger,
            workflow: $workflow,
            description: isset($raw['description']) ? trim((string) $raw['description']) : null,
            scopeType: trim((string) ($raw['scope_type'] ?? 'site')),
            scopeRef: isset($raw['scope_ref']) ? trim((string) $raw['scope_ref']) : null,
            condition: is_array($raw['condition'] ?? null) ? $raw['condition'] : null,
            notification: is_array($raw['notification'] ?? null) ? $raw['notification'] : null,
            policy: is_array($raw['policy'] ?? null) ? $raw['policy'] : null,
            enabled: (bool) ($raw['enabled'] ?? true),
            timezone: isset($raw['timezone']) ? trim((string) $raw['timezone']) : null,
            conversationId: isset($raw['conversation_id']) ? (int) $raw['conversation_id'] : null,
        );
    }
}
