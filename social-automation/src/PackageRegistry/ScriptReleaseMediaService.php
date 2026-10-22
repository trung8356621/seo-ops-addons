<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\PackageRegistry;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Omnichannel\Addons\SocialAutomation\Models\SaPackage;
use Omnichannel\Addons\SocialAutomation\Models\SaPackageRelease;
use Omnichannel\Addons\SocialAutomation\Models\SaScriptReleaseMedia;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;
use Throwable;

/**
 * Video demo + thumbnail owned by Script release (not Widget).
 */
final class ScriptReleaseMediaService
{
    public function __construct(
        private readonly FfmpegVideoLastFrameExtractor $extractor = new FfmpegVideoLastFrameExtractor,
    ) {}

    /**
     * @return array{
     *     ok: bool,
     *     errors: list<string>,
     *     video?: array<string, mixed>,
     *     thumbnail?: array<string, mixed>|null
     * }
     */
    public function attachVideoDemo(
        string $packageKey,
        string $version,
        string $binary,
        string $mime = 'video/webm',
        ?string $checksumSha256 = null,
    ): array {
        if (! $this->ready()) {
            return ['ok' => false, 'errors' => ['media_schema_unavailable']];
        }
        $mime = strtolower(trim($mime));
        if (! in_array($mime, ['video/webm', 'video/mp4'], true)) {
            return ['ok' => false, 'errors' => ['script_video_demo_mime_invalid']];
        }
        if ($binary === '') {
            return ['ok' => false, 'errors' => ['script_video_demo_empty']];
        }

        $release = $this->findScriptRelease($packageKey, $version);
        if ($release === null) {
            return ['ok' => false, 'errors' => ['script_release_not_found']];
        }

        $sha = $checksumSha256 !== null && $checksumSha256 !== ''
            ? strtolower($checksumSha256)
            : hash('sha256', $binary);
        if (! PackageDigest::isValidHex($sha)) {
            return ['ok' => false, 'errors' => ['script_video_demo_digest_invalid']];
        }

        $relative = sprintf(
            'social-automation/script-media/%d/%s/video-demo-%s.%s',
            (int) $release->id,
            Str::slug($version) ?: 'v',
            substr($sha, 0, 12),
            $mime === 'video/mp4' ? 'mp4' : 'webm',
        );

        Storage::disk('local')->put($relative, $binary);

        $this->deleteKindFiles((int) $release->id, SaScriptReleaseMedia::KIND_VIDEO_DEMO);
        $this->deleteKindFiles((int) $release->id, SaScriptReleaseMedia::KIND_THUMBNAIL);

        $video = SaScriptReleaseMedia::query()->updateOrCreate(
            ['release_id' => $release->id, 'kind' => SaScriptReleaseMedia::KIND_VIDEO_DEMO],
            [
                'storage_disk' => 'local',
                'storage_path' => $relative,
                'mime' => $mime,
                'size_bytes' => strlen($binary),
                'checksum_sha256' => $sha,
                'status' => SaScriptReleaseMedia::STATUS_READY,
                'metadata' => ['attached_at' => now()->toIso8601String()],
            ],
        );

        $absoluteVideo = Storage::disk('local')->path($relative);
        $thumbRelative = sprintf(
            'social-automation/script-media/%d/%s/thumbnail-last.jpg',
            (int) $release->id,
            Str::slug($version) ?: 'v',
        );
        $absoluteThumb = Storage::disk('local')->path($thumbRelative);
        $extract = $this->extractor->extractJpeg($absoluteVideo, $absoluteThumb);

        $thumbnail = null;
        if (($extract['ok'] ?? false) === true) {
            $thumbnail = SaScriptReleaseMedia::query()->updateOrCreate(
                ['release_id' => $release->id, 'kind' => SaScriptReleaseMedia::KIND_THUMBNAIL],
                [
                    'storage_disk' => 'local',
                    'storage_path' => $thumbRelative,
                    'mime' => 'image/jpeg',
                    'size_bytes' => is_file($absoluteThumb) ? (int) filesize($absoluteThumb) : 0,
                    'checksum_sha256' => is_file($absoluteThumb) ? hash_file('sha256', $absoluteThumb) : null,
                    'status' => SaScriptReleaseMedia::STATUS_READY,
                    'metadata' => [
                        'seek_seconds' => $extract['seek_seconds'] ?? null,
                        'source' => 'last_frame',
                    ],
                ],
            );
        } else {
            SaScriptReleaseMedia::query()->updateOrCreate(
                ['release_id' => $release->id, 'kind' => SaScriptReleaseMedia::KIND_THUMBNAIL],
                [
                    'storage_disk' => 'local',
                    'storage_path' => '',
                    'mime' => 'image/jpeg',
                    'size_bytes' => 0,
                    'checksum_sha256' => null,
                    'status' => SaScriptReleaseMedia::STATUS_UNAVAILABLE,
                    'metadata' => [
                        'error' => $extract['error'] ?? 'thumbnail_extract_failed',
                        'seek_seconds' => $extract['seek_seconds'] ?? null,
                    ],
                ],
            );
        }

        return [
            'ok' => true,
            'errors' => [],
            'video' => $this->mediaArray($video),
            'thumbnail' => $thumbnail instanceof SaScriptReleaseMedia
                ? $this->mediaArray($thumbnail)
                : [
                    'kind' => SaScriptReleaseMedia::KIND_THUMBNAIL,
                    'status' => SaScriptReleaseMedia::STATUS_UNAVAILABLE,
                    'error' => $extract['error'] ?? 'thumbnail_extract_failed',
                ],
        ];
    }

    /**
     * @return array{
     *     video_demo_status: string,
     *     has_video: bool,
     *     has_thumbnail: bool,
     *     thumbnail_status: string|null,
     *     video_media_id: int|null,
     *     thumbnail_media_id: int|null
     * }
     */
    public function statusForRelease(int $releaseId): array
    {
        if (! $this->ready()) {
            return [
                'video_demo_status' => 'missing',
                'has_video' => false,
                'has_thumbnail' => false,
                'thumbnail_status' => null,
                'video_media_id' => null,
                'thumbnail_media_id' => null,
            ];
        }

        $video = SaScriptReleaseMedia::query()
            ->where('release_id', $releaseId)
            ->where('kind', SaScriptReleaseMedia::KIND_VIDEO_DEMO)
            ->where('status', SaScriptReleaseMedia::STATUS_READY)
            ->first();
        $thumb = SaScriptReleaseMedia::query()
            ->where('release_id', $releaseId)
            ->where('kind', SaScriptReleaseMedia::KIND_THUMBNAIL)
            ->first();

        $hasVideo = $video instanceof SaScriptReleaseMedia && $video->storage_path !== '';
        $thumbReady = $thumb instanceof SaScriptReleaseMedia
            && $thumb->status === SaScriptReleaseMedia::STATUS_READY
            && $thumb->storage_path !== '';

        $status = 'missing';
        if ($hasVideo && $thumbReady) {
            $status = 'ready';
        } elseif ($hasVideo) {
            $status = 'thumbnail_unavailable';
        }

        return [
            'video_demo_status' => $status,
            'has_video' => $hasVideo,
            'has_thumbnail' => $thumbReady,
            'thumbnail_status' => $thumb instanceof SaScriptReleaseMedia ? (string) $thumb->status : null,
            'video_media_id' => $hasVideo ? (int) $video->id : null,
            'thumbnail_media_id' => $thumbReady ? (int) $thumb->id : null,
        ];
    }

    public function findReadyMedia(int $mediaId): ?SaScriptReleaseMedia
    {
        if (! $this->ready()) {
            return null;
        }
        $row = SaScriptReleaseMedia::query()->where('id', $mediaId)->first();
        if (! $row instanceof SaScriptReleaseMedia) {
            return null;
        }
        if ($row->status !== SaScriptReleaseMedia::STATUS_READY || $row->storage_path === '') {
            return null;
        }

        return $row;
    }

    /**
     * Delete media rows + files for the given release ids only.
     *
     * @param  list<int>  $releaseIds
     */
    public function deleteForReleases(array $releaseIds): void
    {
        if ($releaseIds === [] || ! $this->ready()) {
            return;
        }
        $rows = SaScriptReleaseMedia::query()->whereIn('release_id', $releaseIds)->get();
        foreach ($rows as $row) {
            if ($row->storage_path !== '') {
                try {
                    Storage::disk($row->storage_disk ?: 'local')->delete($row->storage_path);
                } catch (Throwable) {
                    // best-effort file cleanup
                }
            }
            $row->delete();
        }
    }

    private function findScriptRelease(string $packageKey, string $version): ?SaPackageRelease
    {
        $package = SaPackage::query()->where('package_key', $packageKey)->where('kind', 'script')->first();
        if (! $package instanceof SaPackage) {
            return null;
        }
        $release = SaPackageRelease::query()
            ->where('package_id', $package->id)
            ->where('version', $version)
            ->first();

        return $release instanceof SaPackageRelease ? $release : null;
    }

    private function deleteKindFiles(int $releaseId, string $kind): void
    {
        $existing = SaScriptReleaseMedia::query()
            ->where('release_id', $releaseId)
            ->where('kind', $kind)
            ->get();
        foreach ($existing as $row) {
            if ($row->storage_path !== '') {
                try {
                    Storage::disk($row->storage_disk ?: 'local')->delete($row->storage_path);
                } catch (Throwable) {
                }
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function mediaArray(SaScriptReleaseMedia $media): array
    {
        return [
            'id' => (int) $media->id,
            'release_id' => (int) $media->release_id,
            'kind' => (string) $media->kind,
            'mime' => (string) $media->mime,
            'size_bytes' => (int) $media->size_bytes,
            'checksum_sha256' => $media->checksum_sha256,
            'status' => (string) $media->status,
            'metadata' => is_array($media->metadata) ? $media->metadata : [],
        ];
    }

    private function ready(): bool
    {
        try {
            return Schema::connection(SocialAutomationServiceConfig::CONNECTION)->hasTable('sa_script_release_media');
        } catch (Throwable) {
            return false;
        }
    }
}
