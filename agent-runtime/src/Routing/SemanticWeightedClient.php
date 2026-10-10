<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Routing;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Client for POST /v1/tool-intents/weighted-match. Scoring stays in Python.
 */
final class SemanticWeightedClient implements WeightedRouteEvaluator, HybridRouteEvaluator
{
    public function evaluateHybrid(string $query, array $document): array
    {
        if (! (bool) config('semantic.enabled', false) || rtrim((string) config('semantic.url', ''), '/') === '') {
            return ['status' => 'unavailable', 'reason' => 'semantic_service_disabled'];
        }
        try {
            $response = Http::baseUrl(rtrim((string) config('semantic.url'), '/'))->acceptJson()->asJson()
                ->timeout((int) config('semantic.timeout', 30))->post('/v1/tool-intents/hybrid-match', [
                    'query' => $query,
                    'global_groups' => $document['global'] ?? [],
                    'modules' => $document['modules'] ?? [],
                    'lexical_hints' => $document['lexical_hints'] ?? [],
                    'policy' => $document['policy'] ?? [],
                ]);
        } catch (Throwable) {
            return ['status' => 'unavailable', 'reason' => 'semantic_service_error'];
        }
        $payload = $response->successful() ? $response->json() : null;
        return is_array($payload) ? $payload : ['status' => 'unavailable', 'reason' => 'invalid_semantic_response'];
    }

    public function evaluate(string $query, array $groups): WeightedEvaluation
    {
        if ($groups === [] || ! (bool) config('semantic.enabled', false)) {
            return new WeightedEvaluation('unavailable', null, []);
        }
        $base = rtrim((string) config('semantic.url', ''), '/');
        if ($base === '') {
            return new WeightedEvaluation('unavailable', null, []);
        }

        try {
            $response = Http::baseUrl($base)
                ->acceptJson()
                ->asJson()
                ->timeout((int) config('semantic.timeout', 30))
                ->post('/v1/tool-intents/weighted-match', [
                    'query' => $query,
                    'groups' => $groups,
                ]);
        } catch (Throwable) {
            return new WeightedEvaluation('unavailable', null, []);
        }

        if (! $response->successful()) {
            return new WeightedEvaluation('unavailable', null, []);
        }
        $payload = $response->json();
        if (! is_array($payload)) {
            return new WeightedEvaluation('unavailable', null, []);
        }

        $status = (string) ($payload['status'] ?? 'none');
        if (! in_array($status, ['confident', 'ambiguous', 'none', 'unavailable'], true)) {
            $status = 'none';
        }
        $candidates = [];
        foreach ((array) ($payload['candidates'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $candidates[] = [
                'ref' => (string) ($row['ref'] ?? ''),
                'semantic_relevance' => (float) ($row['semantic_relevance'] ?? 0),
                'weight' => (float) ($row['weight'] ?? 0),
                'score' => (float) ($row['score'] ?? 0),
                'group_id' => (string) ($row['group_id'] ?? ''),
                'example' => (string) ($row['example'] ?? ''),
            ];
        }
        $winner = isset($payload['winner']) ? (string) $payload['winner'] : null;

        return new WeightedEvaluation($status, $winner !== '' ? $winner : null, $candidates);
    }
}
