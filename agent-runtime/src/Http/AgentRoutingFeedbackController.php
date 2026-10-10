<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentRun;
use Omnichannel\Addons\AgentRuntime\Routing\SemanticRoutingConfig;

final class AgentRoutingFeedbackController
{
    public function store(Request $request, SemanticRoutingConfig $routing): JsonResponse
    {
        $userId = (int) ($request->user()?->id ?? 0);
        $items = $request->input('items');
        if ($userId <= 0 || ! is_array($items) || $items === [] || count($items) > 100) {
            return new JsonResponse(['message' => 'Invalid feedback batch.'], 422);
        }

        $baseUrl = rtrim((string) config('agent-runtime.feedback.url'), '/');
        $token = (string) config('agent-runtime.feedback.internal_token');
        if ($baseUrl === '' || $token === '') {
            return new JsonResponse(['message' => 'Feedback service is unavailable.'], 503);
        }

        $outgoing = [];
        $runByReview = [];
        foreach ($items as $item) {
            $runUlid = is_array($item) ? trim((string) ($item['run_ulid'] ?? '')) : '';
            $rating = is_array($item) ? ($item['rating'] ?? null) : null;
            if ($runUlid === '' || ! is_bool($rating)) {
                continue;
            }
            $run = AgentRun::query()
                ->where('ulid', $runUlid)
                ->where('user_id', $userId)
                ->where('status', 'done')
                ->first();
            $execution = is_array($run?->retrieval_summary)
                ? ($run->retrieval_summary['model_diagnostics']['execution'] ?? null)
                : null;
            if (! $run instanceof AgentRun || ! is_array($execution)) {
                continue;
            }
            $operation = $execution['operation'] ?? null;
            $groupId = $execution['routing_group_id'] ?? null;
            $clientId = (string) config('agent-runtime.feedback.installation_id');
            $reviewHash = hash('sha256', $clientId.'|'.$userId.'|'.$runUlid);
            $reviewId = substr($reviewHash, 0, 8).'-'.substr($reviewHash, 8, 4).'-4'.substr($reviewHash, 13, 3).'-a'.substr($reviewHash, 17, 3).'-'.substr($reviewHash, 20, 12);
            $outgoing[] = [
                'review_id' => $reviewId,
                'client_id' => $clientId,
                'agent_app' => (string) $run->app_key,
                'service_id' => 'seo-ops',
                'module_id' => $execution['module'] ?? null,
                'operation_id' => $operation,
                'routing_group_id' => is_string($groupId) ? $groupId : null,
                'routing_version' => (string) ($routing->document()['revision'] ?? ''),
                'routing_outcome' => (string) ($execution['outcome'] ?? 'unknown'),
                'rating' => $rating,
            ];
            $runByReview[$reviewId] = $runUlid;
        }
        if ($outgoing === []) {
            return new JsonResponse(['acknowledged_run_ulids' => []]);
        }

        try {
            $response = Http::acceptJson()->asJson()
                ->withHeaders(['X-Internal-Token' => $token])
                ->timeout((int) config('agent-runtime.feedback.timeout', 10))
                ->post($baseUrl.'/v1/internal/agent-routing-feedback/batch', ['items' => $outgoing]);
        } catch (\Throwable) {
            return new JsonResponse(['message' => 'Feedback service is unavailable.'], 503);
        }
        if (! $response->successful()) {
            return new JsonResponse(['message' => 'Feedback service rejected the batch.'], 503);
        }
        $acknowledgedIds = array_map('strval', (array) ($response->json('acknowledged') ?? []));
        $acknowledged = array_values(array_filter(array_map(
            static fn (string $id): string => $runByReview[$id] ?? '',
            $acknowledgedIds,
        )));

        return new JsonResponse(['acknowledged_run_ulids' => $acknowledged]);
    }
}
