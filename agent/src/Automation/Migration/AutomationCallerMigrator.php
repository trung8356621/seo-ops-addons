<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Migration;

use Omnichannel\Addons\Agent\Automation\Data\ActionResult;
use Omnichannel\Addons\Agent\Automation\Enums\MigrationMode;
use Illuminate\Support\Str;

/**
 * Default: Action only.
 * Emergency Legacy: AUTOMATION_MIGRATION_EMERGENCY_LEGACY=true.
 */
final class AutomationCallerMigrator
{
    public function __construct(
        private readonly AutomationMigrationFlags $flags,
        private readonly AutomationParityLogger $parityLogger,
    ) {}

    /**
     * @param  callable(): mixed  $legacyWrite
     * @param  callable(): ActionResult  $actionWrite
     * @param  callable(): array<string, mixed>  $parityExpected
     * @param  callable(mixed): array<string, mixed>  $normalizeLegacy
     * @param  callable(array<string, mixed>): array<string, mixed>  $normalizeExpected
     */
    public function run(
        string $callerKey,
        callable $legacyWrite,
        callable $actionWrite,
        callable $parityExpected,
        callable $normalizeLegacy,
        callable $normalizeExpected,
        string $actionKey = '',
        ?string $correlationId = null,
    ): mixed {
        $mode = $this->flags->mode($callerKey);
        $correlationId = $correlationId !== null && $correlationId !== ''
            ? $correlationId
            : Str::uuid()->toString();

        if ($mode === MigrationMode::Action) {
            $result = $actionWrite();
            if (! $result instanceof ActionResult) {
                throw new \RuntimeException("Action write for [{$callerKey}] must return ActionResult.");
            }
            if (! $result->success) {
                throw new AutomationMigrationWriteException(
                    $callerKey,
                    (string) ($result->error['message'] ?? 'Automation action failed.'),
                    $result,
                );
            }

            return $result;
        }

        // Emergency Legacy — parity logger unused.
        unset($parityExpected, $normalizeLegacy, $normalizeExpected, $actionKey, $correlationId);

        return $legacyWrite();
    }
}
