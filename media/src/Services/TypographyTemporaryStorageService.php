<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Lưu candidate typography tạm — không ghi vào media library chính thức.
 */
final class TypographyTemporaryStorageService
{
    private const DISK = 'local';

    private const BASE_DIR = 'seo-typography-candidates';

    public function store(string $binary, string $mimeType, string $candidateId): string
    {
        $ext = match (true) {
            str_contains($mimeType, 'jpeg') || str_contains($mimeType, 'jpg') => 'jpg',
            str_contains($mimeType, 'webp') => 'webp',
            default => 'png',
        };

        $relative = self::BASE_DIR.'/'.Str::slug($candidateId).'-'.Str::random(8).'.'.$ext;
        Storage::disk(self::DISK)->put($relative, $binary);

        return $relative;
    }

    public function absolutePath(string $relativePath): string
    {
        return Storage::disk(self::DISK)->path($relativePath);
    }

    public function read(string $relativePath): ?string
    {
        if (! Storage::disk(self::DISK)->exists($relativePath)) {
            return null;
        }

        return Storage::disk(self::DISK)->get($relativePath);
    }

    public function delete(string $relativePath): void
    {
        if ($relativePath === '') {
            return;
        }

        Storage::disk(self::DISK)->delete($relativePath);
    }

    /**
     * @param  list<string>  $relativePaths
     */
    public function deleteMany(array $relativePaths): void
    {
        foreach ($relativePaths as $path) {
            $this->delete($path);
        }
    }

    public function cleanupOrphansOlderThanHours(int $hours = 24): int
    {
        if ($hours <= 0) {
            return 0;
        }

        $deleted = 0;
        $files = Storage::disk(self::DISK)->allFiles(self::BASE_DIR);
        $threshold = now()->subHours($hours)->getTimestamp();

        foreach ($files as $file) {
            $mtime = Storage::disk(self::DISK)->lastModified($file);
            if ($mtime > 0 && $mtime < $threshold) {
                Storage::disk(self::DISK)->delete($file);
                $deleted++;
            }
        }

        return $deleted;
    }
}
