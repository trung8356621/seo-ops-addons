<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\System;

use App\System\Ai\Contracts\AiTextExecutionPort;
use App\System\Capability\SystemCapabilityHandler;
use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultSeedingCommentPromptInstaller;
use Omnichannel\Addons\Seeding\Services\SeedingSharedCommentPromptResolver;
use Omnichannel\Addons\Seeding\Services\SeedingSocialContextResolver;
use Omnichannel\Addons\Seeding\Support\SeedingCommentPromptRenderer;
use Omnichannel\Addons\Social\Ai\Exceptions\SocialAiValidationException;
use Omnichannel\Addons\Social\Ai\Tasks\SocialCommentGenerateTask;
use RuntimeException;
use Throwable;

/**
 * Domain adapter for seeding.comment.generate via System AI.
 *
 * Prompt body SSOT = shared Prompt (Settings binding). MCP context stays Seeding-owned.
 * Model calls go through system_ai_text_port only — no Social AI / provider fallback.
 */
final class SeedingCommentGenerateCapabilityHandler implements SystemCapabilityHandler
{
    public const KEY = 'seeding.comment.generate';

    public function __construct(
        private readonly ?SeedingSocialContextResolver $contextResolver = null,
        private readonly ?SeedingSharedCommentPromptResolver $sharedPrompt = null,
        private readonly ?SocialCommentGenerateTask $task = null,
    ) {}

    public function handle(array $input, array $context = []): array
    {
        $textPort = $context['system_ai_text_port'] ?? null;
        if (! $textPort instanceof AiTextExecutionPort) {
            throw new RuntimeException(
                'System AI text port is required for seeding.comment.generate (fail closed).',
            );
        }

        $resolver = $this->contextResolver ?? new SeedingSocialContextResolver();
        $shared = $this->sharedPrompt ?? new SeedingSharedCommentPromptResolver();
        $task = $this->task ?? new SocialCommentGenerateTask();

        $mcpContext = $resolver->resolve($input);
        $active = $shared->resolveActive();
        $finalPrompt = (new SeedingCommentPromptRenderer())->render($active['body'], $mcpContext);

        $quantity = (int) ($input['quantity'] ?? $input['count'] ?? 3);
        $quantity = max(1, min(12, $quantity));
        $social = trim((string) ($input['social'] ?? $input['platform'] ?? 'threads'));
        if ($social === '') {
            $social = 'threads';
        }

        try {
            $normalized = $task->normalizeInput([
                'context' => $mcpContext,
                'business_prompt' => $finalPrompt,
                'social' => $social,
                'quantity' => $quantity,
            ]);
        } catch (SocialAiValidationException $e) {
            throw new RuntimeException($e->getMessage(), 0, $e);
        }

        $compiledPrompt = $task->buildCompiledPrompt($normalized);
        $maxOutput = min(2048, max(256, $quantity * 180));

        $promptResultId = null;
        $startedAt = now();

        try {
            $generated = $textPort->generate(
                $compiledPrompt,
                self::KEY,
                [
                    'quantity' => $quantity,
                    'count' => $quantity,
                    'max_output' => $maxOutput,
                    'desired_output_tokens' => $maxOutput,
                    'profile' => 'fast_text',
                ],
            );

            $raw = (string) ($generated['text'] ?? '');
            $comments = $task->validateAndParseOutput($raw, $quantity);

            $promptResultId = $this->persistPromptResult(
                promptId: $active['prompt_id'],
                promptVersionId: $active['prompt_version_id'],
                status: 'completed',
                mcpContext: $mcpContext,
                social: $social,
                quantity: $quantity,
                compiledPrompt: $compiledPrompt,
                outputText: $raw,
                errorMessage: null,
                startedAt: $startedAt,
            );

            return [
                'comments' => $comments,
                'raw_output' => $raw,
                'compiled_prompt' => $compiledPrompt,
                'mcp_context' => $mcpContext,
                'final_prompt' => $finalPrompt,
                'provider' => $generated['provider'] ?? null,
                'model' => $generated['model'] ?? null,
                'physical_route' => $generated['physical_route'] ?? null,
                'path' => 'system_ai_text_port',
                'seo_runtime_participated' => false,
                'system_ai_capability' => self::KEY,
                'hook_key' => self::KEY,
                'shared_prompt_key' => DefaultSeedingCommentPromptInstaller::HOOK_KEY,
                'prompt_id' => $active['prompt_id'],
                'prompt_version_id' => $active['prompt_version_id'],
                'prompt_result_id' => $promptResultId,
            ];
        } catch (Throwable $e) {
            $this->persistPromptResult(
                promptId: $active['prompt_id'],
                promptVersionId: $active['prompt_version_id'],
                status: 'failed',
                mcpContext: $mcpContext,
                social: $social,
                quantity: $quantity,
                compiledPrompt: $compiledPrompt,
                outputText: null,
                errorMessage: $e->getMessage() !== '' ? $e->getMessage() : 'Gen comment thất bại.',
                startedAt: $startedAt,
            );

            if ($e instanceof SocialAiValidationException) {
                throw new RuntimeException($e->getMessage(), 0, $e);
            }

            throw $e instanceof RuntimeException
                ? $e
                : new RuntimeException(
                    $e->getMessage() !== '' ? $e->getMessage() : 'Gen comment thất bại.',
                    0,
                    $e,
                );
        }
    }

    private function persistPromptResult(
        int $promptId,
        ?int $promptVersionId,
        string $status,
        string $mcpContext,
        string $social,
        int $quantity,
        string $compiledPrompt,
        ?string $outputText,
        ?string $errorMessage,
        mixed $startedAt,
    ): ?int {
        try {
            $userId = 0;
            if (function_exists('auth')) {
                try {
                    $id = auth()->id();
                    if ($id !== null && (int) $id > 0) {
                        $userId = (int) $id;
                    }
                } catch (Throwable) {
                    $userId = 0;
                }
            }

            $row = PromptResult::query()->create([
                'prompt_id' => $promptId,
                'prompt_version_id' => $promptVersionId,
                'canonical_prompt_key' => self::KEY,
                'stage' => 'seeding_comment_generate',
                'user_id' => $userId,
                'site_id' => 0,
                'status' => $status,
                'output_text' => $outputText,
                'error_message' => $errorMessage,
                'input_snapshot' => [
                    'hook_key' => self::KEY,
                    'variables' => [
                        'mcp_context' => $mcpContext,
                        'social' => $social,
                        'quantity' => (string) $quantity,
                    ],
                    'compiled_prompt' => $compiledPrompt,
                    'retain_compiled_prompt' => true,
                    'stage' => 'seeding_comment_generate',
                ],
                'started_at' => $startedAt,
                'finished_at' => now(),
            ]);

            return (int) $row->id;
        } catch (Throwable) {
            return null;
        }
    }
}
