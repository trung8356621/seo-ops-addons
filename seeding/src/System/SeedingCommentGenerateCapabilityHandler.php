<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\System;

use App\System\Ai\Contracts\AiTextExecutionPort;
use App\System\Capability\SystemCapabilityHandler;
use Omnichannel\Addons\Seeding\Services\SeedingCommentPromptService;
use Omnichannel\Addons\Seeding\Services\SeedingSocialContextResolver;
use Omnichannel\Addons\Social\Ai\Exceptions\SocialAiValidationException;
use Omnichannel\Addons\Social\Ai\Tasks\SocialCommentGenerateTask;
use RuntimeException;

/**
 * Domain adapter for seeding.comment.generate via System AI.
 *
 * Owns Seeding prompt assembly (MCP + Manager prompt). Model routing / provider
 * calls go through system_ai_text_port only — no direct provider / Social AI fallback.
 * System core must not import this class by namespace from outside registration.
 */
final class SeedingCommentGenerateCapabilityHandler implements SystemCapabilityHandler
{
    public const KEY = 'seeding.comment.generate';

    public function __construct(
        private readonly ?SeedingSocialContextResolver $contextResolver = null,
        private readonly ?SeedingCommentPromptService $promptService = null,
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
        $prompts = $this->promptService ?? new SeedingCommentPromptService();
        $task = $this->task ?? new SocialCommentGenerateTask();

        $mcpContext = $resolver->resolve($input);
        $managerPrompt = $prompts->getPromptBody();
        $finalPrompt = $prompts->renderFinalPrompt($managerPrompt, $mcpContext);

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

        $generated = $textPort->generate(
            $compiledPrompt,
            $task->taskKey(),
            [
                'quantity' => $quantity,
                'count' => $quantity,
                'max_output' => $maxOutput,
                'desired_output_tokens' => $maxOutput,
                'profile' => 'fast_text',
            ],
        );

        $raw = (string) ($generated['text'] ?? '');

        try {
            $comments = $task->validateAndParseOutput($raw, $quantity);
        } catch (SocialAiValidationException $e) {
            throw new RuntimeException($e->getMessage(), 0, $e);
        }

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
            'hook_key' => $task->taskKey(),
        ];
    }
}
