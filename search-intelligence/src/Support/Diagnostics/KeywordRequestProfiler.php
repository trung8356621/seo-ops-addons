<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Support\Diagnostics;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Illuminate\View\View as IlluminateView;

/** @internal Temporary local profiler for the v8 authenticated-request trace. */
final class KeywordRequestProfiler
{
    private static bool $active = false;

    /** @var array<string, float> */
    private static array $starts = [];

    /** @var array<string, float> */
    private static array $durations = [];

    /** @var list<array{sql: string, duration_ms: float}> */
    private static array $queries = [];

    /** @var array<string, int> */
    private static array $views = [];

    /** @var array<string, float> */
    private static array $marks = [];

    public static function register(): void
    {
        if (! app()->environment('local') || ! request()->isMethod('GET') || ! request()->is('seo/keywords')) {
            return;
        }

        self::$active = true;
        self::mark('search_intelligence_provider_ready');

        DB::listen(static function (QueryExecuted $query): void {
            self::$queries[] = [
                'sql' => preg_replace('/\s+/', ' ', trim($query->sql)) ?: $query->sql,
                'duration_ms' => (float) $query->time,
            ];
        });

        View::composer('*', static function (IlluminateView $view): void {
            $name = $view->name();
            self::$views[$name] = (self::$views[$name] ?? 0) + 1;
            self::mark('view:first:'.$name, overwrite: false);
            self::mark('view:last:'.$name);
        });

        Event::listen(RequestHandled::class, static function (RequestHandled $event): void {
            self::finish($event);
        });
    }

    public static function start(string $name): void
    {
        if (self::$active) {
            self::$starts[$name] = hrtime(true) / 1_000_000;
        }
    }

    public static function stop(string $name): void
    {
        if (! self::$active || ! isset(self::$starts[$name])) {
            return;
        }

        self::$durations[$name] = (hrtime(true) / 1_000_000) - self::$starts[$name];
        unset(self::$starts[$name]);
    }

    public static function mark(string $name, bool $overwrite = true): void
    {
        if (! self::$active || (! $overwrite && isset(self::$marks[$name]))) {
            return;
        }

        self::$marks[$name] = self::elapsedMs();
    }

    private static function finish(RequestHandled $event): void
    {
        $totalSql = array_sum(array_column(self::$queries, 'duration_ms'));
        $slowest = self::$queries;
        usort($slowest, static fn (array $left, array $right): int => $right['duration_ms'] <=> $left['duration_ms']);

        $patterns = [];
        foreach (self::$queries as $query) {
            $pattern = preg_replace('/\b\d+\b|\?/', '?', $query['sql']) ?: $query['sql'];
            $patterns[$pattern] ??= ['count' => 0, 'duration_ms' => 0.0];
            $patterns[$pattern]['count']++;
            $patterns[$pattern]['duration_ms'] += $query['duration_ms'];
        }
        uasort($patterns, static fn (array $left, array $right): int => $right['count'] <=> $left['count']);

        arsort(self::$views);
        $content = method_exists($event->response, 'getContent') ? (string) $event->response->getContent() : '';

        Log::info('KEYWORD_REQUEST_PROFILE_V8', [
            'total_ms' => self::elapsedMs(),
            'response_bytes' => strlen($content),
            'status' => $event->response->getStatusCode(),
            'durations_ms' => self::rounded(self::$durations),
            'marks_ms_from_request_start' => self::rounded(self::$marks),
            'sql' => [
                'count' => count(self::$queries),
                'total_ms' => round($totalSql, 3),
                'slowest' => array_slice($slowest, 0, 10),
                'most_repeated_patterns' => array_slice($patterns, 0, 10, true),
            ],
            'most_rendered_views' => array_slice(self::$views, 0, 20, true),
        ]);
    }

    private static function elapsedMs(): float
    {
        $start = (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));

        return (microtime(true) - $start) * 1000;
    }

    /** @param array<string, float> $values */
    private static function rounded(array $values): array
    {
        return array_map(static fn (float $value): float => round($value, 3), $values);
    }
}
