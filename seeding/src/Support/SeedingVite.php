<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Support;

use Illuminate\Support\HtmlString;
use RuntimeException;

/**
 * Resolves Seeding frontend assets from a dedicated Vite build boundary.
 *
 * Dev:  public/hot-seeding → Seeding Vite (port 5174)
 * Prod: public/build-seeding/manifest.json
 *
 * Does NOT mutate Core Vite (public/hot, public/build).
 */
final class SeedingVite
{
    public const BUILD_DIRECTORY = 'build-seeding';

    public const HOT_FILE = 'hot-seeding';

    /** @var list<string> */
    public const ENTRYPOINTS = [
        'resources/js/seeding-workspace.jsx',
        'resources/css/seeding-workspace.css',
    ];

    public function hotFilePath(): string
    {
        return public_path(self::HOT_FILE);
    }

    public function buildDirectory(): string
    {
        return self::BUILD_DIRECTORY;
    }

    public function manifestPath(): string
    {
        return public_path(self::BUILD_DIRECTORY.DIRECTORY_SEPARATOR.'manifest.json');
    }

    public function isDevServerRunning(): bool
    {
        return is_file($this->hotFilePath());
    }

    /**
     * HTML tags for Seeding JS/CSS (dev HMR or production hashed assets).
     */
    public function tags(): HtmlString
    {
        try {
            if ($this->isDevServerRunning()) {
                return new HtmlString($this->devTags());
            }

            return new HtmlString($this->productionTags());
        } catch (\Throwable $e) {
            report($e);

            return new HtmlString('<!-- seeding vite assets unavailable: '.e($e->getMessage()).' -->');
        }
    }

    private function hotBaseUrl(): string
    {
        $raw = trim((string) file_get_contents($this->hotFilePath()));
        if ($raw === '') {
            throw new RuntimeException('Seeding hot file is empty.');
        }

        return rtrim($raw, '/');
    }

    private function devTags(): string
    {
        $base = $this->hotBaseUrl();
        $tags = [];

        // React Refresh preamble (Vite + @vitejs/plugin-react)
        $tags[] = '<script type="module">'.
            'import RefreshRuntime from '.json_encode($base.'/@react-refresh').';'.
            'RefreshRuntime.injectIntoGlobalHook(window);'.
            'window.$RefreshReg$ = () => {};'.
            'window.$RefreshSig$ = () => (type) => type;'.
            'window.__vite_plugin_react_preamble_installed__ = true;'.
            '</script>';

        $tags[] = '<script type="module" src="'.e($base.'/@vite/client').'"></script>';

        foreach (self::ENTRYPOINTS as $entry) {
            if (str_ends_with($entry, '.css')) {
                $tags[] = '<link rel="stylesheet" href="'.e($base.'/'.$entry).'">';
                continue;
            }
            $tags[] = '<script type="module" src="'.e($base.'/'.$entry).'"></script>';
        }

        return implode("\n", $tags);
    }

    private function productionTags(): string
    {
        $manifestPath = $this->manifestPath();
        if (! is_file($manifestPath)) {
            throw new RuntimeException('Seeding manifest missing: '.$manifestPath);
        }

        $decoded = json_decode((string) file_get_contents($manifestPath), true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Seeding manifest is invalid JSON.');
        }

        $tags = [];
        $emittedCss = [];

        foreach (self::ENTRYPOINTS as $entry) {
            $chunk = $decoded[$entry] ?? null;
            if (! is_array($chunk) || empty($chunk['file'])) {
                throw new RuntimeException('Seeding manifest entry missing: '.$entry);
            }

            $file = (string) $chunk['file'];
            $url = asset(self::BUILD_DIRECTORY.'/'.$file);

            if (str_ends_with($file, '.css')) {
                if (! isset($emittedCss[$url])) {
                    $tags[] = '<link rel="stylesheet" href="'.e($url).'">';
                    $emittedCss[$url] = true;
                }
            } else {
                $tags[] = '<script type="module" src="'.e($url).'"></script>';
            }

            foreach (($chunk['css'] ?? []) as $cssFile) {
                $cssUrl = asset(self::BUILD_DIRECTORY.'/'.$cssFile);
                if (isset($emittedCss[$cssUrl])) {
                    continue;
                }
                $tags[] = '<link rel="stylesheet" href="'.e($cssUrl).'">';
                $emittedCss[$cssUrl] = true;
            }
        }

        return implode("\n", $tags);
    }
}
