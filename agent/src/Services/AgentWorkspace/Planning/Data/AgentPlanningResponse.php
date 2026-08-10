<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data;

final readonly class AgentPlanningResponse
{
    public const TYPE_CLARIFICATION = 'clarification';

    public const TYPE_SINGLE_INTENT = 'single_intent';

    public const TYPE_EXECUTION_PLAN = 'execution_plan';

    public const TYPE_ASSISTANT_ANSWER = 'assistant_answer';

    public const TYPE_UNSUPPORTED = 'unsupported';

    /** Additive Phase 5 — proposal draft only; never auto-persist/activate. */
    public const TYPE_AUTOMATION_PROPOSAL = 'automation_proposal';

    /** @var list<string> */
    public const ALLOWED_TYPES = [
        self::TYPE_CLARIFICATION,
        self::TYPE_SINGLE_INTENT,
        self::TYPE_EXECUTION_PLAN,
        self::TYPE_ASSISTANT_ANSWER,
        self::TYPE_UNSUPPORTED,
        self::TYPE_AUTOMATION_PROPOSAL,
    ];

    /**
     * @param  list<string>  $assumptions
     * @param  list<AgentClarifyingQuestion>  $clarifyingQuestions
     * @param  list<array{skill_key: string, name?: string}>  $suggestedSkills
     * @param  list<string>  $warnings
     */
    public function __construct(
        public string $type,
        public float $confidence,
        public string $summary,
        public array $assumptions = [],
        public array $clarifyingQuestions = [],
        public ?AgentProposedIntent $intent = null,
        public ?AgentProposedPlan $plan = null,
        public array $suggestedSkills = [],
        public array $warnings = [],
        public float $adjustedConfidence = 0.0,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $questions = [];
        foreach ($data['clarifying_questions'] ?? [] as $q) {
            if (is_array($q)) {
                $questions[] = AgentClarifyingQuestion::fromArray($q);
            }
        }

        $suggested = [];
        foreach ($data['suggested_skills'] ?? [] as $row) {
            if (is_string($row)) {
                $suggested[] = ['skill_key' => $row];
            } elseif (is_array($row) && isset($row['skill_key'])) {
                $suggested[] = [
                    'skill_key' => (string) $row['skill_key'],
                    'name' => isset($row['name']) ? (string) $row['name'] : null,
                ];
            }
        }

        $intent = null;
        if (isset($data['intent']) && is_array($data['intent'])) {
            $intent = AgentProposedIntent::fromArray($data['intent']);
        }

        $plan = null;
        if (isset($data['plan']) && is_array($data['plan'])) {
            $plan = AgentProposedPlan::fromArray($data['plan']);
        }

        $confidence = (float) ($data['confidence'] ?? 0.0);

        return new self(
            type: (string) ($data['type'] ?? self::TYPE_UNSUPPORTED),
            confidence: $confidence,
            summary: (string) ($data['summary'] ?? ''),
            assumptions: array_values(array_filter(array_map(
                'strval',
                is_array($data['assumptions'] ?? null) ? $data['assumptions'] : [],
            ))),
            clarifyingQuestions: $questions,
            intent: $intent,
            plan: $plan,
            suggestedSkills: $suggested,
            warnings: array_values(array_filter(array_map(
                'strval',
                is_array($data['warnings'] ?? null) ? $data['warnings'] : [],
            ))),
            adjustedConfidence: (float) ($data['adjusted_confidence'] ?? $confidence),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'confidence' => $this->confidence,
            'adjusted_confidence' => $this->adjustedConfidence,
            'summary' => $this->summary,
            'assumptions' => $this->assumptions,
            'clarifying_questions' => array_map(
                static fn (AgentClarifyingQuestion $q): array => $q->toArray(),
                $this->clarifyingQuestions,
            ),
            'intent' => $this->intent?->toArray(),
            'plan' => $this->plan?->toArray(),
            'suggested_skills' => $this->suggestedSkills,
            'warnings' => $this->warnings,
        ];
    }
}
