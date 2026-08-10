<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Enums;

/**
 * Full cutover: default Action.
 * Emergency only: AUTOMATION_MIGRATION_EMERGENCY_LEGACY=true → Legacy (tắt event bridge path).
 */
enum MigrationMode: string
{
    case Legacy = 'legacy';
    case Shadow = 'shadow';
    case Action = 'action';

    public static function fromConfig(mixed $value): self
    {
        if (filter_var(env('AUTOMATION_MIGRATION_EMERGENCY_LEGACY', false), FILTER_VALIDATE_BOOLEAN)) {
            return self::Legacy;
        }

        return self::Action;
    }

    public function writesViaAction(): bool
    {
        return $this === self::Action;
    }

    public function writesViaLegacy(): bool
    {
        return $this === self::Legacy || $this === self::Shadow;
    }

    public function evaluatesParity(): bool
    {
        return $this === self::Shadow;
    }
}
