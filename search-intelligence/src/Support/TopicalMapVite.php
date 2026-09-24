<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Support;

use Illuminate\Support\HtmlString;
use RuntimeException;

/**
 * Resolves Topical Map frontend assets from a dedicated Vite build boundary.
 *
 * Dev:  public/hot-topical-map → Topical Map Vite (port 5175)
 * Prod: public/build-topical-map/manifest.json
 *
 * Does NOT mutate Core Vite (public/hot, public/build).
 */
final class TopicalMapVite
{
    public const BUILD_DIRECTORY = 'build-topical-map';

    public const HOT_FILE = 'hot-topical-map';

    /** @var list<string> */
    public const ENTRYPOINTS = [
        'resources/js/topical-map/topical-map-app.jsx',
        'resources/js/topical-map/styles/topical-map-app.css',
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
     * HTML tags for Topical Map JS/CSS (dev HMR or production hashed assets).
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

            return new HtmlString('<!-- topical-map vite assets unavailable: '.e($e->getMessage()).' -->');
        }
    }

    private function hotBaseUrl(): string
    {
        $raw = trim((string) file_get_contents($this->hotFilePath()));
        if ($raw === '') {
            throw new RuntimeException('Topical Map hot file is empty.');
        }

        return rtrim($raw, '/');
    }

    private function devTags(): string
    {
        $base = $this->hotBaseUrl();
        $tags = [];

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
            throw new RuntimeException('Topical Map manifest missing: '.$manifestPath);
        }

        $decoded = json_decode((string) file_get_contents($manifestPath), true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Topical Map manifest is invalid JSON.');
        }

        $tags = [];
        $emittedCss = [];

        foreach (self::ENTRYPOINTS as $entry) {
            $chunk = $this->findManifestChunk($decoded, $entry);

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

    /**
     * @param  array<string, mixed>  $manifest
     * @return array{file: string, css?: list<string>, src?: string}
     */
    private function findManifestChunk(array $manifest, string $entry): array
    {
        $direct = $manifest[$entry] ?? null;
        if (is_array($direct) && ! empty($direct['file'])) {
            /** @var array{file: string, css?: list<string>, src?: string} $direct */
            return $direct;
        }

        $normalizedEntry = str_replace('\\', '/', $entry);

        foreach ($manifest as $key => $chunk) {
            if (! is_array($chunk) || empty($chunk['file'])) {
                continue;
            }

            $candidates = [
                str_replace('\\', '/', (string) $key),
                str_replace('\\', '/', (string) ($chunk['src'] ?? '')),
            ];

            foreach ($candidates as $candidate) {
                if ($candidate === '') {
                    continue;
                }

                if (
                    $candidate === $normalizedEntry
                    || str_ends_with($candidate, '/'.$normalizedEntry)
                ) {
                    /** @var array{file: string, css?: list<string>, src?: string} $chunk */
                    return $chunk;
                }
            }
        }

        throw new RuntimeException('Topical Map manifest entry missing: '.$entry);
    }
}
