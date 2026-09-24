<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Services;

use Omnichannel\Addons\Seeding\Support\SeedingOutboundUrlPolicy;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

/**
 * Fetch + cache remote preview images so cards do not depend on hotlinking forever.
 * Failures return null — caller may omit the image.
 */
final class SeedingLinkPreviewImageCache
{
    private const TIMEOUT_SECONDS = 5;

    private const MAX_BYTES = 1_500_000;

    private const DISK = 'local';

    private const DIR = 'seeding/link-previews';

    public function __construct(
        private readonly SeedingOutboundUrlPolicy $urls = new SeedingOutboundUrlPolicy,
    ) {}

    /**
     * Cache remote image and return a controlled app URL, or null on failure.
     */
    public function cachePublicImage(string $imageUrl): ?string
    {
        $imageUrl = trim($imageUrl);
        if ($imageUrl === '' || ! preg_match('#^https?://#i', $imageUrl)) {
            return null;
        }

        try {
            $this->urls->assertSafeUrl($imageUrl);
        } catch (InvalidArgumentException) {
            return null;
        }

        $hash = sha1(strtolower($imageUrl));
        $metaKey = self::DIR.'/'.$hash.'.json';
        $disk = Storage::disk(self::DISK);

        if ($disk->exists($metaKey)) {
            try {
                /** @var array{path?: string, content_type?: string}|null $meta */
                $meta = json_decode((string) $disk->get($metaKey), true);
                $path = is_array($meta) ? (string) ($meta['path'] ?? '') : '';
                if ($path !== '' && $disk->exists($path)) {
                    return $this->serveUrl($hash);
                }
            } catch (Throwable) {
                // Re-fetch below.
            }
        }

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withHeaders([
                    'User-Agent' => 'OmnichannelSeedingPreview/1.0',
                    'Accept' => 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
                ])
                ->withOptions(['allow_redirects' => ['max' => 3]])
                ->get($imageUrl);

            if (! $response->successful()) {
                return null;
            }

            $contentType = strtolower((string) ($response->header('Content-Type') ?: ''));
            $contentType = trim(explode(';', $contentType)[0] ?? '');
            if ($contentType === '' || ! str_starts_with($contentType, 'image/')) {
                return null;
            }
            if (str_contains($contentType, 'svg')) {
                // Avoid storing/serving arbitrary SVG (XSS surface).
                return null;
            }

            $body = $response->body();
            if ($body === '' || strlen($body) > self::MAX_BYTES) {
                return null;
            }

            $ext = $this->extensionFor($contentType);
            $path = self::DIR.'/'.$hash.'.'.$ext;
            $disk->put($path, $body);
            $disk->put($metaKey, (string) json_encode([
                'path' => $path,
                'content_type' => $contentType,
                'source_url' => $imageUrl,
                'cached_at' => now()->toIso8601String(),
            ], JSON_UNESCAPED_SLASHES));

            return $this->serveUrl($hash);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{body: string, content_type: string}|null
     */
    public function readCached(string $hash): ?array
    {
        $hash = strtolower(trim($hash));
        if (! preg_match('/^[a-f0-9]{40}$/', $hash)) {
            return null;
        }

        $disk = Storage::disk(self::DISK);
        $metaKey = self::DIR.'/'.$hash.'.json';
        if (! $disk->exists($metaKey)) {
            return null;
        }

        try {
            /** @var array{path?: string, content_type?: string}|null $meta */
            $meta = json_decode((string) $disk->get($metaKey), true);
            if (! is_array($meta)) {
                return null;
            }
            $path = (string) ($meta['path'] ?? '');
            $contentType = (string) ($meta['content_type'] ?? 'application/octet-stream');
            if ($path === '' || ! $disk->exists($path)) {
                return null;
            }

            return [
                'body' => (string) $disk->get($path),
                'content_type' => $contentType !== '' ? $contentType : 'application/octet-stream',
            ];
        } catch (Throwable) {
            return null;
        }
    }

    private function serveUrl(string $hash): string
    {
        return url('/api/seeding/link-preview/image/'.$hash);
    }

    private function extensionFor(string $contentType): string
    {
        return match ($contentType) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
            default => 'bin',
        };
    }
}
