<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Services;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Media\Models\SeoMedia;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SeoMediaStorageService
{
    public function __construct(
        private readonly SeoImageOptimizationService $optimization,
        private readonly SeoMediaPathAllocator $mediaPaths,
    ) {}

    public function storeUpload(
        UploadedFile $file,
        ?int $siteId = null,
        ?int $articleId = null,
        string $source = 'clipboard',
    ): SeoMedia {
        $article = $articleId !== null
            ? SeoArticle::query()->find($articleId)
            : null;

        $config = $this->optimization->resolveForSite($siteId);
        $processed = $this->optimization->processUpload(
            $file,
            $config,
            $article,
            $source === 'clipboard',
        );

        Storage::disk('public')->put($processed['relative_path'], $processed['binary']);

        $media = SeoMedia::query()->create([
            'site_id' => $siteId,
            'article_id' => $articleId,
            'filename' => $processed['filename'],
            'slug' => $processed['slug'],
            'path' => $processed['relative_path'],
            'url' => $this->urlForPath($processed['relative_path']),
            'source' => $source,
            'alt_text' => $processed['alt_text'],
        ]);

        if ($siteId !== null) {
            app(SeoWatermarkService::class)->applyToMediaIfEnabled($media);
        }

        $fresh = $media->fresh() ?? $media;
        $emitter = app(\Omnichannel\Addons\Agent\Automation\BusinessHook\Support\BusinessHookEmitter::class);
        $emitter->mediaUploaded($fresh);
        $emitter->mediaProcessed($fresh);

        return $fresh;
    }

    /**
     * Tải ảnh từ URL bất kỳ, tối ưu theo cấu hình site và lưu thư viện nội bộ.
     */
    public function storeFromRemoteUrl(
        string $remoteUrl,
        ?int $siteId = null,
        ?int $articleId = null,
        bool $randomFilename = false,
    ): SeoMedia {
        $remoteUrl = trim($remoteUrl);
        if ($remoteUrl === '' || ! filter_var($remoteUrl, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('URL ảnh không hợp lệ.');
        }

        $scheme = strtolower((string) parse_url($remoteUrl, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Chỉ hỗ trợ URL http hoặc https.');
        }

        $fetchUrl = $remoteUrl;
        if ($randomFilename) {
            $separator = str_contains($remoteUrl, '?') ? '&' : '?';
            $fetchUrl = $remoteUrl . $separator . 'seo_cb=' . uniqid('', true);
        }

        $response = Http::timeout(120)
            ->withHeaders(['User-Agent' => 'OmiSeoAi/1.0'])
            ->get($fetchUrl);

        if (! $response->successful()) {
            throw new \RuntimeException('Không tải được ảnh (HTTP ' . $response->status() . ').');
        }

        $binary = $response->body();
        if ($binary === '') {
            throw new \RuntimeException('URL không trả về dữ liệu ảnh.');
        }

        $contentType = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        if ($contentType !== '' && ! str_starts_with($contentType, 'image/')) {
            throw new \InvalidArgumentException('URL không phải file ảnh (Content-Type: ' . $contentType . ').');
        }

        $article = $articleId !== null
            ? SeoArticle::query()->find($articleId)
            : null;

        if ($article !== null) {
            $siteId = (int) $article->site_id;
        }

        $pathExt = strtolower((string) pathinfo(parse_url($remoteUrl, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
        if ($pathExt === 'jpeg') {
            $pathExt = 'jpg';
        }
        if (! in_array($pathExt, ['jpg', 'png', 'gif', 'webp'], true)) {
            $pathExt = match ($contentType) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
                default => 'jpg',
            };
        }

        $slugSeed = $randomFilename
            ? 'import-' . bin2hex(random_bytes(8))
            : pathinfo(parse_url($remoteUrl, PHP_URL_PATH) ?? '', PATHINFO_FILENAME);

        $config = $this->optimization->resolveForSite($siteId);
        $processed = $this->optimization->processBinary(
            $binary,
            $pathExt,
            $config,
            $article,
            is_string($slugSeed) ? $slugSeed : null,
        );

        Storage::disk('public')->put($processed['relative_path'], $processed['binary']);

        $media = SeoMedia::query()->create([
            'site_id' => $siteId,
            'article_id' => $articleId,
            'filename' => $processed['filename'],
            'slug' => $processed['slug'],
            'path' => $processed['relative_path'],
            'url' => $this->urlForPath($processed['relative_path']),
            'source' => 'url_import',
            'alt_text' => $processed['alt_text'],
        ]);

        if ($siteId !== null) {
            app(SeoWatermarkService::class)->applyToMediaIfEnabled($media);
        }

        $fresh = $media->fresh() ?? $media;
        $emitter = app(\Omnichannel\Addons\Agent\Automation\BusinessHook\Support\BusinessHookEmitter::class);
        $emitter->mediaUploaded($fresh);
        $emitter->mediaProcessed($fresh);

        return $fresh;
    }

    public function renameBySlug(SeoMedia $media, string $newSlug, bool $copyThenDelete = false): SeoMedia
    {
        $newSlug = Str::slug($newSlug);
        if ($newSlug === '') {
            throw new \InvalidArgumentException('Slug không hợp lệ.');
        }

        $oldPath = ltrim(str_replace('\\', '/', (string) $media->path), '/');
        $extension = (string) (pathinfo($oldPath, PATHINFO_EXTENSION) ?: pathinfo((string) $media->filename, PATHINFO_EXTENSION));
        $allocated = $this->mediaPaths->allocate($newSlug, $extension, $oldPath);
        $newPath = $allocated['relative_path'];
        $newSlug = $allocated['slug'];
        $newFilename = $allocated['filename'];

        $disk = Storage::disk('public');
        $copied = false;
        if ($disk->exists($oldPath) && $newPath !== $oldPath) {
            if ($copyThenDelete) {
                if (! $disk->copy($oldPath, $newPath)) {
                    throw new \RuntimeException('Không copy được file ảnh sang path mới.');
                }
                $copied = true;
            } else {
                $disk->move($oldPath, $newPath);
            }
        }

        try {
            $media->update([
                'filename' => $newFilename,
                'slug' => $newSlug,
                'path' => $newPath,
                'url' => $this->urlForPath($newPath),
            ]);
        } catch (\Throwable $e) {
            if ($copied && $disk->exists($newPath)) {
                $disk->delete($newPath);
            }
            throw $e;
        }

        $fresh = $media->fresh() ?? $media;
        app(\Omnichannel\Addons\Agent\Automation\BusinessHook\Support\BusinessHookEmitter::class)
            ->mediaProcessed($fresh);

        return $fresh;
    }

    public function urlForPath(string $relativePath): string
    {
        $normalized = ltrim(str_replace('\\', '/', $relativePath), '/');

        return '/storage/' . $normalized;
    }
}
