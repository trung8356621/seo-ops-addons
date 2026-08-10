<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Platform;

use Omnichannel\Addons\Agent\Automation\Modules\Content\ContentAutomationModuleProvider;
use Omnichannel\Addons\Agent\Automation\Modules\Core\CoreAutomationModuleProvider;
use Omnichannel\Addons\Agent\Automation\Modules\Media\MediaAutomationModuleProvider;
use Omnichannel\Addons\Agent\Automation\Modules\Sample\SampleAutomationModuleProvider;
use Omnichannel\Addons\Agent\Automation\Modules\Seo\SeoAutomationModuleProvider;
use Omnichannel\Addons\Agent\Automation\Modules\WordPress\WordPressAutomationModuleProvider;
use Omnichannel\Addons\Agent\Automation\Platform\Contracts\AutomationModuleProvider;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

final class AutomationModuleRegistry
{
    /** @var array<class-string<AutomationModuleProvider>, bool> */
    private array $modules;

    /** @var list<string> */
    private array $bootedModuleIds = [];

    /**
     * @param  array<class-string<AutomationModuleProvider>, bool>  $modules
     */
    public function __construct(array $modules)
    {
        $this->modules = $modules;
    }

    public function boot(AutomationModuleContext $context): void
    {
        foreach ($this->modules as $providerClass => $enabled) {
            if (! $enabled) {
                continue;
            }

            if (! is_string($providerClass) || ! is_subclass_of($providerClass, AutomationModuleProvider::class)) {
                throw new InvalidArgumentException("Module [{$providerClass}] must implement AutomationModuleProvider.");
            }

            /** @var AutomationModuleProvider $provider */
            $provider = new $providerClass;

            if (in_array($provider->id(), $this->bootedModuleIds, true)) {
                continue;
            }

            $provider->register($context);
            $this->bootedModuleIds[] = $provider->id();
        }
    }

    /**
     * @return array<class-string<AutomationModuleProvider>, bool>
     */
    public function modules(): array
    {
        return $this->modules;
    }

    /**
     * @return list<string>
     */
    public function bootedModuleIds(): array
    {
        return $this->bootedModuleIds;
    }

    /**
     * Load modules from addon config file (works even when Laravel config cache skips mergeConfigFrom).
     * Runtime overlay: config('seo-content-ai.automation_modules.modules') khi có.
     */
    public static function fromConfig(Container $container): self
    {
        $modules = self::loadModulesFromFile();

        $overlay = $container->make('config')->get('seo-content-ai.automation_modules.modules');
        if (is_array($overlay) && $overlay !== []) {
            /** @var array<class-string<AutomationModuleProvider>, bool> $overlay */
            $modules = array_replace($modules, $overlay);
        }

        if ($modules === []) {
            $modules = self::builtinDefaults();
        }

        return new self($modules);
    }

    /**
     * @return array<class-string<AutomationModuleProvider>, bool>
     */
    public static function loadModulesFromFile(): array
    {
        $path = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'automation-modules.php';
        if (! is_file($path)) {
            return self::builtinDefaults();
        }

        /** @var mixed $loaded */
        $loaded = require $path;
        if (! is_array($loaded)) {
            return self::builtinDefaults();
        }

        $modules = $loaded['modules'] ?? null;
        if (! is_array($modules) || $modules === []) {
            return self::builtinDefaults();
        }

        /** @var array<class-string<AutomationModuleProvider>, bool> $modules */
        return $modules;
    }

    /**
     * @return array<class-string<AutomationModuleProvider>, bool>
     */
    public static function builtinDefaults(): array
    {
        return [
            CoreAutomationModuleProvider::class => true,
            WordPressAutomationModuleProvider::class => true,
            ContentAutomationModuleProvider::class => true,
            SeoAutomationModuleProvider::class => true,
            MediaAutomationModuleProvider::class => true,
            SampleAutomationModuleProvider::class => false,
        ];
    }
}
