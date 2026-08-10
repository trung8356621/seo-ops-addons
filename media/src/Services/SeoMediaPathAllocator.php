<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Đường dẫn ảnh nội bộ: một thư mục uploads/seo_media, trùng tên thì -1, -2… (giống WordPress).
 */
final class SeoMediaPathAllocator
{
    public const BASE_DIR = 'uploads/seo_media';

    /**
     * @return array{slug: string, filename: string, relative_path: string}
     */
    public function allocate(string $preferredSlug, string $extension, ?string $ignoreExistingPath = null): array
    {
        $slug = Str::slug($preferredSlug);
        if ($slug === '') {
            $slug = 'img-' . time();
        }

        $extension = strtolower(ltrim($extension, '.'));
        if ($extension === 'jpeg') {
            $extension = 'jpg';
        }
        if ($extension === '') {
            $extension = 'jpg';
        }

        $ignore = $this->normalizeRelativePath($ignoreExistingPath);
        $disk = Storage::disk('public');

        $candidateSlug = $slug;
        $suffix = 0;

        while (true) {
            $filename = $candidateSlug . '.' . $extension;
            $relativePath = self::BASE_DIR . '/' . $filename;

            if (! $disk->exists($relativePath) || $relativePath === $ignore) {
                return [
                    'slug' => $candidateSlug,
                    'filename' => $filename,
                    'relative_path' => $relativePath,
                ];
            }

            $suffix++;
            $candidateSlug = $slug . '-' . $suffix;
        }
    }

    private function normalizeRelativePath(?string $path): string
    {
        $path = ltrim(str_replace('\\', '/', trim((string) $path)), '/');

        return $path;
    }
}
