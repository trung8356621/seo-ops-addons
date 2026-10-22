<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\PackageRegistry;

use Symfony\Component\Process\Process;

/**
 * Extract one JPEG near the end of a video via ffmpeg/ffprobe when available.
 */
final class FfmpegVideoLastFrameExtractor
{
    public function __construct(
        private readonly VideoLastFrameSeek $seek = new VideoLastFrameSeek,
        private readonly ?string $ffmpegBinary = null,
        private readonly ?string $ffprobeBinary = null,
    ) {}

    /**
     * @return array{ok: bool, path?: string, error?: string, seek_seconds?: float}
     */
    public function extractJpeg(string $videoAbsolutePath, string $outputAbsolutePath): array
    {
        if (! is_file($videoAbsolutePath)) {
            return ['ok' => false, 'error' => 'video_file_missing'];
        }

        $ffmpeg = $this->resolveBinary($this->ffmpegBinary, 'ffmpeg');
        $ffprobe = $this->resolveBinary($this->ffprobeBinary, 'ffprobe');
        if ($ffmpeg === null || $ffprobe === null) {
            return ['ok' => false, 'error' => 'ffmpeg_unavailable'];
        }

        $duration = $this->probeDuration($ffprobe, $videoAbsolutePath);
        $seekAt = $this->seek->seekSeconds($duration);

        $dir = dirname($outputAbsolutePath);
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            return ['ok' => false, 'error' => 'thumbnail_dir_failed'];
        }

        $process = new Process([
            $ffmpeg,
            '-y',
            '-ss',
            sprintf('%.3f', $seekAt),
            '-i',
            $videoAbsolutePath,
            '-frames:v',
            '1',
            '-q:v',
            '2',
            $outputAbsolutePath,
        ]);
        $process->setTimeout(60);
        $process->run();

        if (! $process->isSuccessful() || ! is_file($outputAbsolutePath) || filesize($outputAbsolutePath) === 0) {
            return ['ok' => false, 'error' => 'thumbnail_extract_failed', 'seek_seconds' => $seekAt];
        }

        return ['ok' => true, 'path' => $outputAbsolutePath, 'seek_seconds' => $seekAt];
    }

    private function probeDuration(string $ffprobe, string $videoAbsolutePath): float
    {
        $process = new Process([
            $ffprobe,
            '-v',
            'error',
            '-show_entries',
            'format=duration',
            '-of',
            'default=noprint_wrappers=1:nokey=1',
            $videoAbsolutePath,
        ]);
        $process->setTimeout(30);
        $process->run();
        if (! $process->isSuccessful()) {
            return 0.0;
        }
        $raw = trim($process->getOutput());
        if ($raw === '' || ! is_numeric($raw)) {
            return 0.0;
        }

        return max(0.0, (float) $raw);
    }

    private function resolveBinary(?string $configured, string $name): ?string
    {
        if (is_string($configured) && $configured !== '' && is_file($configured)) {
            return $configured;
        }
        $process = stripos(PHP_OS_FAMILY, 'Windows') === 0
            ? new Process(['where', $name])
            : new Process(['command', '-v', $name]);
        $process->setTimeout(5);
        try {
            $process->run();
        } catch (\Throwable) {
            return null;
        }
        if (! $process->isSuccessful()) {
            return null;
        }
        $line = trim(explode("\n", str_replace("\r", '', $process->getOutput()))[0] ?? '');

        return $line !== '' ? $line : null;
    }
}
