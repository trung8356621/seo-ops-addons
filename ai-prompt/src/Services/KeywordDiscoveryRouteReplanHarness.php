<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\DataTransfer\ModelContextCapability;
use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\PromptBudget\KeywordDiscoveryBudgetStrategy;
use Omnichannel\Addons\AiPrompt\PromptBudget\PromptChunkLedger;
use Omnichannel\Addons\AiPrompt\Support\AiFailureClass;

/**
 * Simulated KD bounded execution for E2E harness (qty=50) without full Laravel job stack.
 * Demonstrates route-specific replan: each attempt builds payload for THAT capability.
 */
final class KeywordDiscoveryRouteReplanHarness
{
    /**
     * @param  list<array{
     *   id: string,
     *   capability: ModelContextCapability,
     *   provider: callable(string $compiled, int $batchTarget, ModelContextCapability $cap): array{ideas: list<array{keyword: string, fingerprint: string}>, http_status?: int, schema_error?: bool}
     * }>  $routes
     * @return array{
     *   accepted: list<array{keyword: string, fingerprint: string}>,
     *   provider_calls: array<string, int>,
     *   payloads: list<array{route: string, batch: int, compiled_chars: int}>,
     *   ledger: array<string, mixed>,
     *   stopped_reason: string|null
     * }
     */
    public function runToTarget(int $requestedTotal, array $routes, string $immutableBrief): array
    {
        $strategy = new KeywordDiscoveryBudgetStrategy();
        $estimator = new PromptTokenEstimator();
        $ledger = new PromptChunkLedger();
        $ledger->setRun('kd-e2e', KeywordDiscoveryBudgetStrategy::HOOK);

        $accepted = [];
        $providerCalls = [];
        $payloads = [];
        $stoppedReason = null;
        $routeIndex = 0;

        while (count($accepted) < $requestedTotal && $routeIndex < count($routes)) {
            $route = $routes[$routeIndex];
            $routeId = (string) $route['id'];
            $capability = $route['capability'];
            $providerCalls[$routeId] = $providerCalls[$routeId] ?? 0;

            $remaining = $requestedTotal - count($accepted);
            $immutableTokens = $estimator->estimate($immutableBrief, $capability->estimatorFamily);
            $continuation = $this->continuationFromAccepted($accepted);
            $continuationTokens = $estimator->estimate($continuation, $capability->estimatorFamily);
            $batch = $strategy->resolveBatchTarget($remaining, $immutableTokens, $continuationTokens, $capability);
            if ($batch < 1) {
                $routeIndex++;
                continue;
            }

            $desired = $strategy->estimateOutputReserve(['batch_target' => $batch], $capability);
            $minimum = max(64, (int) floor($desired * 0.35));
            if ($minimum > $capability->maxOutputTokens) {
                // Shrink until output capability fits.
                while ($batch > 1 && $minimum > $capability->maxOutputTokens) {
                    $batch--;
                    $desired = $strategy->estimateOutputReserve(['batch_target' => $batch], $capability);
                    $minimum = max(64, (int) floor($desired * 0.35));
                }
                if ($minimum > $capability->maxOutputTokens) {
                    $routeIndex++;
                    continue;
                }
            }

            $compiled = $immutableBrief."\n\n".$continuation."\n\nGenerate exactly {$batch} unique ideas as JSON.";
            // Final outbound check (compiled already contains continuation — no double-count).
            $preflight = new PromptBudgetPreflightService();
            $plan = $preflight->planWithCapability($capability, $strategy, $compiled, [
                'continuation_already_inlined' => true,
                'schema_already_inlined' => true,
                'desired_output_tokens' => $desired,
                'minimum_required_output_tokens' => $minimum,
                'batch_target' => $batch,
            ]);
            if (! $plan->requestFits || ! $plan->outputCapabilitySufficient) {
                $routeIndex++;
                continue;
            }

            $chunkId = $routeId.'-batch-'.count($payloads);
            $inputHash = hash('sha256', $compiled);
            if ($ledger->isCompletedWithHash($chunkId, $inputHash)) {
                continue;
            }
            $ledger->planChunk($chunkId, $inputHash, count($payloads));
            $ledger->markRunning($chunkId, $routeIndex);

            $payloads[] = [
                'route' => $routeId,
                'batch' => $batch,
                'compiled_chars' => mb_strlen($compiled),
            ];
            $providerCalls[$routeId]++;

            $result = ($route['provider'])($compiled, $batch, $capability);
            $http = (int) ($result['http_status'] ?? 200);

            if (($result['schema_error'] ?? false) === true && $http === 200) {
                $ledger->markFailed($chunkId);
                $stoppedReason = 'schema_error';
                throw new PromptRunException(
                    'KD schema validation failed',
                    0,
                    null,
                    [
                    'classification' => AiFailureClass::ProviderInvalidOutput->value,
                    'retryable' => false,
                    ],
                );
            }

            if ($http >= 500 || $http === 402 || $http === 429) {
                $ledger->markFailed($chunkId);
                // Eligible API failure → next route rebuilds remaining work (same-attempt semantics in loop).
                $routeIndex++;
                continue;
            }

            $ideas = is_array($result['ideas'] ?? null) ? $result['ideas'] : [];
            $batchAccepted = [];
            foreach ($ideas as $idea) {
                if (! is_array($idea)) {
                    continue;
                }
                $fp = (string) ($idea['fingerprint'] ?? hash('sha256', (string) ($idea['keyword'] ?? '')));
                if (! $ledger->rememberAcceptedIdentity($fp)) {
                    continue;
                }
                $batchAccepted[] = [
                    'keyword' => (string) ($idea['keyword'] ?? ''),
                    'fingerprint' => $fp,
                ];
            }
            $ledger->markCompleted($chunkId, json_encode($batchAccepted, JSON_THROW_ON_ERROR));
            $accepted = array_merge($accepted, $batchAccepted);

            // Stay on same route while it succeeds.
            if ($batchAccepted === []) {
                $routeIndex++;
            }
        }

        return [
            'accepted' => $accepted,
            'provider_calls' => $providerCalls,
            'payloads' => $payloads,
            'ledger' => $ledger->toArray(),
            'stopped_reason' => $stoppedReason,
        ];
    }

    /**
     * @param  list<array{keyword: string, fingerprint: string}>  $accepted
     */
    private function continuationFromAccepted(array $accepted): string
    {
        if ($accepted === []) {
            return '';
        }
        $ids = array_map(static fn (array $row): string => $row['fingerprint'], $accepted);

        return 'Already accepted fingerprints (do not repeat): '.implode(',', array_slice($ids, -40));
    }
}
