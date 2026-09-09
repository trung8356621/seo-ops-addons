<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Support;

use Omnichannel\Addons\AiPrompt\Support\AiHistoryRouteDisplay;

/**
 * Prompt-centric AI History grouping + display helpers.
 * Localized titles are display-only; grouping key is canonical prompt/hook key.
 */
final class ArticleAiHistoryPromptCentricPresenter
{
    public const MODEL_NO_ATTEMPT = AiHistoryRouteDisplay::MODEL_NO_ATTEMPT;

    public const MODEL_LEGACY_UNAVAILABLE = AiHistoryRouteDisplay::MODEL_LEGACY_UNAVAILABLE;

    /**
     * Regroup run-centric groups into prompt-key groups (latest first).
     *
     * @param  list<array<string, mixed>>  $runGroups
     * @return list<array<string, mixed>>
     */
    public static function regroupByPromptKey(array $runGroups, bool $latestOnly = false): array
    {
        /** @var array<string, array{prompt_key: string, title: string, stage: ?string, prompts: list<array<string, mixed>>, latest_ran_at: ?string}> $buckets */
        $buckets = [];

        foreach ($runGroups as $group) {
            $prompts = is_array($group['prompts'] ?? null) ? $group['prompts'] : [];
            foreach ($prompts as $prompt) {
                if (! is_array($prompt)) {
                    continue;
                }
                $enriched = self::enrichPromptItem($prompt, $group);
                $key = (string) ($enriched['canonical_prompt_key'] ?? 'unknown');
                if (! isset($buckets[$key])) {
                    $buckets[$key] = [
                        'id' => 'prompt-'.$key,
                        'prompt_key' => $key,
                        'title' => (string) ($enriched['type'] ?? $key),
                        'stage' => $enriched['stage'] ?? null,
                        'prompts' => [],
                        'latest_ran_at' => null,
                        'run_id' => null,
                    ];
                }
                $buckets[$key]['prompts'][] = $enriched;
                $ranAt = self::sortableTime($enriched['ran_at'] ?? null);
                $current = self::sortableTime($buckets[$key]['latest_ran_at'] ?? null);
                if ($ranAt !== null && ($current === null || $ranAt > $current)) {
                    $buckets[$key]['latest_ran_at'] = $enriched['ran_at'] ?? null;
                    $buckets[$key]['title'] = (string) ($enriched['type'] ?? $buckets[$key]['title']);
                    $buckets[$key]['stage'] = $enriched['stage'] ?? $buckets[$key]['stage'];
                }
            }
        }

        foreach ($buckets as &$bucket) {
            usort($bucket['prompts'], static function (array $a, array $b): int {
                $ta = self::sortableTime($a['ran_at'] ?? null) ?? '';
                $tb = self::sortableTime($b['ran_at'] ?? null) ?? '';

                return $tb <=> $ta;
            });
            if ($latestOnly && $bucket['prompts'] !== []) {
                $bucket['prompts'] = [$bucket['prompts'][0]];
            }
            $latest = $bucket['prompts'][0] ?? null;
            if (is_array($latest)) {
                $bucket['latest_status'] = $latest['status'] ?? null;
                $bucket['latest_model'] = $latest['model_display'] ?? $latest['model'] ?? null;
                $bucket['latest_failure_category'] = $latest['failure_category'] ?? null;
                $bucket['latest_failure_summary'] = $latest['failure_summary'] ?? null;
                $bucket['ran_at'] = $latest['ran_at'] ?? $bucket['latest_ran_at'];
            }
        }
        unset($bucket);

        $list = array_values($buckets);
        usort($list, static function (array $a, array $b): int {
            $ta = self::sortableTime($a['latest_ran_at'] ?? $a['ran_at'] ?? null) ?? '';
            $tb = self::sortableTime($b['latest_ran_at'] ?? $b['ran_at'] ?? null) ?? '';

            return $tb <=> $ta;
        });

        return $list;
    }

    /**
     * @param  array<string, mixed>  $prompt
     * @param  array<string, mixed>  $group
     * @return array<string, mixed>
     */
    public static function enrichPromptItem(array $prompt, array $group = []): array
    {
        $hookKey = trim((string) ($prompt['canonical_prompt_key'] ?? $prompt['hook_key'] ?? $prompt['execution_role'] ?? ''));
        $canonical = $hookKey !== '' ? $hookKey : 'unknown';
        $stage = trim((string) ($prompt['stage'] ?? $prompt['execution_role'] ?? $hookKey));
        $routingAttempts = is_array($prompt['routing_attempts'] ?? null) ? $prompt['routing_attempts'] : [];
        $tokenUsage = is_array($prompt['token_usage'] ?? null) ? $prompt['token_usage'] : [];
        if ($routingAttempts === [] && is_array($tokenUsage['routing']['routing_attempts'] ?? null)) {
            $routingAttempts = $tokenUsage['routing']['routing_attempts'];
        }

        $modelDisplay = AiHistoryRouteDisplay::resolveModelDisplay($prompt, $routingAttempts);
        $failure = is_array($prompt['normalized_failure'] ?? null)
            ? $prompt['normalized_failure']
            : (is_array($tokenUsage['normalized_failure'] ?? null) ? $tokenUsage['normalized_failure'] : null);

        $validationContract = $prompt['validation_contract']
            ?? $tokenUsage['validation_contract']
            ?? null;
        $validatorsApplied = $prompt['validators_applied']
            ?? $tokenUsage['validators_applied']
            ?? null;

        $category = strtoupper(trim((string) ($prompt['failure_category'] ?? '')));
        if ($category === '' && is_array($failure)) {
            $category = strtoupper(trim((string) ($failure['category'] ?? '')));
        }
        $summary = is_array($failure) ? (string) ($failure['user_message'] ?? $failure['code'] ?? '') : '';
        if ($summary === '') {
            $summary = trim((string) ($prompt['failure_code'] ?? ''));
        }
        if ($summary === '' && in_array(($prompt['status'] ?? ''), ['failed', 'error'], true)) {
            $summary = trim((string) ($prompt['message'] ?? ''));
        }

        return array_merge($prompt, [
            'canonical_prompt_key' => $canonical,
            'stage' => $stage !== '' ? $stage : null,
            'model_display' => $modelDisplay,
            'routing_attempts' => $routingAttempts,
            'failure_category' => $category !== '' ? $category : null,
            'failure_summary' => $summary !== '' ? $summary : null,
            'normalized_failure' => $failure,
            'validation_contract' => is_string($validationContract) ? $validationContract : null,
            'validators_applied' => is_array($validatorsApplied) ? $validatorsApplied : null,
            'run_id' => $prompt['run_id'] ?? $group['run_id'] ?? null,
            'project_name' => $prompt['project_name'] ?? $group['project_name'] ?? null,
            'routing_plan' => $prompt['routing_plan']
                ?? $tokenUsage['routing']['routing_plan']
                ?? null,
            'routing_mode' => $prompt['routing_mode']
                ?? $tokenUsage['routing']['routing_mode']
                ?? null,
            'correlation_id' => $prompt['correlation_id']
                ?? $tokenUsage['routing']['correlation_id']
                ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $prompt
     * @param  list<array<string, mixed>>  $routingAttempts
     */
    public static function resolveModelDisplay(array $prompt, array $routingAttempts): string
    {
        return AiHistoryRouteDisplay::resolveModelDisplay($prompt, $routingAttempts);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function formatAttemptRoute(array $row): string
    {
        return AiHistoryRouteDisplay::formatAttemptRoute($row);
    }

    private static function sortableTime(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('c');
        }
        if (is_string($value) && trim($value) !== '') {
            try {
                return (new \DateTimeImmutable(trim($value)))->format('c');
            } catch (\Throwable) {
                return trim($value);
            }
        }

        return null;
    }
}
