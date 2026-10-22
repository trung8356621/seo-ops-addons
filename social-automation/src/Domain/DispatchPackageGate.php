<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Domain;

/**
 * Block new dispatch when pinned Widget/Workflow package is disabled
 * or Workflow references a disabled Widget.
 *
 * @phpstan-type PackageState array{package_key: string, kind: string, is_enabled: bool, disabled_reason?: string|null}
 * @phpstan-type DependencyRef array{package_key: string}
 */
final class DispatchPackageGate
{
    /**
     * @param  PackageState  $pinned
     * @param  list<PackageState>  $dependencyStates  widget deps for workflows
     * @return array{ok: true}|array{ok: false, errors: list<string>}
     */
    public function assertDispatchable(array $pinned, array $dependencyStates = []): array
    {
        if (! ($pinned['is_enabled'] ?? true)) {
            return ['ok' => false, 'errors' => ['package_disabled:'.(string) ($pinned['package_key'] ?? '')]];
        }

        $kind = strtolower((string) ($pinned['kind'] ?? ''));
        if ($kind === 'script') {
            return ['ok' => false, 'errors' => ['script_not_dispatchable']];
        }

        foreach ($dependencyStates as $dep) {
            if (! ($dep['is_enabled'] ?? true)) {
                return ['ok' => false, 'errors' => ['widget_disabled:'.(string) ($dep['package_key'] ?? '')]];
            }
        }

        return ['ok' => true];
    }
}
