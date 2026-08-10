<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Platform;

use Illuminate\Contracts\Container\Container;

/**
 * Holds platform registries after module boot — single boot point for ServiceProvider.
 */
final class AutomationPlatformKernel
{
    private static bool $booted = false;

    private function __construct(
        public readonly AutomationModuleContext $context,
        public readonly AutomationModuleRegistry $modules,
    ) {}

    public static function boot(Container $container): self
    {
        /** @var AutomationModuleRegistry $modules */
        $modules = $container->make(AutomationModuleRegistry::class);
        $context = AutomationModuleContext::create($container);
        $modules->boot($context);

        return new self($context, $modules);
    }

    public static function bootOnce(Container $container): self
    {
        static $kernel = null;

        if ($kernel instanceof self) {
            return $kernel;
        }

        $kernel = self::boot($container);
        self::$booted = true;

        return $kernel;
    }

    public static function resetForTesting(): void
    {
        self::$booted = false;
    }

    public static function isBooted(): bool
    {
        return self::$booted;
    }
}
