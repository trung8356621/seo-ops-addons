<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Decision;

final readonly class RetrievalDecision
{
    /**
     * @param  array<string, float>  $needs
     * @param  array<string, string>  $parameters
     */
    public function __construct(
        public string $intent,
        public array $needs,
        public array $parameters,
        public bool $requiresParameterExtraction,
        public bool $requiresUserConfirmation,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'intent' => $this->intent,
            'needs' => $this->needs,
            'parameters' => $this->parameters,
            'requires_parameter_extraction' => $this->requiresParameterExtraction,
            'requires_user_confirmation' => $this->requiresUserConfirmation,
        ];
    }
}
