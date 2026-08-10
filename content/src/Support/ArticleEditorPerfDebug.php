<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Support;

use App\Support\RuntimeLogger;

/**
 * Lightweight Article Editor mount/SEO bootstrap timing (no content/secrets in logs).
 */
final class ArticleEditorPerfDebug
{
    /** @var array<string, float> */
    private array $starts = [];

    /** @var array<string, float> */
    private array $durationsMs = [];

    private int $wpHttpCount = 0;

    private ArticleEditorBootstrapSizer $sizer;

    public function __construct()
    {
        $this->sizer = new ArticleEditorBootstrapSizer;
    }

    public function enabled(): bool
    {
        return (bool) config('seo-content-ai.article_editor_perf_debug', false);
    }

    public function start(string $label): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->starts[$label] = microtime(true);
    }

    public function stop(string $label): void
    {
        if (! $this->enabled()) {
            return;
        }

        $started = $this->starts[$label] ?? null;
        if ($started === null) {
            return;
        }

        $this->durationsMs[$label] = round((microtime(true) - $started) * 1000, 2);
        unset($this->starts[$label]);
    }

    public function countWpHttp(int $delta = 1): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->wpHttpCount += max(0, $delta);
    }

    /**
     * Record JSON byte size of a bootstrap piece (blade script / lazy endpoint payload).
     * No-op when perf debug is disabled — never pays json_encode cost in production.
     */
    public function recordBootstrapSize(string $key, mixed $data): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->sizer->record($key, $data);
    }

    public function bootstrapSizer(): ArticleEditorBootstrapSizer
    {
        return $this->sizer;
    }

    /**
     * Log accumulated bootstrap sizes recorded via recordBootstrapSize() this request.
     */
    public function logBootstrapSizes(string $context): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->sizer->log($context);
    }

    /**
     * Estimate Livewire public property payload sizes (Phase 2 snapshot budget).
     *
     * @param  array<string, mixed>  $properties
     */
    public function logLivewireSnapshotEstimate(string $context, array $properties): void
    {
        if (! $this->enabled()) {
            return;
        }

        $sizes = [];
        foreach ($properties as $name => $value) {
            $sizes[$name] = ArticleEditorBootstrapSizer::bytes($value);
        }
        arsort($sizes);

        $total = array_sum($sizes);
        RuntimeLogger::warning('article_editor_livewire_snapshot_estimate', [
            'context' => $context,
            'sizes_bytes' => $sizes,
            'total_bytes' => $total,
            'total_kb' => round($total / 1024, 2),
            'under_100kb' => $total < 100 * 1024,
        ]);
    }

    /**
     * @param  array<string, scalar|null>  $extra
     */
    public function logSummary(string $context, array $extra = []): void
    {
        if (! $this->enabled()) {
            return;
        }

        RuntimeLogger::warning('article_editor_perf', array_merge([
            'context' => $context,
            'durations_ms' => $this->durationsMs,
            'wp_http_count' => $this->wpHttpCount,
        ], $extra));
    }
}
