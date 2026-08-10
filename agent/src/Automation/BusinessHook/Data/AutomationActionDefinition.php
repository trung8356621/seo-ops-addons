<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Data;

final class AutomationActionDefinition
{
    /**
     * @param  array<string, array{type?: string, required?: bool}>  $inputRules
     * @param  array<string, array{type?: string, required?: bool}>  $settingsRules
     * @param  array<string, array<string, mixed>>  $fieldMeta
     * @param  array<string, array{type?: string, required?: bool}>  $manualInputSchema
     */
    public function __construct(
        public readonly string $actionCode,
        public readonly string $handlerClass,
        public readonly array $inputRules,
        public readonly array $settingsRules,
        public readonly string $description,
        public readonly bool $isAsyncSafe,
        public readonly int $timeout,
        public readonly string $module,
        public readonly string $defaultQueue = 'automation',
        public readonly ?string $rateLimitKey = null,
        public readonly ?int $maxAttemptsPerMinute = null,
        public readonly bool $supportsTest = false,
        public readonly array $fieldMeta = [],
        public readonly bool $supportsManualTrigger = false,
        public readonly ?string $manualPermission = null,
        public readonly ?string $manualLabel = null,
        public readonly ?string $manualDescription = null,
        public readonly ?string $manualConfirmation = null,
        public readonly array $manualInputSchema = [],
        public readonly string $manualIdempotencyScope = 'subject',
        public readonly bool $manualEnabled = true,
    ) {}

    /**
     * @return array<string, array<string, mixed>>
     */
    public function formFields(): array
    {
        return $this->fieldMeta;
    }

    public function definitionChecksum(): string
    {
        return hash('sha256', json_encode([
            'action_code' => $this->actionCode,
            'handler' => $this->handlerClass,
            'input_rules' => $this->inputRules,
            'settings_rules' => $this->settingsRules,
            'timeout' => $this->timeout,
            'queue' => $this->defaultQueue,
            'module' => $this->module,
            'manual_enabled' => $this->manualEnabled,
        ], JSON_THROW_ON_ERROR));
    }
}
