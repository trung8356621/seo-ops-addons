<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Migration;

use Omnichannel\Addons\Agent\Automation\Support\SensitivePayloadRedactor;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class AutomationParityLogger
{
    /** @var list<string> */
    private const STRIP_KEYS = [
        'body', 'content', 'html', 'article_body', 'wp_post_content',
        'password', 'token', 'api_token', 'authorization', 'secret',
        'rewrite_notes', 'prompt', 'raw_payload',
    ];

    public function __construct(
        private readonly SensitivePayloadRedactor $redactor,
        private readonly AutomationParitySampleRecorder $samples,
    ) {}

    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $actual
     */
    public function compare(
        string $callerKey,
        string $actionKey,
        array $expected,
        array $actual,
        ?string $correlationId = null,
        ?int $durationMs = null,
    ): ParityCompareResult {
        $correlationId = $correlationId !== null && $correlationId !== ''
            ? $correlationId
            : Str::uuid()->toString();
        $durationMs = $durationMs ?? 0;

        $expectedNorm = $this->sanitize($this->ksortRecursive($expected));
        $actualNorm = $this->sanitize($this->ksortRecursive($actual));
        $diff = $this->normalizedDiff($expectedNorm, $actualNorm);
        $matched = $diff === [];

        $this->samples->record($callerKey, $matched);

        $context = [
            'caller' => $callerKey,
            'action_key' => $actionKey,
            'correlation_id' => $correlationId,
            'duration_ms' => $durationMs,
            'sample' => $this->samples->forCaller($callerKey),
        ];

        if ($matched) {
            Log::info('automation.migration.parity_match', $context);

            return new ParityCompareResult(
                matched: true,
                callerKey: $callerKey,
                actionKey: $actionKey,
                correlationId: $correlationId,
                durationMs: $durationMs,
                normalizedDiff: [],
            );
        }

        Log::warning('automation.migration.parity_mismatch', array_merge($context, [
            'normalized_diff' => $diff,
            'expected_keys' => array_keys($expectedNorm),
            'actual_keys' => array_keys($actualNorm),
        ]));

        return new ParityCompareResult(
            matched: false,
            callerKey: $callerKey,
            actionKey: $actionKey,
            correlationId: $correlationId,
            durationMs: $durationMs,
            normalizedDiff: $diff,
        );
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function sanitize(array $value): array
    {
        $redacted = $this->redactor->redact($value);
        foreach (self::STRIP_KEYS as $key) {
            unset($redacted[$key]);
        }

        // Drop nested heavy keys.
        foreach ($redacted as $k => $item) {
            if (is_array($item)) {
                $redacted[$k] = $this->sanitize($item);
            }
        }

        return $redacted;
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function ksortRecursive(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->ksortRecursive($item);
            }
        }
        ksort($value);

        return $value;
    }

    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $actual
     * @return array<string, array{expected: mixed, actual: mixed}>
     */
    private function normalizedDiff(array $expected, array $actual, string $prefix = ''): array
    {
        $diff = [];
        $keys = array_values(array_unique(array_merge(array_keys($expected), array_keys($actual))));

        foreach ($keys as $key) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            $left = $expected[$key] ?? null;
            $right = $actual[$key] ?? null;

            if (is_array($left) && is_array($right)) {
                $diff = array_merge($diff, $this->normalizedDiff($left, $right, $path));

                continue;
            }

            if ($left !== $right) {
                $diff[$path] = [
                    'expected' => $this->scalarize($left),
                    'actual' => $this->scalarize($right),
                ];
            }
        }

        return $diff;
    }

    private function scalarize(mixed $value): mixed
    {
        if (is_scalar($value) || $value === null) {
            return $value;
        }

        if (is_array($value)) {
            return ['type' => 'array', 'keys' => array_keys($value)];
        }

        return ['type' => get_debug_type($value)];
    }
}
