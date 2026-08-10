<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos;

/**
 * Structured intent from AgentIntentRouter — never auto-executes writes.
 *
 * @phpstan-type ExtractedInputs array<string, mixed>
 */
final class AgentIntentResolution
{
    public const SOURCE_SLASH = 'slash';

    public const SOURCE_ALIAS = 'alias';

    public const SOURCE_TEMPLATE = 'template';

    public const SOURCE_DETERMINISTIC = 'deterministic';

    public const SOURCE_AI = 'ai';

    public const SOURCE_ASSISTANT = 'assistant';

    public const SOURCE_LOW_CONFIDENCE = 'low_confidence';

    public const SOURCE_MULTI = 'multi_intent';

    /**
     * @param  ExtractedInputs  $extractedInputs
     * @param  list<string>  $missingFields
     * @param  list<string>  $candidateSkillKeys
     * @param  list<array{skill_key: string, title: string}>|null  $planSteps
     */
    public function __construct(
        public readonly ?string $skillKey,
        public readonly float $confidence,
        public readonly string $source,
        public readonly array $extractedInputs = [],
        public readonly array $missingFields = [],
        public readonly array $candidateSkillKeys = [],
        public readonly ?array $planSteps = null,
        public readonly bool $requiresUserChoice = false,
        public readonly string $message = '',
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'skill_key' => $this->skillKey,
            'confidence' => $this->confidence,
            'source' => $this->source,
            'extracted_inputs' => $this->extractedInputs,
            'missing_fields' => $this->missingFields,
            'candidate_skill_keys' => $this->candidateSkillKeys,
            'plan_steps' => $this->planSteps,
            'requires_user_choice' => $this->requiresUserChoice,
            'message' => $this->message,
        ];
    }
}
