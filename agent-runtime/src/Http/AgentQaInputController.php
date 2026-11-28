<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Omnichannel\Addons\AgentRuntime\Domain\AgentProjectScope;
use Omnichannel\Addons\AgentRuntime\Persistence\AgentThreadRepository;
use Omnichannel\Addons\AgentRuntime\Persistence\AgentTurnPersistence;
use Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentApp;
use Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentMessage;
use Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentRun;
use Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentThread;
use Omnichannel\Addons\AgentRuntime\Projects\SiteDirectory;
use Omnichannel\Addons\AgentRuntime\Response\AgentPublicPayloadSanitizer;
use Omnichannel\Addons\AgentRuntime\Response\AgentResponse;
use Omnichannel\Addons\AgentRuntime\StructuredRag\StructuredRagPipeline;
use Omnichannel\Addons\AgentRuntime\StructuredRag\DatasetPackageWriter;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;

final class AgentQaInputController
{
    public function __invoke(
        Request $request,
        StructuredRagPipeline $pipeline,
        SiteDirectory $sites,
        AgentThreadRepository $threads,
        AgentTurnPersistence $persistence,
        DatasetPackageWriter $datasets,
    ): JsonResponse {
        $context = $request->attributes->get(AgentQaContext::REQUEST_ATTRIBUTE);
        if (! $context instanceof AgentQaContext) {
            return new JsonResponse(['message' => 'Unauthenticated.'], 401);
        }

        $payload = $request->all();
        $message = trim((string) ($payload['message'] ?? ''));
        $siteId = is_numeric($payload['site_id'] ?? null) ? (int) $payload['site_id'] : 0;
        if ($message === '') {
            return new JsonResponse(['message' => 'Message is required.'], 422);
        }
        if ($siteId <= 0) {
            return new JsonResponse(['message' => 'Website scope (site_id) is required.', 'error_code' => 'site_scope_required'], 422);
        }
        if (! $context->allowsSiteId($siteId) || ! $sites->isSiteVisible($siteId, $context->userId)) {
            return new JsonResponse(['message' => 'Site is outside allowed QA scope or inaccessible.', 'error_code' => 'site_scope_forbidden'], 403);
        }
        $user = $request->user();
        if ($user instanceof \App\Models\User && SeoAccessControl::roleForUser($user) === SeoAccessControl::ROLE_CONTENT_MANAGER) {
            return new JsonResponse(['message' => 'Mapped QA user role is restricted from site-scoped QA.', 'error_code' => 'site_scope_forbidden'], 403);
        }

        $existingThread = null;
        $threadUlid = trim((string) ($payload['thread_ulid'] ?? ''));
        if ($threadUlid !== '') {
            $existingThread = $threads->findForPrincipal($threadUlid, 'user', (string) $context->userId);
            if (! $existingThread instanceof AgentThread) {
                return new JsonResponse(['message' => 'Thread not found.'], 404);
            }
            if ($existingThread->scope_ref !== 'site:'.$siteId) {
                return new JsonResponse(['message' => 'Thread scope does not match requested website scope.', 'error_code' => 'thread_scope_mismatch'], 422);
            }
        }

        $app = AgentApp::findByKey('seo-ops');
        if (! $app instanceof AgentApp) {
            return new JsonResponse(['message' => 'Agent app not found.'], 422);
        }

        $scope = AgentProjectScope::site($siteId);
        $ownerId = ($user !== null && method_exists($user, 'accountOwnerId') ? $user->accountOwnerId() : null) ?? $context->userId;
        $thread = $existingThread ?? $threads->createThread(
            $app,
            'user',
            (string) $context->userId,
            $context->userId,
            (int) $ownerId,
            'site',
            'site:'.$siteId,
            mb_substr(preg_replace('/\s+/', ' ', $message) ?: $message, 0, 80),
        );
        $userMessage = $persistence->persistUserMessage($thread, $message);
        $run = $persistence->startRun($thread, $userMessage, $app->app_key, 'site', 'site:'.$siteId, $context->userId, null);

        $history = is_array($payload['history'] ?? null)
            ? $this->safeHistory($payload['history'])
            : $this->historyBefore((int) $thread->id, (int) $userMessage->position);

        try {
            $summary = is_array($run->retrieval_summary) ? $run->retrieval_summary : [];
            $summary['qa_api'] = true;
            $summary['input_only'] = true;
            $summary['routing_policy'] = 'none';
            $run->update(['retrieval_summary' => $summary]);

            $result = $pipeline->prepare($scope, $message, $history);
            $resultData = $result->toArray();
            $datasetManifest = null;
            if (($result->understanding->parameters['dataset_export'] ?? false) === true) {
                try {
                    $datasetManifest = $datasets->writeArticleInventory($siteId, $result->understanding->parameters);
                } catch (\Throwable) {
                    $datasetManifest = ['status' => 'unavailable', 'reason' => 'dataset_packaging_failed'];
                }
            }
            $diagnostics = [
                'structured_rag' => $resultData,
                'input_only' => true,
                'history_source' => array_key_exists('history', $payload) ? 'qa_supplied_unverified' : 'persisted_thread',
            ];
            $persistence->storeModelDiagnostics($run, $diagnostics);

            $response = $result->directResponse ?? new AgentResponse(
                $result->status === 'model_input_ready' ? 'Model input is ready for QA.' : 'No model input was created.',
                [],
                [],
                $result->context?->bundle->toArray()['sources'] ?? [],
            );
            $assistant = $persistence->completeRun($run, $response, ['answer_model' => 'not_called_input_only']);
            $threads->touchLastMessage($thread);

            $public = (new AgentPublicPayloadSanitizer())->sanitize([
                ...$resultData,
                'runtime_metrics' => [
                    'php_peak_memory_bytes' => memory_get_peak_usage(true),
                ],
                'original_user_message' => $message,
                'resolved_scope' => $scope->toArray(),
                'thread_ulid' => $thread->ulid,
                'run_ulid' => $run->ulid,
                'user_message_id' => $run->user_message_id,
                'assistant_message_id' => $assistant->id,
                'history_source' => $diagnostics['history_source'],
                'dataset_manifest' => $datasetManifest,
            ]);

            return new JsonResponse(['data' => $public]);
        } catch (\Throwable $e) {
            $persistence->failRun($run, 'input_only_failed', $e->getMessage());
            throw $e;
        }
    }

    /** @param array<mixed> $history @return list<array{role: string, content: string}> */
    private function safeHistory(array $history): array
    {
        $safe = [];
        foreach (array_slice($history, -6) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $role = in_array(($item['role'] ?? ''), ['user', 'assistant'], true) ? (string) $item['role'] : 'user';
            $content = trim((string) ($item['content'] ?? ''));
            if ($content !== '') {
                $safe[] = ['role' => $role, 'content' => mb_substr($content, 0, 1000)];
            }
        }

        return $safe;
    }

    /** @return list<array{role: string, content: string}> */
    private function historyBefore(int $threadId, int $position): array
    {
        $history = [];
        $messages = AgentMessage::where('thread_id', $threadId)->where('position', '<', $position)->orderBy('position')->take(12)->get();
        foreach ($messages as $message) {
            if (in_array($message->role, ['user', 'assistant'], true)) {
                $history[] = ['role' => $message->role, 'content' => $message->content];
            }
        }

        return array_slice($history, -6);
    }
}
