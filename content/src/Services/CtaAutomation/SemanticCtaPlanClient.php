<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services\CtaAutomation;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Calls the existing semantic service CTA planner. No local heuristic fallback.
 */
final class SemanticCtaPlanClient
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function plan(array $payload): array
    {
        if (config('semantic.enabled') !== true) {
            throw new RuntimeException('semantic_unavailable');
        }
        $base = rtrim((string) config('semantic.url', ''), '/');
        if ($base === '') {
            throw new RuntimeException('semantic_unavailable');
        }

        try {
            $response = Http::baseUrl($base)
                ->acceptJson()
                ->asJson()
                ->timeout((int) config('semantic.timeout', 30))
                ->post('/v1/cta/plan', $payload);
        } catch (\Throwable $exception) {
            throw new RuntimeException('semantic_unavailable', 0, $exception);
        }

        if (! $response->successful()) {
            throw new RuntimeException('semantic_plan_failed');
        }
        $json = $response->json();
        if (! is_array($json) || ! isset($json['placements']) || ! is_array($json['placements'])) {
            throw new RuntimeException('semantic_invalid_plan');
        }

        return $json;
    }
}
