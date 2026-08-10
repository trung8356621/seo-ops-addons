<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Enums;

enum AutomationRuleClassification: string
{
    case Business = 'business';
    case System = 'system';
    case Infrastructure = 'infrastructure';
    case Sample = 'sample';
    case Deprecated = 'deprecated';

    /** @deprecated Use Business */
    case Production = 'production';
    /** @deprecated Use System */
    case Experimental = 'experimental';
    /** @deprecated Use System */
    case ManualOnly = 'manual-only';

    public function normalize(): self
    {
        return match ($this) {
            self::Production => self::Business,
            self::Experimental, self::ManualOnly => self::System,
            default => $this,
        };
    }

    public function isUserFacing(): bool
    {
        return $this->normalize() === self::Business;
    }
}
