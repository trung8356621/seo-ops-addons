<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\Models\Prompt;
use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use Omnichannel\Addons\AiPrompt\Models\PromptResultRoutingAttempt;
use Omnichannel\Addons\AiPrompt\Models\PromptVersion;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;

/**
 * Hot History persistence: no compiled_prompt blobs, version + routing attempts as SoT.
 */
final class PromptExecutionPersistence
{
    public const CONNECTION = 'omi_seo_ai';

    public function __construct(
        private readonly PromptVersionService $versions,
    ) {}

    public function normalizeBeforeSave(PromptResult $result): void
    {
        $snapshot = is_array($result->input_snapshot) ? $result->input_snapshot : [];
        $canHashCompiled = Schema::connection(self::CONNECTION)->hasColumn('prompt_results', 'compiled_prompt_hash');
        $compiled = trim((string) ($snapshot['compiled_prompt'] ?? ''));
        $retainExact = $this->shouldRetainExactCompiledPrompt($snapshot);

        if ($canHashCompiled && $compiled !== '') {
            $hash = $this->compiledPromptHash($compiled);
            $result->compiled_prompt_hash = $hash;
            $snapshot['compiled_prompt_hash'] = $hash;
            if (! $retainExact) {
                unset($snapshot['compiled_prompt']);
            }
            $result->input_snapshot = $this->slimSnapshot($snapshot, $retainExact);
        } elseif ($canHashCompiled) {
            $result->input_snapshot = $this->slimSnapshot($snapshot, $retainExact);
        }

        $this->fillExecutionColumns($result, is_array($result->input_snapshot) ? $result->input_snapshot : []);
    }

    /**
     * Exact provider-boundary prompts (sectioned / manual_compiled) must remain inspectable
     * in AI History — hash alone is not enough for "Xem prompt".
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function shouldRetainExactCompiledPrompt(array $snapshot): bool
    {
        if (! empty($snapshot['retain_compiled_prompt'])) {
            return true;
        }
        if (! empty($snapshot['manual_compiled'])) {
            return true;
        }
        if (! empty($snapshot['sectioned_free_section'])) {
            return true;
        }

        return ! empty($snapshot['sectioned_free_orchestrator']);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function fillExecutionColumns(PromptResult $result, array $snapshot): void
    {
        if (! Schema::connection(self::CONNECTION)->hasColumn('prompt_results', 'prompt_version_id')) {
            return;
        }

        $variables = is_array($snapshot['variables'] ?? null) ? $snapshot['variables'] : [];

        $promptId = (int) ($result->prompt_id ?? 0);
        if ($promptId > 0 && empty($result->prompt_version_id)) {
            $prompt = $result->relationLoaded('prompt') && $result->prompt instanceof Prompt
                ? $result->prompt
                : SeoPrompt::query()->find($promptId);
            if ($prompt instanceof Prompt) {
                $version = $this->versions->ensureCurrentVersion($prompt);
                if ($version instanceof PromptVersion) {
                    $result->prompt_version_id = (int) $version->id;
                }
            }
        }

        $hookKey = trim((string) (
            $result->canonical_prompt_key
            ?? $snapshot['hook_key']
            ?? $snapshot['display_hook_key']
            ?? $variables['hook_key']
            ?? ''
        ));
        if ($hookKey === '' && ! empty($result->prompt_version_id)) {
            $version = $result->relationLoaded('promptVersion') && $result->promptVersion instanceof PromptVersion
                ? $result->promptVersion
                : PromptVersion::query()->find((int) $result->prompt_version_id);
            if ($version instanceof PromptVersion) {
                $hookKey = trim((string) ($version->hook_key ?? ''));
            }
        }
        if ($hookKey !== '') {
            $result->canonical_prompt_key = $hookKey;
        }

        $stage = trim((string) (
            $result->stage
            ?? $snapshot['stage']
            ?? $snapshot['execution_role']
            ?? $variables['execution_role']
            ?? $hookKey
        ));
        if ($stage !== '') {
            $result->stage = $stage;
        }

        $projectId = (int) ($snapshot['content_project_id'] ?? $snapshot['project_id'] ?? $variables['project_id'] ?? 0);
        if ($projectId > 0) {
            $result->content_project_id = $projectId;
        }

        $itemId = (int) ($snapshot['project_item_id'] ?? $variables['project_item_id'] ?? 0);
        if ($itemId > 0) {
            $result->project_item_id = $itemId;
        }

        $runId = (int) ($snapshot['project_run_id'] ?? $snapshot['run_id'] ?? $variables['project_run_id'] ?? $variables['run_id'] ?? 0);
        if ($runId > 0) {
            $result->run_id = $runId;
        }

        $nodeId = trim((string) ($snapshot['workflow_node_id'] ?? $snapshot['node_id'] ?? $variables['node_id'] ?? ''));
        if ($nodeId !== '') {
            $result->node_id = $nodeId;
        }

        $retry = (int) ($snapshot['attempt'] ?? $snapshot['retry_attempt'] ?? $variables['attempt'] ?? 0);
        if ($retry > 0) {
            $result->retry_attempt = $retry;
        }

        $correlation = trim((string) (
            $result->correlation_id
            ?? $snapshot['correlation_id']
            ?? $variables['correlation_id']
            ?? ''
        ));
        $usage = is_array($result->token_usage) ? $result->token_usage : [];
        if ($correlation === '' && is_string($usage['routing']['correlation_id'] ?? null)) {
            $correlation = trim((string) $usage['routing']['correlation_id']);
        }
        if ($correlation !== '') {
            $result->correlation_id = $correlation;
        }

        $failure = is_array($usage['normalized_failure'] ?? null) ? $usage['normalized_failure'] : null;
        if (is_array($failure)) {
            $category = strtoupper(trim((string) ($failure['category'] ?? '')));
            $code = trim((string) ($failure['code'] ?? $failure['failure_code'] ?? ''));
            if ($category !== '') {
                $result->failure_category = $category;
            }
            if ($code !== '') {
                $result->failure_code = $code;
            }
        }
    }

    public function syncRoutingAttempts(PromptResult $result): void
    {
        if (! Schema::connection(self::CONNECTION)->hasTable('prompt_result_routing_attempts')) {
            return;
        }

        $resultId = (int) $result->getKey();
        if ($resultId <= 0) {
            return;
        }

        $attempts = $this->extractRoutingAttempts($result);
        if ($attempts === []) {
            return;
        }

        PromptResultRoutingAttempt::query()->where('prompt_result_id', $resultId)->delete();

        $rows = [];
        $sequence = 1;
        foreach ($attempts as $row) {
            if (! is_array($row)) {
                continue;
            }
            $resultState = strtolower(trim((string) ($row['result'] ?? $row['state'] ?? '')));
            $attempted = (bool) ($row['attempted'] ?? in_array($resultState, ['success', 'failed'], true));
            $rows[] = [
                'prompt_result_id' => $resultId,
                // Route-event order, not router "attempt" (API attempt #1 collides with skipped candidate #1).
                'sequence' => $sequence,
                'logical_model' => $this->nullableString($row['logical_model'] ?? null),
                'physical_route' => $this->nullableString($row['physical_route'] ?? null),
                'provider' => $this->nullableString($row['provider'] ?? null),
                'connection_id' => isset($row['connection_id']) ? (int) $row['connection_id'] : null,
                'connection_name' => $this->nullableString($row['connection_name'] ?? null),
                'provider_model' => $this->nullableString(
                    $row['actual_provider_model'] ?? $row['provider_model'] ?? $row['model'] ?? $row['candidate_model'] ?? null,
                ),
                'cost_class' => $this->nullableString(
                    $row['cost_class'] ?? ((isset($row['is_free']) && $row['is_free']) || ($row['is_free_candidate'] ?? false) ? 'free' : 'paid'),
                ),
                'state' => $this->nullableString($row['status'] ?? $row['state'] ?? strtoupper($resultState)),
                'attempted' => $attempted,
                'skip_reason' => $this->nullableString($row['skip_reason'] ?? null),
                'http_status' => isset($row['http_status']) && is_numeric($row['http_status']) ? (int) $row['http_status'] : null,
                'failure_category' => $this->nullableString($row['failure_category'] ?? $row['failure_class'] ?? null),
                'failure_code' => $this->nullableString($row['failure_code'] ?? $row['failure_class'] ?? null),
                'failure_scope' => $this->nullableString($row['failure_scope'] ?? $row['scope'] ?? null),
                'health_mutation' => $this->nullableString($row['health_mutation'] ?? null),
                'duration_ms' => isset($row['duration_ms']) && is_numeric($row['duration_ms']) ? (int) $row['duration_ms'] : null,
                'token_usage' => isset($row['token_usage']) && is_array($row['token_usage'])
                    ? json_encode($row['token_usage'])
                    : null,
                'raw' => json_encode($row),
                'created_at' => now(),
                'updated_at' => now(),
            ];
            $sequence++;
        }

        if ($rows !== []) {
            PromptResultRoutingAttempt::query()->insert($rows);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function extractRoutingAttempts(PromptResult $result): array
    {
        if ($result->relationLoaded('routingAttempts') && $result->routingAttempts->isNotEmpty()) {
            return $result->routingAttempts
                ->map(static fn (PromptResultRoutingAttempt $row): array => $row->raw ?? $row->toArray())
                ->all();
        }

        $usage = is_array($result->token_usage) ? $result->token_usage : [];
        if (is_array($usage['routing']['routing_attempts'] ?? null)) {
            return $usage['routing']['routing_attempts'];
        }
        if (is_array($usage['routing_attempts'] ?? null)) {
            return $usage['routing_attempts'];
        }

        return [];
    }

    /**
     * Strip large compiled blobs (unless exact execution prompt must be retained);
     * keep joinable refs + small execution metadata.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function slimSnapshot(array $snapshot, ?bool $retainExactCompiled = null): array
    {
        $retainExactCompiled ??= $this->shouldRetainExactCompiledPrompt($snapshot);
        if (! $retainExactCompiled) {
            unset($snapshot['compiled_prompt']);
        }

        $largeKeys = [
            'post_content', 'post_excerpt', 'outline', 'outline_markdown',
            'vocabulary', 'article_body', 'html', 'content', 'raw_content',
            'parent_result', 'PARENT_RESULT', 'previous_outputs',
        ];
        $hasRefs = (int) ($snapshot['article_id'] ?? $snapshot['project_run_id'] ?? $snapshot['content_project_id'] ?? 0) > 0
            || (int) ($snapshot['variables']['article_id'] ?? 0) > 0;
        if ($hasRefs && is_array($snapshot['variables'] ?? null)) {
            foreach ($largeKeys as $key) {
                unset($snapshot['variables'][$key]);
            }
        }

        return $snapshot;
    }

    public function compiledPromptHash(string $compiled): string
    {
        return hash('sha256', $compiled);
    }

    private function nullableString(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }
}
