<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Services;

use App\System\Ai\Contracts\SystemAiClient;
use App\System\Ai\Dto\AiExecutionRequest;
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
        $mcpContext = $this->resolver()->resolve($payload);
        $active = $this->sharedPrompt()->resolveActive();
        $finalPrompt = (new SeedingCommentPromptRenderer())->render($active['body'], $mcpContext);

        $aiOutput = null;
        $provider = null;
        $model = null;
        $systemExecutionId = null;

        try {
            $result = $this->systemAiClient()->execute(new AiExecutionRequest(
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

            if ($result->status === 'failed') {
                $message = trim((string) ($result->errorMessage ?? 'Gen comment thất bại.'));
                if ($message === '') {
                    $message = 'Gen comment thất bại.';
                }

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

                throw new RuntimeException($message);
            }

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

            return array_values($normalized);
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
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
