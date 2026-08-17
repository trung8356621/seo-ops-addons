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
     */
    public function __construct(
        public ConfigurationPackageType $type,
        public string $schemaVersion,
        public string $mode,
        public array $sections,
        public array $prompts,
        public array $warnings,
        public array $payload,
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
            'warnings' => $this->warnings,
        ];
    }
}
