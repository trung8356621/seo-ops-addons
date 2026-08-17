<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\ConfigurationPackages;

use App\Core\Operations\OperationLogger;
use Omnichannel\Addons\AiPrompt\Support\ConfigurationPackageType;

final class ConfigurationImportAuditor
{
    public function record(
        ConfigurationPackageType $type,
        string $schemaVersion,
        bool $success,
        int $sections = 0,
        int $prompts = 0,
        int $settingsChanged = 0,
        ?string $error = null,
    ): void {
        $context = [
            'package_type' => $type->value,
            'schema_version' => $schemaVersion,
            'sections' => $sections,
            'prompts' => $prompts,
            'settings_changed' => $settingsChanged,
            'actor_id' => auth()->id(),
            'success' => $success,
        ];
        if ($error !== null) {
            $context['error'] = $error;
        }

        try {
            $logger = function_exists('app') ? app(OperationLogger::class) : null;
            if ($logger instanceof OperationLogger) {
                $success
                    ? $logger->info('configuration_package.import', $context)
                    : $logger->error('configuration_package.import', $context);
            }
        } catch (\Throwable) {
            // Audit must never break import.
        }
    }
}
