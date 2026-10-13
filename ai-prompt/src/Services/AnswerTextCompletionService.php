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

    /**
     * @param  array<string, mixed>  $options
     */
    public function complete(int $userId, string $prompt, int $maxOutputTokens, array $options = []): string
    {
        $policyRaw = $options['routing_policy'] ?? null;
        $policy = $policyRaw instanceof \Omnichannel\Addons\AiPrompt\Support\AiRoutingPolicy
            ? $policyRaw
            : \Omnichannel\Addons\AiPrompt\Support\AiRoutingPolicy::tryFromMixed($policyRaw);

        $context = new AiRoutingContext(
            userId: $userId,
            hookKey: self::HOOK_KEY,
            canonicalPromptKey: self::HOOK_KEY,
            promptTaskType: 'agent_runtime_answer',
            modelArea: AiExecutionProfile::TextReasoning->value,
            correlationId: is_string($options['correlation_id'] ?? null) ? $options['correlation_id'] : null,
            routingPolicy: $policy,
            routingPolicyRequested: $policy,
            routingPolicyEffective: $policy,
        );

        $executionOptions = array_merge(['max_output' => max(256, $maxOutputTokens)], $options);
        if ($policy !== null) {
            $executionOptions['routing_policy'] = $policy;
        }

        [$output] = $this->execution->generate(
            $prompt,
            self::HOOK_KEY,
            AiExecutionProfile::TextReasoning,
            $context,
            $executionOptions,
        );

        return is_string($output) ? $output : '';
    }
}
