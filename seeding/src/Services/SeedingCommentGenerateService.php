<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Services;

use App\System\Ai\Contracts\SystemAiClient;
use App\System\Ai\Dto\AiExecutionRequest;
use Omnichannel\Addons\AiPrompt\Support\AiLatencyDiag;
use Omnichannel\Addons\Seeding\Support\SeedingCommentPromptRenderer;
use Omnichannel\Addons\Seeding\System\SeedingCommentGenerateCapabilityHandler;
use RuntimeException;
use Throwable;

/**
 * Gen Comment via System AI capability boundary.
 * API response still uses { comments: string[] } for contract stability;
 * Flexible Seeding JS adapter maps that to seed_outputs.
 *
 * Flow: payload → SystemAiClient → seeding.comment.generate → history snapshot (max 20).
 * Prompt body SSOT = shared Prompt system (Settings binding).
 * Service pre-resolves MCP/final prompt solely for Seeding debug history snapshots.
 */
final class SeedingCommentGenerateService
{
    /** @deprecated Use SeedingCommentGenerateCapabilityHandler::KEY */
    public const HOOK_KEY = 'seeding.comment_generate';

    public const CAPABILITY = SeedingCommentGenerateCapabilityHandler::KEY;

    public function __construct(
        private readonly ?SystemAiClient $systemAi = null,
        private readonly ?SeedingSocialContextResolver $contextResolver = null,
        private readonly ?SeedingSharedCommentPromptResolver $sharedPrompt = null,
        private readonly ?SeedingCommentGenerateHistoryService $history = null,
    ) {}

    private function resolver(): SeedingSocialContextResolver
    {
        return $this->contextResolver ?? new SeedingSocialContextResolver();
    }

    private function sharedPrompt(): SeedingSharedCommentPromptResolver
    {
        return $this->sharedPrompt ?? new SeedingSharedCommentPromptResolver();
    }

    private function historyStore(): SeedingCommentGenerateHistoryService
    {
        return $this->history ?? new SeedingCommentGenerateHistoryService();
    }

    private function systemAiClient(): SystemAiClient
    {
        if ($this->systemAi instanceof SystemAiClient) {
            return $this->systemAi;
        }

        if (function_exists('app')) {
            try {
                if (app()->bound(SystemAiClient::class)) {
                    return app(SystemAiClient::class);
                }
            } catch (Throwable) {
            }
        }

        throw new RuntimeException('System AI is unavailable for seeding.comment.generate.');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    public function generateFromPayload(array $payload): array
    {
        $totalStarted = hrtime(true);

        $social = trim((string) ($payload['social'] ?? $payload['platform'] ?? 'threads'));
        if ($social === '') {
            $social = 'threads';
        }

        $quantity = (int) ($payload['quantity'] ?? $payload['count'] ?? 3);
        $quantity = max(1, min(12, $quantity));

        $topicId = null;
        if (isset($payload['topic_id']) && is_numeric($payload['topic_id'])) {
            $topicId = (int) $payload['topic_id'];
            if ($topicId <= 0) {
                $topicId = null;
            }
        }

        // History snapshot fields — assembled before System call so failures still retain them.
        $mcpContext = AiLatencyDiag::time('service_context_prompt_prep_ms', function () use ($payload): string {
            return $this->resolver()->resolve($payload);
        });
        $active = AiLatencyDiag::time('service_shared_prompt_resolve_ms', function (): array {
            return $this->sharedPrompt()->resolveActive();
        });
        $finalPrompt = AiLatencyDiag::time('service_prompt_render_ms', function () use ($active, $mcpContext): string {
            return (new SeedingCommentPromptRenderer())->render($active['body'], $mcpContext);
        });
        if (AiLatencyDiag::isEnabled()) {
            AiLatencyDiag::setMs(
                'B_service_prep_ms',
                (AiLatencyDiag::report()['spans_ms']['service_context_prompt_prep_ms'] ?? 0)
                + (AiLatencyDiag::report()['spans_ms']['service_shared_prompt_resolve_ms'] ?? 0)
                + (AiLatencyDiag::report()['spans_ms']['service_prompt_render_ms'] ?? 0),
            );
            AiLatencyDiag::setMs(
                'C_service_context_prompt_prep_ms',
                (AiLatencyDiag::report()['spans_ms']['service_context_prompt_prep_ms'] ?? 0)
                + (AiLatencyDiag::report()['spans_ms']['service_shared_prompt_resolve_ms'] ?? 0)
                + (AiLatencyDiag::report()['spans_ms']['service_prompt_render_ms'] ?? 0),
            );
        }

        $aiOutput = null;
        $provider = null;
        $model = null;
        $systemExecutionId = null;

        try {
            $result = AiLatencyDiag::time('system_ai_execute_ms', function () use ($payload, $topicId, $social, $quantity) {
                return $this->systemAiClient()->execute(new AiExecutionRequest(
                    capability: self::CAPABILITY,
                    input: $payload,
                    context: [
                        'allow_domain_side_effects' => true,
                        'stage' => 'seeding_comment_generate',
                    ],
                    requirements: [
                        'structured_output' => true,
                    ],
                    correlation: [
                        'stage' => 'seeding_comment_generate',
                        'canonical_prompt_key' => self::CAPABILITY,
                        'topic_id' => $topicId,
                        'social' => $social,
                        'quantity' => $quantity,
                    ],
                ));
            });

            $systemExecutionId = $result->id;
            $output = is_array($result->output) ? $result->output : [];

            if (isset($output['mcp_context']) && is_string($output['mcp_context']) && $output['mcp_context'] !== '') {
                $mcpContext = $output['mcp_context'];
            }
            if (isset($output['final_prompt']) && is_string($output['final_prompt']) && $output['final_prompt'] !== '') {
                $finalPrompt = $output['final_prompt'];
            }

            $aiOutput = isset($output['raw_output']) && is_string($output['raw_output'])
                ? $output['raw_output']
                : null;
            $provider = isset($output['provider']) && is_string($output['provider'])
                ? $output['provider']
                : null;
            $model = isset($output['model']) && is_string($output['model'])
                ? $output['model']
                : null;

            if (AiLatencyDiag::isEnabled()) {
                AiLatencyDiag::setMeta('hook_key', $output['hook_key'] ?? self::CAPABILITY);
                AiLatencyDiag::setMeta('execution_profile', $output['execution_profile'] ?? null);
                AiLatencyDiag::setMeta('routing_policy_requested', $output['routing_policy'] ?? null);
                AiLatencyDiag::setMeta('routing_policy_effective', $output['routing_policy'] ?? null);
                AiLatencyDiag::setMeta('provider', $provider);
                AiLatencyDiag::setMeta('model', $model);
                AiLatencyDiag::setMeta('physical_route', $output['physical_route'] ?? null);
                AiLatencyDiag::setMeta('prompt_result_id', $output['prompt_result_id'] ?? null);
                AiLatencyDiag::setMeta('system_execution_id', $systemExecutionId);
                if (isset($output['usage']) && is_array($output['usage'])) {
                    AiLatencyDiag::setMeta('usage', $output['usage']);
                }
                if (isset($output['_latency_diag']) && is_array($output['_latency_diag'])) {
                    AiLatencyDiag::setMeta('handler_diag', $output['_latency_diag']);
                }
            }

            if ($result->status === 'failed') {
                $message = trim((string) ($result->errorMessage ?? 'Gen comment thất bại.'));
                if ($message === '') {
                    $message = 'Gen comment thất bại.';
                }

                AiLatencyDiag::time('G_seeding_history_persist_ms', function () use (
                    $topicId, $social, $quantity, $mcpContext, $finalPrompt, $aiOutput, $provider, $model, $message, $systemExecutionId,
                ): void {
                    $this->recordHistory(
                        topicId: $topicId,
                        social: $social,
                        quantity: $quantity,
                        mcpContext: $mcpContext,
                        finalPrompt: $finalPrompt,
                        aiOutput: $aiOutput,
                        provider: $provider,
                        model: $model,
                        status: SeedingCommentGenerateHistoryService::STATUS_FAILED,
                        errorMessage: $this->appendSystemExecutionRef($message, $systemExecutionId),
                    );
                });

                throw new RuntimeException($message);
            }

            $comments = $output['comments'] ?? null;
            $normalized = [];
            if (is_array($comments)) {
                foreach ($comments as $comment) {
                    if (! is_string($comment)) {
                        continue;
                    }
                    $trimmed = trim($comment);
                    if ($trimmed !== '') {
                        $normalized[] = $trimmed;
                    }
                }
            }

            if ($normalized === []) {
                $message = 'Gen comment thất bại: empty System AI output.';
                AiLatencyDiag::time('G_seeding_history_persist_ms', function () use (
                    $topicId, $social, $quantity, $mcpContext, $finalPrompt, $aiOutput, $provider, $model, $message, $systemExecutionId,
                ): void {
                    $this->recordHistory(
                        topicId: $topicId,
                        social: $social,
                        quantity: $quantity,
                        mcpContext: $mcpContext,
                        finalPrompt: $finalPrompt,
                        aiOutput: $aiOutput,
                        provider: $provider,
                        model: $model,
                        status: SeedingCommentGenerateHistoryService::STATUS_FAILED,
                        errorMessage: $this->appendSystemExecutionRef($message, $systemExecutionId),
                    );
                });

                throw new RuntimeException($message);
            }

            AiLatencyDiag::time('G_seeding_history_persist_ms', function () use (
                $topicId, $social, $quantity, $mcpContext, $finalPrompt, $aiOutput, $provider, $model,
            ): void {
                $this->recordHistory(
                    topicId: $topicId,
                    social: $social,
                    quantity: $quantity,
                    mcpContext: $mcpContext,
                    finalPrompt: $finalPrompt,
                    aiOutput: $aiOutput,
                    provider: $provider,
                    model: $model,
                    status: SeedingCommentGenerateHistoryService::STATUS_SUCCESS,
                    errorMessage: null,
                );
            });

            AiLatencyDiag::setMs('H_backend_total_ms', (hrtime(true) - $totalStarted) / 1_000_000);

            return array_values($normalized);
        } catch (RuntimeException $e) {
            AiLatencyDiag::setMs('H_backend_total_ms', (hrtime(true) - $totalStarted) / 1_000_000);
            throw $e;
        } catch (Throwable $e) {
            AiLatencyDiag::time('G_seeding_history_persist_ms', function () use (
                $topicId, $social, $quantity, $mcpContext, $finalPrompt, $aiOutput, $provider, $model, $e, $systemExecutionId,
            ): void {
                $this->recordHistory(
                    topicId: $topicId,
                    social: $social,
                    quantity: $quantity,
                    mcpContext: $mcpContext,
                    finalPrompt: $finalPrompt,
                    aiOutput: is_string($aiOutput) ? $aiOutput : null,
                    provider: is_string($provider) ? $provider : null,
                    model: is_string($model) ? $model : null,
                    status: SeedingCommentGenerateHistoryService::STATUS_FAILED,
                    errorMessage: $this->appendSystemExecutionRef(
                        $e->getMessage() !== '' ? $e->getMessage() : 'Gen comment thất bại.',
                        $systemExecutionId,
                    ),
                );
            });

            AiLatencyDiag::setMs('H_backend_total_ms', (hrtime(true) - $totalStarted) / 1_000_000);

            throw new RuntimeException(
                $e->getMessage() !== '' ? $e->getMessage() : 'Gen comment thất bại.',
                0,
                $e,
            );
        }
    }

    /**
     * Legacy signature backward-compatibility.
     *
     * @return list<string>
     */
    public function generate(string $fullText, ?string $socialUrl = null, ?string $platform = null, int $count = 5): array
    {
        $count = max(1, min(12, $count));
        $fullText = trim($fullText);
        $socialUrl = $socialUrl ? trim($socialUrl) : null;

        if ($fullText === '' && ($socialUrl === null || $socialUrl === '')) {
            throw new RuntimeException('Thiếu nội dung gốc để gen nội dung seeding.');
        }

        return $this->generateFromPayload([
            'content' => $fullText,
            'social_url' => $socialUrl,
            'platform' => $platform,
            'count' => $count,
        ]);
    }

    private function recordHistory(
        ?int $topicId,
        string $social,
        int $quantity,
        string $mcpContext,
        string $finalPrompt,
        ?string $aiOutput,
        ?string $provider,
        ?string $model,
        string $status,
        ?string $errorMessage,
    ): void {
        $this->historyStore()->record([
            'topic_id' => $topicId,
            'social' => $social,
            'quantity' => $quantity,
            'mcp_context' => $mcpContext,
            'final_prompt' => $finalPrompt,
            'ai_output' => $aiOutput,
            'provider' => $provider,
            'model' => $model,
            'status' => $status,
            'error_message' => $errorMessage,
        ]);
    }

    private function appendSystemExecutionRef(string $message, ?string $systemExecutionId): string
    {
        if ($systemExecutionId === null || $systemExecutionId === '') {
            return $message;
        }

        if (str_contains($message, 'system_ai_execution_id=')) {
            return $message;
        }

        return $message.' [system_ai_execution_id='.$systemExecutionId.']';
    }
}
