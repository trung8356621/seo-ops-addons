<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Data;

use Omnichannel\Addons\Agent\Automation\Enums\ActionRiskLevel;
use Omnichannel\Addons\Agent\Automation\Enums\ActionSelectability;
use Omnichannel\Addons\Agent\Automation\Enums\ActionSideEffect;

final class ActionDefinition
{
    /**
     * @param  array<string, array<string, mixed>>  $inputSchema
     * @param  array<string, array<string, mixed>>  $outputSchema
     * @param  list<string>  $requiredPermissions
     * @param  list<string>  $emittedEvents
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $description,
        public readonly string $module,
        public readonly ActionSideEffect $sideEffect,
        public readonly ActionRiskLevel $riskLevel,
        public readonly ActionSelectability $selectability,
        public readonly array $inputSchema = [],
        public readonly array $outputSchema = [],
        public readonly bool $idempotent = false,
        public readonly ?string $lockScope = null,
        public readonly array $requiredPermissions = [],
        public readonly bool $supportsDryRun = false,
        public readonly bool $impliesPublishStatus = false,
        public readonly array $emittedEvents = [],
    ) {}

    public function isSelectableForWorkflow(): bool
    {
        return $this->selectability === ActionSelectability::Selectable;
    }

    /**
     * @return array<string, mixed>
     */
    public function toExportArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'module' => $this->module,
            'side_effect' => $this->sideEffect->value,
            'risk_level' => $this->riskLevel->value,
            'selectability' => $this->selectability->value,
            'input_schema' => $this->inputSchema,
            'output_schema' => $this->outputSchema,
            'idempotent' => $this->idempotent,
            'lock_scope' => $this->lockScope,
            'required_permission' => $this->requiredPermissions,
            'supports_dry_run' => $this->supportsDryRun,
            'implies_publish_status' => $this->impliesPublishStatus,
            'emitted_events' => $this->emittedEvents,
        ];
    }
}
