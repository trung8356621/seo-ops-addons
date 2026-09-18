<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\System;

use App\System\Ai\Contracts\AiTextExecutionPort;
use App\System\Capability\SystemCapabilityHandler;
use Omnichannel\Addons\Seeding\Services\SeedingCommentGenerateHistoryService;
use Omnichannel\Addons\Seeding\Services\SeedingCommentPromptService;
use Omnichannel\Addons\Seeding\Services\SeedingSocialContextResolver;
use Omnichannel\Addons\Social\Ai\Services\SocialAiExecutionService;
use Omnichannel\Addons\Social\Ai\Tasks\SocialCommentGenerateTask;
use RuntimeException;

/**
 * Non-SEO proof capability: seeding.comment.generate via System AI boundary.
 * Does not import SEO domain. Uses Canonical port (AiTextExecutionPort) for model calls.
 */
final class SeedingCommentGenerateCapabilityHandler implements SystemCapabilityHandler
{
    public const KEY = 'seeding.comment.generate';

    public function __construct(
        private readonly ?SeedingSocialContextResolver $contextResolver = null,
        private readonly ?SeedingCommentPromptService $promptService = null,
        private readonly ?SeedingCommentGenerateHistoryService $history = null,
        private readonly ?SocialAiExecutionService $socialAi = null,
    ) {}

    public function handle(array $input, array $context = []): array
    {
        $allowSideEffects = (bool) ($context['allow_domain_side_effects'] ?? true);
        $textPort = $context['system_ai_text_port'] ?? null;

        $resolver = $this->contextResolver ?? new SeedingSocialContextResolver();
        $prompts = $this->promptService ?? new SeedingCommentPromptService();

        $mcpContext = $resolver->resolve($input);
        $managerPrompt = $prompts->getPromptBody();
        $finalPrompt = $prompts->renderFinalPrompt($managerPrompt, $mcpContext);

        $quantity = (int) ($input['quantity'] ?? $input['count'] ?? 3);
        $quantity = max(1, min(12, $quantity));

        if ($textPort instanceof AiTextExecutionPort) {
            $generated = $textPort->generate(
                $finalPrompt,
                SocialCommentGenerateTask::TASK_KEY,
                [
                    'quantity' => $quantity,
                    'count' => $quantity,
                    'max_output' => min(2048, max(256, $quantity * 180)),
                    'desired_output_tokens' => min(2048, max(256, $quantity * 180)),
                    'profile' => 'fast_text',
                ],
            );
            $raw = (string) ($generated['text'] ?? '');
            $comments = $this->parseComments($raw, $quantity);
            $result = [
                'comments' => $comments,
                'raw_output' => $raw,
                'compiled_prompt' => $finalPrompt,
                'provider' => $generated['provider'] ?? null,
                'model' => $generated['model'] ?? null,
                'physical_route' => $generated['physical_route'] ?? null,
                'path' => 'system_ai_text_port',
            ];
        } elseif ($this->socialAi instanceof SocialAiExecutionService || function_exists('app')) {
            $social = $this->socialAi ?? app(SocialAiExecutionService::class);
            $detailed = $social->generateCommentsDetailed([
                'prompt' => $finalPrompt,
                'quantity' => $quantity,
                'social' => $input['social'] ?? $input['platform'] ?? 'threads',
            ]);
            $result = [
                'comments' => $detailed['comments'],
                'raw_output' => $detailed['raw_output'],
                'compiled_prompt' => $detailed['compiled_prompt'] ?? $finalPrompt,
                'provider' => $detailed['provider'] ?? null,
                'model' => $detailed['model'] ?? null,
                'path' => 'social_ai_fallback',
            ];
        } else {
            throw new RuntimeException('No AI execution port available for seeding.comment.generate.');
        }

        if ($allowSideEffects && $this->history instanceof SeedingCommentGenerateHistoryService) {
            // History write stays seeding-owned; skipped in shadow mode.
        }

        $result['seo_runtime_participated'] = false;

        return $result;
    }

    /**
     * @return list<string>
     */
    private function parseComments(string $raw, int $quantity): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($raw)) ?: [];
        $comments = [];
        foreach ($lines as $line) {
            $line = trim($line);
            $line = preg_replace('/^\s*[\-\*\d]+[\.\)]\s*/', '', $line) ?? $line;
            if ($line === '') {
                continue;
            }
            $comments[] = $line;
            if (count($comments) >= $quantity) {
                break;
            }
        }

        if ($comments === [] && trim($raw) !== '') {
            $comments[] = trim($raw);
        }

        return $comments;
    }
}
