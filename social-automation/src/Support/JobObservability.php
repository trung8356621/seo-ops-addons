<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Support;

/**
 * Redacted job timeline / observability DTO builder (pure).
 *
 * @phpstan-type EventRow array{
 *     event_type: string,
 *     from_status?: string|null,
 *     to_status?: string|null,
 *     occurred_at?: string|null,
 *     payload?: array<string, mixed>|null
 * }
 * @phpstan-type AttemptRow array{
 *     attempt_uuid: string,
 *     attempt_no: int,
 *     status: string,
 *     started_at?: string|null,
 *     finished_at?: string|null,
 *     error_code?: string|null
 * }
 */
final class JobObservability
{
    /** @var list<string> */
    private const SECRET_KEYS = [
        'cookie', 'cookies', 'password', 'token', 'secret', 'authorization',
        'api_key', 'bearer', 'service_key', 'credential',
    ];

    /**
     * @param  array<string, mixed>  $job
     * @param  list<AttemptRow>  $attempts
     * @param  list<EventRow>  $events
     * @param  array<string, mixed>|null  $account
     * @param  array<string, mixed>|null  $agent
     * @return array<string, mixed>
     */
    public function build(
        array $job,
        array $attempts,
        array $events,
        ?array $account = null,
        ?array $agent = null,
    ): array {
        return [
            'job_uuid' => $job['job_uuid'] ?? null,
            'status' => $job['status'] ?? null,
            'package' => [
                'package_key' => $job['package_key'] ?? null,
                'package_version' => $job['package_version'] ?? null,
                'content_digest' => $job['content_digest'] ?? null,
            ],
            'timestamps' => [
                'reserved_at' => $job['reserved_at'] ?? null,
                'claimed_at' => $job['claimed_at'] ?? null,
                'started_at' => $job['started_at'] ?? null,
                'finished_at' => $job['finished_at'] ?? null,
                'lease_expires_at' => $job['lease_expires_at'] ?? null,
                'duration_ms' => $job['duration_ms'] ?? null,
            ],
            'failure' => [
                'error_code' => $job['error_code'] ?? null,
                'error_message' => $this->redactString(isset($job['error_message']) ? (string) $job['error_message'] : null),
                'classification' => $this->classify((string) ($job['error_code'] ?? ''), (string) ($job['status'] ?? '')),
            ],
            'runner' => $agent === null ? null : [
                'agent_uuid' => $agent['agent_uuid'] ?? null,
                'device_id' => $agent['device_id'] ?? null,
                'runtime_version' => $agent['runtime_version'] ?? null,
            ],
            'account' => $account === null ? null : [
                'account_uuid' => $account['account_uuid'] ?? null,
                'platform' => $account['platform'] ?? null,
                'platform_account_id' => $account['platform_account_id'] ?? null,
                'verified' => (bool) ($account['verified'] ?? false),
                // Never expose declared_email in default timeline.
            ],
            'attempts' => array_map(fn (array $a): array => [
                'attempt_uuid' => $a['attempt_uuid'],
                'attempt_no' => (int) $a['attempt_no'],
                'status' => $a['status'],
                'started_at' => $a['started_at'] ?? null,
                'finished_at' => $a['finished_at'] ?? null,
                'error_code' => $a['error_code'] ?? null,
            ], $attempts),
            'timeline' => array_map(fn (array $e): array => [
                'event_type' => $e['event_type'],
                'from_status' => $e['from_status'] ?? null,
                'to_status' => $e['to_status'] ?? null,
                'occurred_at' => $e['occurred_at'] ?? null,
                'payload' => $this->redactArray(is_array($e['payload'] ?? null) ? $e['payload'] : []),
            ], $events),
        ];
    }

    public function classify(string $errorCode, string $status): string
    {
        $errorCode = strtolower(trim($errorCode));
        if ($status === 'unknown') {
            return 'uncertain_outcome';
        }
        if ($status === 'human_required') {
            return 'human_required';
        }
        if ($errorCode === '') {
            return $status === 'failed' ? 'failed_unspecified' : 'none';
        }
        if (str_contains($errorCode, 'timeout') || str_contains($errorCode, 'lease')) {
            return 'timeout';
        }
        if (str_contains($errorCode, 'offline') || str_contains($errorCode, 'heartbeat')) {
            return 'runner_offline';
        }
        if (str_contains($errorCode, 'session')) {
            return 'session_error';
        }

        return 'runner_reported';
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function redactArray(array $input): array
    {
        $out = [];
        foreach ($input as $key => $value) {
            $k = strtolower((string) $key);
            if (in_array($k, self::SECRET_KEYS, true)) {
                $out[$key] = '[redacted]';
                continue;
            }
            $out[$key] = is_array($value) ? $this->redactArray($value) : $value;
        }

        return $out;
    }

    private function redactString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        foreach (['Bearer ', 'cookie=', 'password='] as $needle) {
            if (str_contains($value, $needle)) {
                return '[redacted]';
            }
        }

        return $value;
    }
}
