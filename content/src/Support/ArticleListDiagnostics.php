<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Support;

use App\Support\RuntimeLogger;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Optional Article List request diagnostics (SEO_ARTICLE_LIST_DIAGNOSTICS).
 * Never logs body/content, tokens, passwords, or raw bindings values.
 */
final class ArticleListDiagnostics
{
    private static bool $booted = false;

    private static ?string $requestId = null;

    private static float $startedAt = 0.0;

    /** @var array<string, float> */
    private static array $marks = [];

    /** @var list<array{sql: string, duration_ms: float, connection: string, bindings_count: int}> */
    private static array $slowQueries = [];

    public static function enabled(): bool
    {
        return (bool) config('seo-content-ai.article_list.diagnostics', false);
    }

    public static function requestId(): ?string
    {
        return self::$requestId;
    }

    public static function begin(string $route = 'seo.articles.index'): void
    {
        if (! self::enabled() || self::$booted) {
            return;
        }

        self::$booted = true;
        self::$requestId = (string) Str::uuid();
        self::$startedAt = microtime(true);
        self::$marks = [];
        self::$slowQueries = [];

        self::mark('request_started_at');

        $thresholdMs = (float) config('seo-content-ai.article_list.slow_sql_ms', 300);

        DB::listen(static function (QueryExecuted $event) use ($thresholdMs): void {
            if ($event->time < $thresholdMs) {
                return;
            }

            self::$slowQueries[] = [
                'sql' => self::normalizeSql((string) $event->sql),
                'duration_ms' => round((float) $event->time, 2),
                'connection' => (string) $event->connectionName,
                'bindings_count' => count($event->bindings),
            ];
        });

        RuntimeLogger::info('seo.article_list.diagnostics_started', [
            'request_id' => self::$requestId,
            'route' => $route,
            'request_started_at' => now()->toIso8601String(),
        ]);

        app()->terminating(static function (): void {
            self::flush();
        });
    }

    public static function mark(string $name): void
    {
        if (! self::enabled() || ! self::$booted) {
            return;
        }

        self::$marks[$name] = round((microtime(true) - self::$startedAt) * 1000, 2);
    }

    /**
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    public static function measure(string $name, callable $callback): mixed
    {
        if (! self::enabled() || ! self::$booted) {
            return $callback();
        }

        $before = microtime(true);
        try {
            return $callback();
        } finally {
            self::$marks[$name.'_duration_ms'] = round((microtime(true) - $before) * 1000, 2);
            self::mark($name.'_done_at_ms');
        }
    }

    public static function flush(): void
    {
        if (! self::enabled() || ! self::$booted || self::$requestId === null) {
            return;
        }

        self::mark('total_response_duration_ms');

        RuntimeLogger::info('seo.article_list.diagnostics_finished', [
            'request_id' => self::$requestId,
            'timings_ms' => self::$marks,
            'slow_queries' => self::$slowQueries,
            'slow_query_count' => count(self::$slowQueries),
        ]);

        self::$booted = false;
        self::$requestId = null;
        self::$marks = [];
        self::$slowQueries = [];
    }

    private static function normalizeSql(string $sql): string
    {
        $normalized = preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);

        // Strip long string literals that may contain article HTML.
        $normalized = preg_replace("/'(?:[^'\\\\]|\\\\.){80,}'/", "'…'", $normalized) ?? $normalized;

        return Str::limit($normalized, 2000, '…');
    }
}
