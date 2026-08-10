<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Support;

use App\Support\RuntimeLogger;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Measures JSON size of Article Editor bootstrap pieces (Phase 2 perf).
 * Only active work happens when ArticleEditorPerfDebug::enabled() — otherwise
 * record()/log() are cheap no-ops so production stays untouched.
 */
final class ArticleEditorBootstrapSizer
{
    /** @var array<string, int> */
    private array $sizes = [];

    public static function bytes(mixed $data): int
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);

        return $json === false ? 0 : strlen($json);
    }

    public static function kb(mixed $data): float
    {
        return round(self::bytes($data) / 1024, 2);
    }

    public function record(string $key, mixed $data): void
    {
        $this->sizes[$key] = self::bytes($data);
    }

    /**
     * @return array<string, int>
     */
    public function snapshot(): array
    {
        return $this->sizes;
    }

    public function totalBytes(): int
    {
        return array_sum($this->sizes);
    }

    public function log(string $context): void
    {
        if ($this->sizes === []) {
            return;
        }

        RuntimeLogger::warning('article_editor_bootstrap_sizes', [
            'context' => $context,
            'sizes_bytes' => $this->sizes,
            'total_bytes' => $this->totalBytes(),
            'total_kb' => round($this->totalBytes() / 1024, 2),
        ]);

        $this->writeToStorage($context);
    }

    /**
     * Best-effort snapshot file for offline inspection — never throws, never
     * blocks the request if storage/logs is missing or unwritable.
     */
    private function writeToStorage(string $context): void
    {
        try {
            $path = storage_path('logs/article_editor_bootstrap_sizes.json');
            $directory = dirname($path);
            if (! File::isDirectory($directory)) {
                return;
            }

            File::put(
                $path,
                json_encode([
                    'context' => $context,
                    'measured_at' => now()->toIso8601String(),
                    'sizes_bytes' => $this->sizes,
                    'total_bytes' => $this->totalBytes(),
                    'total_kb' => round($this->totalBytes() / 1024, 2),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            );
        } catch (Throwable) {
            // Storage might be unavailable (permissions, missing dir) — measurement is best-effort only.
        }
    }
}
