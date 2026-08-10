<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\Media\Models\SeoMedia;
use Omnichannel\Addons\AiPrompt\Support\PromptMediaPersistContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Tải ảnh/video từ URL (kết quả AI) và lưu vào thư viện seo_media nội bộ.
 */
final class PromptMediaStorageService
{
    private ?SeoMedia $persistTarget = null;

    public function __construct(
        private readonly SeoMediaStorageService $mediaStorage,
        private readonly SeoMediaPathAllocator $mediaPaths,
    ) {}

    /**
     * Ghi file kết quả AI vào đúng bản ghi seo_media placeholder (không tạo bản ghi mới).
     *
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    public function usingTargetMedia(?SeoMedia $media, callable $callback): mixed
    {
        $previous = $this->persistTarget;
        $this->persistTarget = $media;

        try {
            return $callback();
        } finally {
            $this->persistTarget = $previous;
        }
    }

    /**
     * Nếu $rawOutput chứa URL media hợp lệ — tải, lưu disk public + seo_media, trả URL nội bộ (/storage/...).
     */
    public function persistRemoteMediaIfPresent(
        string $rawOutput,
        string $toolType,
        ?string $aiGenerator = null,
        ?SeoMedia $targetMedia = null,
    ): string {
        $targetMedia ??= $this->persistTarget;

        if (! \Omnichannel\Addons\Media\Support\ImageToolType::fromMixed($toolType)->isMediaTool()) {
            return $rawOutput;
        }

        $firstLine = trim(strtok($rawOutput, "\n") ?: $rawOutput);
        if (str_starts_with($firstLine, '/storage/')) {
            if ($targetMedia instanceof SeoMedia && ! str_contains($firstLine, 'placeholder-loading')) {
                $this->attachStoredPathToMedia($targetMedia, ltrim(substr($firstLine, strlen('/storage/')), '/'), $aiGenerator);
            }

            return $firstLine;
        }

        $remoteUrl = $this->extractUrl($rawOutput);
        if ($remoteUrl === '' || ! filter_var($remoteUrl, FILTER_VALIDATE_URL)) {
            return $rawOutput;
        }

        if ($targetMedia instanceof SeoMedia) {
            $storedUrl = $this->downloadAndStore($remoteUrl, $toolType, $aiGenerator, $targetMedia);

            return $storedUrl ?? $rawOutput;
        }

        $storedUrl = $this->downloadAndStore($remoteUrl, $toolType, $aiGenerator);

        return $storedUrl ?? $rawOutput;
    }

    /**
     * Lưu bytes ảnh/video từ API (inlineData) vào thư viện nội bộ.
     */
    public function storeBinaryMedia(
        string $binary,
        string $mimeType,
        string $toolType = 'image',
        ?string $aiGenerator = null,
        ?SeoMedia $targetMedia = null,
    ): string {
        $targetMedia ??= $this->persistTarget;

        $ext = $this->extensionFromMime($mimeType, $toolType);
        $slug = $targetMedia instanceof SeoMedia && trim((string) $targetMedia->slug) !== ''
            ? (string) $targetMedia->slug
            : 'gen-' . time() . '-' . random_int(100, 999);
        $allocated = $this->mediaPaths->allocate($slug, $ext);
        $slug = $allocated['slug'];
        $filename = $allocated['filename'];
        $relativePath = $allocated['relative_path'];

        Storage::disk('public')->put($relativePath, $binary);

        $publicUrl = $this->mediaStorage->urlForPath($relativePath);

        if ($targetMedia instanceof SeoMedia) {
            $this->attachStoredPathToMedia($targetMedia, $relativePath, $aiGenerator, $filename, $slug);

            return $publicUrl;
        }

        SeoMedia::query()->create(array_merge([
            'filename' => $filename,
            'slug' => $slug,
            'path' => $relativePath,
            'url' => $publicUrl,
            'source' => 'ai_prompt',
            'ai_generator' => $aiGenerator !== null ? trim($aiGenerator) : null,
        ], PromptMediaPersistContext::attributesForNewRecord()));

        return $publicUrl;
    }

    private function extensionFromMime(string $mimeType, string $toolType): string
    {
        return $this->resolveExtension('', $toolType, $mimeType);
    }

    private function downloadAndStore(
        string $remoteUrl,
        string $toolType,
        ?string $aiGenerator = null,
        ?SeoMedia $targetMedia = null,
    ): ?string {
        $targetMedia ??= $this->persistTarget;

        try {
            $response = Http::timeout(120)->get($remoteUrl);
            if (! $response->successful()) {
                return null;
            }

            $fileData = $response->body();
            if ($fileData === '') {
                return null;
            }

            $ext = $this->resolveExtension($remoteUrl, $toolType, (string) $response->header('Content-Type'));
            $slug = $targetMedia instanceof SeoMedia && trim((string) $targetMedia->slug) !== ''
                ? (string) $targetMedia->slug
                : 'gen-' . time() . '-' . random_int(100, 999);
            $allocated = $this->mediaPaths->allocate($slug, $ext);
            $slug = $allocated['slug'];
            $filename = $allocated['filename'];
            $relativePath = $allocated['relative_path'];

            Storage::disk('public')->put($relativePath, $fileData);

            $publicUrl = $this->mediaStorage->urlForPath($relativePath);

            if ($targetMedia instanceof SeoMedia) {
                $this->attachStoredPathToMedia($targetMedia, $relativePath, $aiGenerator, $filename, $slug);

                return $publicUrl;
            }

            SeoMedia::query()->create(array_merge([
                'filename' => $filename,
                'slug' => $slug,
                'path' => $relativePath,
                'url' => $publicUrl,
                'source' => 'ai_prompt',
                'ai_generator' => $aiGenerator !== null ? trim($aiGenerator) : null,
            ], PromptMediaPersistContext::attributesForNewRecord()));

            return $publicUrl;
        } catch (\Throwable) {
            return null;
        }
    }

    private function attachStoredPathToMedia(
        SeoMedia $media,
        string $relativePath,
        ?string $aiGenerator = null,
        ?string $filename = null,
        ?string $slug = null,
    ): void {
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
        $filename = $filename ?? basename($relativePath);
        $slug = $slug ?? (string) ($media->slug ?? pathinfo($filename, PATHINFO_FILENAME));

        $payload = [
            'filename' => $filename,
            'slug' => $slug,
            'path' => $relativePath,
            'url' => $this->mediaStorage->urlForPath($relativePath),
        ];

        if ($aiGenerator !== null && trim($aiGenerator) !== '') {
            $payload['ai_generator'] = trim($aiGenerator);
        }

        $payload = array_merge($payload, PromptMediaPersistContext::fillMissingOnMedia($media));

        $media->update($payload);
    }

    private function resolveExtension(string $remoteUrl, string $toolType, string $contentType): string
    {
        $pathExt = strtolower((string) pathinfo(parse_url($remoteUrl, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
        if (in_array($pathExt, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'webm', 'mov'], true)) {
            return $pathExt === 'jpeg' ? 'jpg' : $pathExt;
        }

        $contentType = strtolower(trim(explode(';', $contentType)[0]));

        return match ($contentType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
            'video/quicktime' => 'mov',
            default => $toolType === 'video' ? 'mp4' : 'png',
        };
    }

    private function extractUrl(string $value): string
    {
        $value = trim($value);

        if (preg_match('/\((https?:\/\/[^)]+)\)/i', $value, $matches) === 1) {
            return (string) $matches[1];
        }

        if (preg_match('#https?://[^\s<>"\'\)]+#i', $value, $matches) === 1) {
            return rtrim((string) $matches[0], '.,;');
        }

        return $value;
    }
}
