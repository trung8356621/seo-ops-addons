<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Runtime;

use Illuminate\Support\Facades\Log;

/**
 * Real shadow/hook parity samples — fingerprint only, no raw prompt/body/secret.
 * In-process aggregates for reporting; durable trail via logs (hosting).
 */
final class PromptHookShadowParityRecorder
{
    /** @var list<array<string, mixed>> */
    private array $samples = [];

    /**
     * @var array<string, array{
     *   hook_key: string,
     *   hook_version: string,
     *   environment: string,
     *   mode: string,
     *   sample_count: int,
     *   match_count: int,
     *   mismatch_count: int,
     *   schema_failure_count: int,
     *   locale_failure_count: int,
     *   marker_leak_count: int,
     *   provider_mapping_failure_count: int,
     *   exception_count: int,
     *   token_cost_anomaly_count: int,
     *   duplicate_ai_call_count: int,
     *   domain_side_effect_mismatch_count: int,
     *   prompt_result_linkage_mismatch_count: int,
     *   first_seen: string,
     *   last_seen: string
     * }>
     */
    private array $aggregates = [];

    /**
     * @param  array<string, mixed>  $sample
     */
    public function record(array $sample): void
    {
        unset($sample['full_prompt'], $sample['full_response'], $sample['raw_output'], $sample['api_key'], $sample['secret']);

        $now = now()->toIso8601String();
        $sample['environment'] = (string) ($sample['environment'] ?? app()->environment());
        $sample['recorded_at'] = (string) ($sample['recorded_at'] ?? $now);

        $this->samples[] = $sample;
        $this->bumpAggregate($sample, $now);

        Log::info('prompt_hook.shadow_parity', $sample);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function samplesFor(string $hookKey, ?string $version = null): array
    {
        return array_values(array_filter(
            $this->samples,
            static function (array $s) use ($hookKey, $version): bool {
                if (($s['hook_key'] ?? '') !== $hookKey) {
                    return false;
                }
                if ($version !== null && ($s['hook_version'] ?? '') !== $version) {
                    return false;
                }

                return true;
            },
        ));
    }

    /**
     * @return array{
     *   hook_key: string,
     *   hook_version: string,
     *   environment: string,
     *   mode: string,
     *   sample_count: int,
     *   match_count: int,
     *   mismatch_count: int,
     *   schema_failure_count: int,
     *   locale_failure_count: int,
     *   marker_leak_count: int,
     *   provider_mapping_failure_count: int,
     *   exception_count: int,
     *   token_cost_anomaly_count: int,
     *   duplicate_ai_call_count: int,
     *   domain_side_effect_mismatch_count: int,
     *   prompt_result_linkage_mismatch_count: int,
     *   first_seen: ?string,
     *   last_seen: ?string
     * }
     */
    public function reportFor(string $hookKey, string $version = '', ?string $mode = null, ?string $environment = null): array
    {
        $empty = $this->emptyReport($hookKey, $version, $mode ?? 'shadow', $environment ?? (string) app()->environment());
        $matches = [];
        foreach ($this->aggregates as $row) {
            if ($row['hook_key'] !== $hookKey) {
                continue;
            }
            if ($version !== '' && $row['hook_version'] !== $version) {
                continue;
            }
            if ($mode !== null && $row['mode'] !== $mode) {
                continue;
            }
            if ($environment !== null && $row['environment'] !== $environment) {
                continue;
            }
            $matches[] = $row;
        }

        if ($matches === []) {
            return $empty;
        }

        $merged = $empty;
        foreach ($matches as $row) {
            foreach ([
                'sample_count', 'match_count', 'mismatch_count', 'schema_failure_count',
                'locale_failure_count', 'marker_leak_count', 'provider_mapping_failure_count',
                'exception_count', 'token_cost_anomaly_count', 'duplicate_ai_call_count',
                'domain_side_effect_mismatch_count', 'prompt_result_linkage_mismatch_count',
            ] as $field) {
                $merged[$field] += $row[$field];
            }
            $merged['hook_version'] = $row['hook_version'] !== '' ? $row['hook_version'] : $merged['hook_version'];
            $merged['mode'] = $row['mode'];
            $merged['environment'] = $row['environment'];
            if ($merged['first_seen'] === null || $row['first_seen'] < $merged['first_seen']) {
                $merged['first_seen'] = $row['first_seen'];
            }
            if ($merged['last_seen'] === null || $row['last_seen'] > $merged['last_seen']) {
                $merged['last_seen'] = $row['last_seen'];
            }
        }

        return $merged;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function allReports(): array
    {
        return array_values($this->aggregates);
    }

    public function clear(): void
    {
        $this->samples = [];
        $this->aggregates = [];
    }

    /**
     * @param  array<string, mixed>  $sample
     */
    private function bumpAggregate(array $sample, string $now): void
    {
        $hookKey = (string) ($sample['hook_key'] ?? '');
        $version = (string) ($sample['hook_version'] ?? '');
        $mode = (string) ($sample['mode'] ?? 'shadow');
        $environment = (string) ($sample['environment'] ?? 'unknown');
        $bucket = $hookKey.'@'.$version.'#'.$mode.'#'.$environment;

        if (! isset($this->aggregates[$bucket])) {
            $this->aggregates[$bucket] = $this->emptyReport($hookKey, $version, $mode, $environment);
            $this->aggregates[$bucket]['first_seen'] = $now;
            $this->aggregates[$bucket]['last_seen'] = $now;
        }

        $row = &$this->aggregates[$bucket];
        $row['sample_count']++;
        $row['last_seen'] = $now;

        $matched = (bool) ($sample['matched'] ?? (
            ($sample['schema_ok'] ?? true)
            && ! ($sample['marker_leak'] ?? false)
            && ! ($sample['locale_failure'] ?? false)
            && ! ($sample['provider_mapping_failure'] ?? false)
            && ! ($sample['exception'] ?? false)
            && ! ($sample['cost_anomaly'] ?? false)
            && ! ($sample['duplicate_ai_call'] ?? false)
            && ! ($sample['domain_side_effect_mismatch'] ?? false)
            && ! ($sample['prompt_result_linkage_mismatch'] ?? false)
        ));

        if ($matched) {
            $row['match_count']++;
        } else {
            $row['mismatch_count']++;
        }

        if (($sample['schema_ok'] ?? true) === false) {
            $row['schema_failure_count']++;
        }
        if ($sample['locale_failure'] ?? false) {
            $row['locale_failure_count']++;
        }
        if ($sample['marker_leak'] ?? false) {
            $row['marker_leak_count']++;
        }
        if ($sample['provider_mapping_failure'] ?? false) {
            $row['provider_mapping_failure_count']++;
        }
        if ($sample['exception'] ?? false) {
            $row['exception_count']++;
        }
        if ($sample['cost_anomaly'] ?? false) {
            $row['token_cost_anomaly_count']++;
        }
        if ($sample['duplicate_ai_call'] ?? false) {
            $row['duplicate_ai_call_count']++;
        }
        if ($sample['domain_side_effect_mismatch'] ?? false) {
            $row['domain_side_effect_mismatch_count']++;
        }
        if ($sample['prompt_result_linkage_mismatch'] ?? false) {
            $row['prompt_result_linkage_mismatch_count']++;
        }
    }

    /**
     * @return array{
     *   hook_key: string,
     *   hook_version: string,
     *   environment: string,
     *   mode: string,
     *   sample_count: int,
     *   match_count: int,
     *   mismatch_count: int,
     *   schema_failure_count: int,
     *   locale_failure_count: int,
     *   marker_leak_count: int,
     *   provider_mapping_failure_count: int,
     *   exception_count: int,
     *   token_cost_anomaly_count: int,
     *   duplicate_ai_call_count: int,
     *   domain_side_effect_mismatch_count: int,
     *   prompt_result_linkage_mismatch_count: int,
     *   first_seen: ?string,
     *   last_seen: ?string
     * }
     */
    private function emptyReport(string $hookKey, string $version, string $mode, string $environment): array
    {
        return [
            'hook_key' => $hookKey,
            'hook_version' => $version,
            'environment' => $environment,
            'mode' => $mode,
            'sample_count' => 0,
            'match_count' => 0,
            'mismatch_count' => 0,
            'schema_failure_count' => 0,
            'locale_failure_count' => 0,
            'marker_leak_count' => 0,
            'provider_mapping_failure_count' => 0,
            'exception_count' => 0,
            'token_cost_anomaly_count' => 0,
            'duplicate_ai_call_count' => 0,
            'domain_side_effect_mismatch_count' => 0,
            'prompt_result_linkage_mismatch_count' => 0,
            'first_seen' => null,
            'last_seen' => null,
        ];
    }
}
