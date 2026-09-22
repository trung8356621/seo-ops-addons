<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\System;

use App\System\Ai\Contracts\AiTextExecutionPort;
use App\System\Capability\SystemCapabilityHandler;
use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Services\PromptExecutionProfileResolver;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultSeedingCommentPromptInstaller;
use Omnichannel\Addons\AiPrompt\Services\PromptRoutingPolicyResolver;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionTransport;
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
 * Model calls go through system_ai_text_port only — interactive transport + routing policy
 * resolved by shared ai-prompt (no Seeding provider/routing code).
 */
final class SeedingCommentGenerateCapabilityHandler implements SystemCapabilityHandler
{
    public const KEY = 'seeding.comment.generate';

    public function __construct(
        private readonly ?SeedingSocialContextResolver $contextResolver = null,
        private readonly ?SeedingSharedCommentPromptResolver $sharedPrompt = null,
        private readonly ?SocialCommentGenerateTask $task = null,
        private readonly ?PromptExecutionProfileResolver $profiles = null,
        private readonly ?PromptRoutingPolicyResolver $routingPolicies = null,
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
        $profiles = $this->profiles ?? new PromptExecutionProfileResolver();
        $routingPolicies = $this->routingPolicies ?? new PromptRoutingPolicyResolver();

        $mcpContext = $resolver->resolve($input);
        $active = $shared->resolveActive();
        $finalPrompt = (new SeedingCommentPromptRenderer())->render($active['body'], $mcpContext);

        $quantity = (int) ($input['quantity'] ?? $input['count'] ?? 3);
        $quantity = max(1, min(12, $quantity));
        $social = trim((string) ($input['social'] ?? $input['platform'] ?? 'threads'));
        if ($social === '') {
            $social = 'threads';
        }

        $idempotencyKey = $this->resolveIdempotencyKey($input);
        if ($idempotencyKey !== null) {
            $cached = $this->findIdempotentSuccess($idempotencyKey, $quantity);
            if ($cached !== null) {
                return $cached;
            }
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

        $seoPrompt = $this->loadPrompt($active['prompt_id']);
        $profile = $profiles->resolve($seoPrompt, self::KEY);
        $routingPolicy = $routingPolicies->resolve($seoPrompt, self::KEY);

        $promptResultId = null;
        $startedAt = now();

        try {
            // External AI call — never wrap in a long DB transaction.
            $generated = $textPort->generate(
                $compiledPrompt,
                self::KEY,
                [
                    'quantity' => $quantity,
                    'count' => $quantity,
                    'max_output' => $maxOutput,
                    'desired_output_tokens' => $maxOutput,
                    'profile' => $profile->value,
                    'execution_profile' => $profile->value,
                    'routing_policy' => $routingPolicy->value,
                    'execution_transport' => AiExecutionTransport::Interactive->value,
                    'prompt_id' => $active['prompt_id'],
                    'prompt' => $seoPrompt,
                    'idempotency_key' => $idempotencyKey,
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
                idempotencyKey: $idempotencyKey,
                comments: $comments,
                generated: $generated,
                profile: $profile->value,
                routingPolicy: $routingPolicy->value,
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
                'execution_transport' => AiExecutionTransport::Interactive->value,
                'routing_policy' => $routingPolicy->value,
                'execution_profile' => $profile->value,
                'idempotency_key' => $idempotencyKey,
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
                idempotencyKey: $idempotencyKey,
                comments: null,
                generated: null,
                profile: $profile->value,
                routingPolicy: $routingPolicy->value,
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

    /**
     * @param  array<string, mixed>  $input
     */
    private function resolveIdempotencyKey(array $input): ?string
    {
        foreach (['idempotency_key', 'client_request_id', 'batch_id', 'seed_batch_id'] as $key) {
            $raw = trim((string) ($input[$key] ?? ''));
            if ($raw !== '') {
                return mb_substr($raw, 0, 128);
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findIdempotentSuccess(string $idempotencyKey, int $quantity): ?array
    {
        try {
            $row = PromptResult::query()
                ->where('canonical_prompt_key', self::KEY)
                ->where('status', 'completed')
                ->where('input_snapshot->idempotency_key', $idempotencyKey)
                ->orderByDesc('id')
                ->first();
            if ($row === null) {
                return null;
            }

            $snapshot = is_array($row->input_snapshot) ? $row->input_snapshot : [];
            $comments = $snapshot['comments'] ?? null;
            if (! is_array($comments) || $comments === []) {
                return null;
            }

            $normalized = [];
            foreach ($comments as $comment) {
                if (is_string($comment) && trim($comment) !== '') {
                    $normalized[] = trim($comment);
                }
            }
            if ($normalized === []) {
                return null;
            }

            return [
                'comments' => array_values($normalized),
                'raw_output' => (string) ($row->output_text ?? ''),
                'compiled_prompt' => (string) ($snapshot['compiled_prompt'] ?? ''),
                'mcp_context' => (string) ($snapshot['variables']['mcp_context'] ?? ''),
                'final_prompt' => (string) ($snapshot['variables']['final_prompt'] ?? ''),
                'provider' => $snapshot['provider'] ?? null,
                'model' => $snapshot['model'] ?? null,
                'physical_route' => $snapshot['physical_route'] ?? null,
                'path' => 'system_ai_text_port_idempotent',
                'seo_runtime_participated' => false,
                'system_ai_capability' => self::KEY,
                'hook_key' => self::KEY,
                'shared_prompt_key' => DefaultSeedingCommentPromptInstaller::HOOK_KEY,
                'prompt_id' => (int) $row->prompt_id,
                'prompt_version_id' => $row->prompt_version_id !== null ? (int) $row->prompt_version_id : null,
                'prompt_result_id' => (int) $row->id,
                'execution_transport' => AiExecutionTransport::Interactive->value,
                'routing_policy' => $snapshot['routing_policy'] ?? null,
                'execution_profile' => $snapshot['execution_profile'] ?? null,
                'idempotency_key' => $idempotencyKey,
                'idempotent_replay' => true,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    private function loadPrompt(int $promptId): ?SeoPrompt
    {
        if ($promptId <= 0) {
            return null;
        }

        try {
            return SeoPrompt::query()->find($promptId);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  list<string>|null  $comments
     * @param  array<string, mixed>|null  $generated
     */
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
        ?string $idempotencyKey,
        ?array $comments,
        ?array $generated,
        string $profile,
        string $routingPolicy,
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
                    'idempotency_key' => $idempotencyKey,
                    'comments' => $comments,
                    'provider' => $generated['provider'] ?? null,
                    'model' => $generated['model'] ?? null,
                    'physical_route' => $generated['physical_route'] ?? null,
                    'execution_transport' => AiExecutionTransport::Interactive->value,
                    'routing_policy' => $routingPolicy,
                    'execution_profile' => $profile,
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
