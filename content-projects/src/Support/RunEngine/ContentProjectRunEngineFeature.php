<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\RunEngine;

use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;

/**
 * Feature flag + immutable orchestration stamp (Phase 1.8).
 *
 * Single resolver: orchestrationFor($run). Do not OR global flag ad-hoc elsewhere.
 */
final class ContentProjectRunEngineFeature
{
    public const ORCHESTRATION_PHP = 'php';

    public const ORCHESTRATION_LEGACY = 'legacy';

    public static function enabled(): bool
    {
        return (bool) self::configGet('seo-content-ai.content_project.php_engine', false);
    }

    /**
     * Immutable engine ownership for a run.
     * Does NOT re-read global/project allowlist after stamp exists.
     */
    public static function orchestrationFor(SeoProjectRun $run): string
    {
        $settings = is_array($run->settings) ? $run->settings : [];
        $engine = is_array($settings['php_engine'] ?? null) ? $settings['php_engine'] : [];

        $stamped = $engine['orchestration'] ?? $settings['orchestration'] ?? null;
        if ($stamped === self::ORCHESTRATION_PHP || $stamped === self::ORCHESTRATION_LEGACY) {
            return $stamped;
        }

        // Create-time preference before nested stamp (still immutable once set).
        if (array_key_exists('use_php_engine', $settings) && is_bool($settings['use_php_engine'])) {
            return $settings['use_php_engine'] ? self::ORCHESTRATION_PHP : self::ORCHESTRATION_LEGACY;
        }
        if (array_key_exists('use_php_engine', $engine) && is_bool($engine['use_php_engine'])) {
            return $engine['use_php_engine'] ? self::ORCHESTRATION_PHP : self::ORCHESTRATION_LEGACY;
        }

        return self::resolveHistoricalUnstamped($run, $engine);
    }

    /**
     * @param  array<string, mixed>  $engine
     */
    private static function resolveHistoricalUnstamped(SeoProjectRun $run, array $engine): string
    {
        $status = (string) ($run->status ?? '');
        $terminal = in_array($status, [
            SeoProjectRun::STATUS_COMPLETED,
            SeoProjectRun::STATUS_CANCELLED,
            SeoProjectRun::STATUS_FAILED,
        ], true);

        // Terminal unstamped — treat as legacy (no ownership change needed).
        if ($terminal) {
            return self::ORCHESTRATION_LEGACY;
        }

        // Active run with PHP engine signals → PHP (do not use global flag).
        if (self::hasPhpEngineSignals($engine)) {
            return self::ORCHESTRATION_PHP;
        }

        // Active without PHP signals → legacy. Never steal with global ON.
        return self::ORCHESTRATION_LEGACY;
    }

    /**
     * @param  array<string, mixed>  $engine
     */
    public static function hasPhpEngineSignals(array $engine): bool
    {
        if (! empty($engine['active_dispatch']) && is_array($engine['active_dispatch'])) {
            return true;
        }
        if (! empty($engine['started_at'])) {
            return true;
        }
        if (($engine['enabled'] ?? null) === true) {
            return true;
        }

        return false;
    }

    public static function enabledFor(SeoProjectRun $run): bool
    {
        return self::orchestrationFor($run) === self::ORCHESTRATION_PHP;
    }

    public static function isLegacy(SeoProjectRun $run): bool
    {
        return self::orchestrationFor($run) === self::ORCHESTRATION_LEGACY;
    }

    /**
     * Persist orchestration once. No-op if already stamped (immutable).
     *
     * @return array{stamped: bool, orchestration: string}
     */
    public static function ensureStamped(SeoProjectRun $run, ?string $preferred = null): array
    {
        $settings = is_array($run->settings) ? $run->settings : [];
        $engine = is_array($settings['php_engine'] ?? null) ? $settings['php_engine'] : [];
        $existing = $engine['orchestration'] ?? $settings['orchestration'] ?? null;
        if ($existing === self::ORCHESTRATION_PHP || $existing === self::ORCHESTRATION_LEGACY) {
            return ['stamped' => false, 'orchestration' => $existing];
        }

        $status = (string) ($run->status ?? '');
        $terminal = in_array($status, [
            SeoProjectRun::STATUS_COMPLETED,
            SeoProjectRun::STATUS_CANCELLED,
            SeoProjectRun::STATUS_FAILED,
        ], true);
        // Terminal unstamped: do not restamp.
        if ($terminal) {
            return ['stamped' => false, 'orchestration' => self::orchestrationFor($run)];
        }

        $orchestration = $preferred;
        if ($orchestration !== self::ORCHESTRATION_PHP && $orchestration !== self::ORCHESTRATION_LEGACY) {
            $orchestration = self::orchestrationFor($run);
        }

        $engine['orchestration'] = $orchestration;
        $engine['use_php_engine'] = $orchestration === self::ORCHESTRATION_PHP;
        $engine['enabled'] = $orchestration === self::ORCHESTRATION_PHP;
        $settings['use_php_engine'] = $orchestration === self::ORCHESTRATION_PHP;
        $settings['php_engine'] = $engine;
        $run->update(['settings' => $settings]);
        $run->refresh();

        return ['stamped' => true, 'orchestration' => $orchestration];
    }

    public static function enabledForProject(SeoProject $project): bool
    {
        if (self::enabled()) {
            return true;
        }

        $projectId = (int) $project->id;
        $allowlist = self::configGet('seo-content-ai.content_project.php_engine_project_ids', []);
        if (! is_array($allowlist)) {
            return false;
        }

        return in_array($projectId, array_map('intval', $allowlist), true);
    }

    /**
     * Decide at Start time (List) before/while creating run.
     */
    public static function shouldStartWithPhpEngine(SeoProject $project, ?bool $runOptIn = null): bool
    {
        if ($runOptIn === true) {
            return true;
        }
        if ($runOptIn === false) {
            return false;
        }

        return self::enabledForProject($project);
    }

    public static function queueName(): string
    {
        return (string) self::configGet('seo-content-ai.content_project.run_queue', 'seo-content-run');
    }

    public static function activeDispatchTtlMinutes(): int
    {
        return max(1, (int) self::configGet('seo-content-ai.content_project.active_dispatch_ttl_minutes', 45));
    }

    public static function heartbeatStaleMinutes(): int
    {
        return max(1, (int) self::configGet('seo-content-ai.content_project.heartbeat_stale_minutes', 20));
    }

    public static function maxParallelArticles(): int
    {
        $configured = (int) self::configGet('seo-content-ai.content_project.max_parallel_articles', 1);

        return max(1, $configured);
    }

    public static function effectiveMaxParallelArticles(): int
    {
        return 1;
    }

    /**
     * Safe for pure PHPUnit (no Laravel container) — Job ctor / unit tests.
     */
    private static function configGet(string $key, mixed $default): mixed
    {
        try {
            if (! function_exists('app')) {
                return $default;
            }
            $app = app();
            if (! is_object($app) || ! method_exists($app, 'bound') || ! $app->bound('config')) {
                return $default;
            }

            return config($key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }
}
