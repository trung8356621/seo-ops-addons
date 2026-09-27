<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\Contracts\AnswerTextCompletion;
use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;

/**
 * Answer-stage prose through Reasoning Text routing.
 * Hook key is runtime-only and is not a Prompt Admin record.
 */
final class AnswerTextCompletionService implements AnswerTextCompletion
{
    public const HOOK_KEY = 'agent.runtime.answer';

    public function __construct(
        private readonly CanonicalAiTextExecutionService $execution,
    ) {}

    public function complete(int $userId, string $prompt, int $maxOutputTokens): string
    {
        $context = new AiRoutingContext(
            userId: $userId,
            hookKey: self::HOOK_KEY,
            canonicalPromptKey: self::HOOK_KEY,
            promptTaskType: 'agent_runtime_answer',
            modelArea: AiExecutionProfile::TextReasoning->value,
        );

        [$output] = $this->execution->generate(
            $prompt,
            self::HOOK_KEY,
            AiExecutionProfile::TextReasoning,
            $context,
            ['max_output' => max(256, $maxOutputTokens)],
        );

        return is_string($output) ? $output : '';
    }
}
