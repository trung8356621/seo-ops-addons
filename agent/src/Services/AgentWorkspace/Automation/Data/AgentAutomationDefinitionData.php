<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Data;

final readonly class AgentAutomationDefinitionData
{
    public const TYPE_SCHEDULED_REPORT = 'scheduled_report';

    public const TYPE_CONDITION_WATCH = 'condition_watch';

    public const TYPE_PLANNING_WORKFLOW = 'planning_workflow';

    public const TYPE_GUARDED_ACTION = 'guarded_action';

    /** @var list<string> */
    public const ALLOWED_TYPES = [
        self::TYPE_SCHEDULED_REPORT,
        self::TYPE_CONDITION_WATCH,
        self::TYPE_PLANNING_WORKFLOW,
        self::TYPE_GUARDED_ACTION,
    ];

    /**
     * @param  array<string, mixed>  $trigger
     * @param  list<array<string, mixed>>  $workflow
     * @param  array<string, mixed>|null  $condition
     * @param  array<string, mixed>|null  $notification
     * @param  array<string, mixed>|null  $policy
     * @param  list<string>  $warnings
     */
    public function __construct(
        public string $name,
        public string $type,
        public array $trigger,
        public array $workflow,
        public string $timezone,
        public string $definitionHash,
        public ?string $description = null,
        public string $scopeType = 'site',
        public ?string $scopeRef = null,
        public ?array $condition = null,
        public ?array $notification = null,
        public ?array $policy = null,
        public bool $enabled = true,
        public ?int $conversationId = null,
        public array $warnings = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'type' => $this->type,
            'scope_type' => $this->scopeType,
            'scope_ref' => $this->scopeRef,
            'trigger' => $this->trigger,
            'workflow' => $this->workflow,
            'condition' => $this->condition,
            'notification' => $this->notification,
            'policy' => $this->policy,
            'enabled' => $this->enabled,
            'timezone' => $this->timezone,
            'definition_hash' => $this->definitionHash,
            'conversation_id' => $this->conversationId,
            'warnings' => $this->warnings,
        ];
    }
}
