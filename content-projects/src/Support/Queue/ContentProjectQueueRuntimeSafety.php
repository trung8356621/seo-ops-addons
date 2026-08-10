<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\Queue;

/**
 * Pure read-only evaluator for Content Project queue vs database retry_after.
 * No DB/cache/queue mutation — safe for artisan diagnostics and unit tests.
 */
final class ContentProjectQueueRuntimeSafety
{
    public const DEFAULT_RUN_QUEUE = 'seo-content-run';

    public const EXPECTED_JOB_TIMEOUT = 900;

    public const EXPECTED_JOB_TRIES = 1;

    public const EXPECTED_JOB_UNIQUE_FOR = 900;

    public const PRODUCTION_RETRY_AFTER_TARGET = 1200;

    /**
     * @param  array{
     *     queue_connection?: mixed,
     *     retry_after?: mixed,
     *     run_queue?: mixed,
     *     job_timeout?: mixed,
     *     job_tries?: mixed,
     *     job_unique_for?: mixed,
     *     pcntl?: bool|null
     * }  $snapshot
     * @return array{
     *     safe: bool,
     *     failures: list<string>,
     *     lines: list<string>,
     *     queue_connection: string,
     *     run_queue: string,
     *     job_timeout: int,
     *     job_tries: int,
     *     job_unique_for: int,
     *     retry_after: int|null,
     *     pcntl: string
     * }
     */
    public static function evaluate(array $snapshot): array
    {
        $failures = [];

        $queueConnection = trim((string) ($snapshot['queue_connection'] ?? ''));
        if ($queueConnection === '') {
            $failures[] = 'queue connection could not be resolved';
        }

        $runQueue = trim((string) ($snapshot['run_queue'] ?? ''));
        if ($runQueue === '') {
            $failures[] = 'Content Project run queue is empty';
        }

        $jobTimeout = self::positiveIntOrNull($snapshot['job_timeout'] ?? null);
        $jobTries = self::positiveIntOrNull($snapshot['job_tries'] ?? null);
        $jobUniqueFor = self::positiveIntOrNull($snapshot['job_unique_for'] ?? null);

        if ($jobTimeout === null) {
            $failures[] = 'job timeout contract could not be read';
            $jobTimeoutDisplay = 0;
        } else {
            $jobTimeoutDisplay = $jobTimeout;
        }

        if ($jobTries === null) {
            $failures[] = 'job tries contract could not be read';
            $jobTriesDisplay = 0;
        } else {
            $jobTriesDisplay = $jobTries;
        }

        if ($jobUniqueFor === null) {
            $failures[] = 'job uniqueFor contract could not be read';
            $jobUniqueForDisplay = 0;
        } else {
            $jobUniqueForDisplay = $jobUniqueFor;
        }

        $retryAfter = self::positiveIntOrNull($snapshot['retry_after'] ?? null);
        if ($retryAfter === null) {
            $failures[] = 'retry_after is null or invalid';
        } elseif ($jobTimeout !== null && $retryAfter <= $jobTimeout) {
            $failures[] = sprintf(
                'retry_after (%d) must be greater than job timeout (%d)',
                $retryAfter,
                $jobTimeout,
            );
        }

        $pcntlRaw = $snapshot['pcntl'] ?? null;
        $pcntlLabel = match (true) {
            $pcntlRaw === true => 'enabled',
            $pcntlRaw === false => 'disabled',
            default => 'unknown',
        };

        $safe = $failures === [];

        $lines = [
            'Queue connection: '.($queueConnection !== '' ? $queueConnection : '(unresolved)'),
            'Content Project queue: '.($runQueue !== '' ? $runQueue : '(empty)'),
            'Job timeout: '.$jobTimeoutDisplay,
            'Job tries: '.$jobTriesDisplay,
            'Job uniqueFor: '.$jobUniqueForDisplay,
            'retry_after: '.($retryAfter ?? 'null'),
            'pcntl: '.$pcntlLabel,
            '',
        ];

        if ($safe) {
            $lines[] = 'PASS retry_after is greater than job timeout';
        } else {
            $lines[] = 'FAIL unsafe queue runtime configuration';
            foreach ($failures as $failure) {
                $lines[] = '- '.$failure;
            }
        }

        $lines[] = '';
        $lines[] = 'aaPanel queue coverage cannot be verified from Laravel source.';
        $lines[] = 'Expected dedicated worker queue: '.self::DEFAULT_RUN_QUEUE;

        return [
            'safe' => $safe,
            'failures' => $failures,
            'lines' => $lines,
            'queue_connection' => $queueConnection,
            'run_queue' => $runQueue,
            'job_timeout' => $jobTimeoutDisplay,
            'job_tries' => $jobTriesDisplay,
            'job_unique_for' => $jobUniqueForDisplay,
            'retry_after' => $retryAfter,
            'pcntl' => $pcntlLabel,
        ];
    }

    private static function positiveIntOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return null;
        }

        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && is_numeric($value)) {
            $int = (int) $value;

            return $int > 0 ? $int : null;
        }

        if (is_float($value)) {
            $int = (int) $value;

            return $int > 0 ? $int : null;
        }

        return null;
    }
}
