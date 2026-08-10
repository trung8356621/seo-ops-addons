<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;


use Omnichannel\Addons\Seo\Services\SeoOverviewSettingsService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class TeamChatAttachmentService
{
    public function __construct(
        private readonly SeoOverviewSettingsService $overviewSettings,
    ) {}

    /**
     * @return array{
     *     path: string,
     *     name: string,
     *     mime: string,
     *     size: int,
     *     url: string,
     *     is_image: bool
     * }
     */
    public function store(UploadedFile $file, int $ownerId): array
    {
        if ($ownerId <= 0) {
            throw ValidationException::withMessages([
                'file' => 'Không xác định được workspace.',
            ]);
        }

        $this->assertAllowed($file);

        $extension = $this->resolveExtension($file);
        $filename = Str::uuid()->toString().($extension !== '' ? '.'.$extension : '');
        $directory = 'uploads/team-chat/'.$ownerId;
        $path = $file->storeAs($directory, $filename, 'public');

        if ($path === false) {
            throw ValidationException::withMessages([
                'file' => 'Không lưu được tệp đính kèm.',
            ]);
        }

        $mime = (string) ($file->getMimeType() ?? 'application/octet-stream');
        $size = (int) $file->getSize();

        return [
            'path' => $path,
            'name' => $this->sanitizeOriginalName($file->getClientOriginalName()),
            'mime' => $mime,
            'size' => $size,
            'url' => Storage::disk('public')->url($path),
            'is_image' => str_starts_with($mime, 'image/'),
        ];
    }

    /**
     * @return array{
     *     allowed_extensions: list<string>,
     *     max_file_size_mb: int,
     *     max_file_size_bytes: int
     * }
     */
    public function clientConfig(): array
    {
        $maxMb = $this->overviewSettings->getTeamChatMaxFileSizeMb();

        return [
            'allowed_extensions' => $this->overviewSettings->getTeamChatAllowedExtensions(),
            'max_file_size_mb' => $maxMb,
            'max_file_size_bytes' => $maxMb * 1024 * 1024,
        ];
    }

    private function assertAllowed(UploadedFile $file): void
    {
        $allowed = $this->overviewSettings->getTeamChatAllowedExtensions();
        $extension = $this->resolveExtension($file);
        $maxBytes = $this->overviewSettings->getTeamChatMaxFileSizeMb() * 1024 * 1024;
        $size = (int) $file->getSize();

        if ($size <= 0) {
            throw ValidationException::withMessages([
                'file' => 'Tệp rỗng hoặc không hợp lệ.',
            ]);
        }

        if ($size > $maxBytes) {
            throw ValidationException::withMessages([
                'file' => 'Tệp vượt quá giới hạn '.$this->overviewSettings->getTeamChatMaxFileSizeMb().' MB.',
            ]);
        }

        if ($extension === '' || ! in_array($extension, $allowed, true)) {
            throw ValidationException::withMessages([
                'file' => 'Loại tệp không được phép. Cho phép: '.implode(', ', $allowed).'.',
            ]);
        }
    }

    private function resolveExtension(UploadedFile $file): string
    {
        $fromName = strtolower((string) $file->getClientOriginalExtension());
        if ($fromName !== '') {
            return $fromName;
        }

        return match ((string) ($file->getMimeType() ?? '')) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'application/pdf' => 'pdf',
            default => '',
        };
    }

    private function sanitizeOriginalName(?string $name): string
    {
        $clean = trim((string) $name);
        if ($clean === '') {
            return 'attachment';
        }

        return Str::limit($clean, 180, '');
    }
}
