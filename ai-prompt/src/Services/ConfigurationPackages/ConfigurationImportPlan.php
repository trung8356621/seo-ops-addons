<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\ConfigurationPackages;

use Omnichannel\Addons\AiPrompt\Support\ConfigurationPackageType;

final readonly class ConfigurationImportPlan
{
    /**
     * @param  list<array<string, mixed>>  $sections
     * @param  list<array<string, mixed>>  $prompts
     * @param  list<string>  $warnings
     * @param  array<string, mixed>  $payload
     * @param  list<array<string, mixed>>  $tasks
     * @param  list<array<string, mixed>>  $connections
     * @param  list<array<string, mixed>>  $providerTemplates
     */
    public function __construct(
        public ConfigurationPackageType $type,
        public string $schemaVersion,
        public string $mode,
        public array $sections,
        public array $prompts,
        public array $warnings,
        public array $payload,
        public array $tasks = [],
        public array $connections = [],
        public array $providerTemplates = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'schema_version' => $this->schemaVersion,
            'mode' => $this->mode,
            'sections' => $this->sections,
            'prompts' => $this->prompts,
            'tasks' => $this->tasks,
            'connections' => $this->connections,
            'provider_templates' => $this->providerTemplates,
            'warnings' => $this->warnings,
        ];
    }
}
