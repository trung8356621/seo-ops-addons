<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Routing;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Closed-set client for POST /v1/tool-intents/match.
 * Transport failure is returned to the router; it is not a JEV fallback.
 */
final class SemanticToolIntentMatcher implements ToolIntentMatcher
{
    public function match(string $query, array $intents): ToolIntentMatchResult
    {
        if ($intents === [] || ! (bool) config('semantic.enabled', false)) {
            return new ToolIntentMatchResult('unavailable', []);
        }

        $base = rtrim((string) config('semantic.url', ''), '/');
        if ($base === '') {
            return new ToolIntentMatchResult('unavailable', []);
        }

        try {
            $response = Http::baseUrl($base)
                ->acceptJson()
                ->asJson()
                ->timeout((int) config('semantic.timeout', 30))
                ->post('/v1/tool-intents/match', [
                    'scope_ref' => 'agent_tool_intents',
                    'query' => $query,
                    'intents' => $intents,
                    'policy' => [
                        'min_positive_score' => 0.62,
                        'min_margin' => 0.08,
                    ],
                ]);
        } catch (Throwable) {
            return new ToolIntentMatchResult('unavailable', []);
        }

        if (! $response->successful()) {
            return new ToolIntentMatchResult('unavailable', []);
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            return new ToolIntentMatchResult('unavailable', []);
        }

        $status = (string) ($payload['status'] ?? 'none');
        if (! in_array($status, ['confident', 'ambiguous', 'none'], true)) {
            $status = 'none';
        }

        $matches = [];
        foreach ((array) ($payload['matches'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $evidence = is_array($row['evidence'] ?? null) ? $row['evidence'] : [];
            $matches[] = [
                'ref' => (string) ($row['ref'] ?? ''),
                'score' => (float) ($row['score'] ?? 0),
                'lexical' => (bool) ($evidence['lexical_matched'] ?? false),
                'semantic_score' => isset($evidence['semantic_score']) ? (float) $evidence['semantic_score'] : null,
            ];
        }

        return new ToolIntentMatchResult($status, $matches);
    }
}
