<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Extension;

final class SdkVersion
{
    public const MAJOR = 1;

    public static function supports(int $pluginSdk): bool
    {
        return $pluginSdk === self::MAJOR;
    }
}
