<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Enums;

enum AutomationEdgeBranch: string
{
    case Success = 'success';
    case Failure = 'failure';
    case True = 'true';
    case False = 'false';
    case Always = 'always';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $b): string => $b->value, self::cases());
    }
}
