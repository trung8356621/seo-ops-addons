<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Support;

use Omnichannel\Addons\Seo\Services\SettingsTransfer\SystemBootstrapAuthorization;

/**
 * Process-local gate opened only while an explicit system-bootstrap token is active.
 * Downstream import services may honor this without accepting Manager session forgery.
 */
final class SystemBootstrapGate
{
    private static int $depth = 0;

    /**
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    public static function run(SystemBootstrapAuthorization $authorization, callable $callback): mixed
    {
        $authorization->assertAuthorized();
        self::$depth++;
        try {
            return $callback();
        } finally {
            self::$depth--;
        }
    }

    public static function isOpen(): bool
    {
        return self::$depth > 0;
    }

    /** @internal tests */
    public static function reset(): void
    {
        self::$depth = 0;
    }
}
