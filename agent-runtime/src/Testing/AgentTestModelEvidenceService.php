<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Testing;

use Illuminate\Support\Collection;
use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use Omnichannel\Addons\AiPrompt\Models\PromptResultRoutingAttempt;

final class AgentTestModelEvidenceService
{
    /** @return list<array{provider: string|null, model: string}> */
    public function forPromptResult(PromptResult $result): array
    {
        return $this->normalize($result->routingAttempts()
            ->select(['provider', 'provider_model', 'logical_model', 'sequence'])
            ->where('attempted', true)
            ->whereRaw('LOWER(state) = ?', ['success'])
            ->orderBy('sequence')
            ->get());
    }

    /**
     * @param array<string, mixed> ...$workflowPayloads
     * @return list<array{provider: string|null, model: string}>
     */
    public function forWorkflow(array ...$workflowPayloads): array
    {
        $ids = [];
        foreach ($workflowPayloads as $payload) {
            $this->collectPromptResultIds($payload, $ids);
        }
        if ($ids === []) {
            return [];
        }

        return $this->normalize(PromptResultRoutingAttempt::query()
            ->select(['prompt_result_id', 'provider', 'provider_model', 'logical_model', 'sequence'])
            ->whereIn('prompt_result_id', array_values($ids))
            ->where('attempted', true)
            ->whereRaw('LOWER(state) = ?', ['success'])
            ->orderBy('prompt_result_id')
            ->orderBy('sequence')
            ->get());
    }

    /** @param array<int, int> $ids */
    private function collectPromptResultIds(mixed $value, array &$ids, ?string $key = null): void
    {
        if (is_array($value)) {
            foreach ($value as $childKey => $child) {
                $this->collectPromptResultIds($child, $ids, is_string($childKey) ? $childKey : $key);
            }
            return;
        }
        if (! is_numeric($value)) {
            return;
        }
        if (in_array($key, ['prompt_result_id', 'result_id'], true)) {
            $id = (int) $value;
            if ($id > 0) {
                $ids[$id] = $id;
            }
            return;
        }
        if (in_array($key, ['prompt_result_ids', 'child_prompt_result_ids'], true)) {
            $id = (int) $value;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
    }

    /**
     * @param Collection<int, PromptResultRoutingAttempt> $attempts
     * @return list<array{provider: string|null, model: string}>
     */
    private function normalize(Collection $attempts): array
    {
        $models = [];
        foreach ($attempts as $attempt) {
            $provider = trim((string) ($attempt->provider ?? ''));
            $model = trim((string) ($attempt->provider_model ?? ''));
            if ($model === '') {
                $model = trim((string) ($attempt->logical_model ?? ''));
            }
            if ($model === '') {
                continue;
            }
            $key = strtolower($provider).'|'.strtolower($model);
            $models[$key] = ['provider' => $provider !== '' ? $provider : null, 'model' => $model];
        }

        return array_values($models);
    }
}
