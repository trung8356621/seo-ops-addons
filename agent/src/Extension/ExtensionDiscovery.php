<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Extension;

use Omnichannel\Addons\Agent\Extension\Contracts\ExtensionProvider;
use Omnichannel\Addons\Agent\Extension\Registry\ExtensionRegistry;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\File;
use Throwable;

final class ExtensionDiscovery
{
    /** @var list<array{provider: ExtensionProvider, id: string}> */
    private array $bootQueue = [];

    public function __construct(
        private readonly Application $app,
        private readonly ExtensionRegistry $extensionRegistry,
        private readonly ExtensionStateStore $stateStore,
        private readonly ExtensionCompatibilityChecker $compatibilityChecker,
        private readonly ExtensionContext $context,
    ) {}

    public function discoverAndRegister(): void
    {
        $this->bootQueue = [];

        foreach ($this->scanManifestPaths() as $manifestPath) {
            $this->registerFromManifest($manifestPath);
        }
    }

    public function bootExtensions(): void
    {
        foreach ($this->bootQueue as $entry) {
            if (! $this->stateStore->isEnabled($entry['id'])) {
                continue;
            }

            try {
                $entry['provider']->boot($this->context);
            } catch (Throwable $e) {
                $this->stateStore->setHealth($entry['id'], [
                    'ok' => false,
                    'status' => 'error',
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    private function registerFromManifest(string $manifestPath): void
    {
        try {
            $manifest = ExtensionManifest::fromFile($manifestPath);
        } catch (Throwable $e) {
            return;
        }

        $compatibility = $this->compatibilityChecker->check($manifest);
        $status = $compatibility['compatible'] ? 'healthy' : 'needs_update';

        if (! $compatibility['compatible']) {
            $this->stateStore->setHealth($manifest->id, [
                'ok' => false,
                'status' => 'needs_update',
                'message' => implode(' ', $compatibility['reasons']),
            ]);
        }

        $providerClass = $manifest->provider;
        if (! class_exists($providerClass)) {
            $this->stateStore->setHealth($manifest->id, [
                'ok' => false,
                'status' => 'error',
                'message' => "Provider class not found: {$providerClass}",
            ]);

            return;
        }

        $definition = new ExtensionDefinition(
            manifest: $manifest,
            path: dirname($manifestPath),
            providerClass: $providerClass,
            status: $status,
        );

        if ($this->extensionRegistry->find($manifest->id) === null) {
            $this->extensionRegistry->register($definition);
        }

        if (! $this->stateStore->isEnabled($manifest->id) && $manifest->enabled) {
            // giữ DB/cache làm nguồn sự thật; manifest.enabled chỉ default lần đầu
        }

        if (! $compatibility['compatible']) {
            return;
        }

        if (! $this->stateStore->isEnabled($manifest->id)) {
            return;
        }

        try {
            /** @var ExtensionProvider $provider */
            $provider = $this->app->make($providerClass);
            $provider->register($this->context);
            $this->bootQueue[] = ['provider' => $provider, 'id' => $manifest->id];
        } catch (Throwable $e) {
            $this->stateStore->setHealth($manifest->id, [
                'ok' => false,
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function scanManifestPaths(): array
    {
        $paths = [];

        if ((bool) config('seo-content-ai.extension_sdk.autoload_builtin', true)) {
            $paths = array_merge($paths, $this->globManifests(__DIR__.'/Builtin'));
        }

        $extensionsPath = config('seo-content-ai.extension_sdk.extensions_path');
        if (! is_string($extensionsPath) || $extensionsPath === '') {
            $extensionsPath = dirname(__DIR__).'/Extensions';
        }

        if (is_dir($extensionsPath)) {
            $paths = array_merge($paths, $this->globManifests($extensionsPath));
        }

        return $paths;
    }

    /**
     * @return list<string>
     */
    private function globManifests(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }

        $matches = [];
        foreach (File::directories($root) as $directory) {
            $manifest = $directory.DIRECTORY_SEPARATOR.'plugin.json';
            if (is_file($manifest)) {
                $matches[] = $manifest;
            }
        }

        sort($matches);

        return $matches;
    }
}
