<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Extension;

final class ExtensionCompatibilityChecker
{
    /**
     * @return array{compatible: bool, reasons: list<string>, migration_needed: bool, deprecated: list<string>}
     */
    public function check(ExtensionManifest $manifest): array
    {
        $reasons = [];
        $deprecated = [];
        $migrationNeeded = false;

        if (! SdkVersion::supports($manifest->sdk)) {
            $reasons[] = "SDK major mismatch: plugin requires {$manifest->sdk}, platform supports ".SdkVersion::MAJOR.'.';
            if ($manifest->sdk > SdkVersion::MAJOR) {
                $migrationNeeded = true;
            }
        }

        foreach ($manifest->requires as $requirement) {
            if (! str_starts_with($requirement, 'extension:')) {
                continue;
            }

            $requiredId = substr($requirement, strlen('extension:'));
            if ($requiredId === '') {
                $reasons[] = 'Invalid extension requirement.';
            }
        }

        return [
            'compatible' => $reasons === [],
            'reasons' => $reasons,
            'migration_needed' => $migrationNeeded,
            'deprecated' => $deprecated,
        ];
    }
}
