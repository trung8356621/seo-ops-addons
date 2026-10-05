<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Decision;

final readonly class RetrievalDecision
{
    /**
     * @param  list<string>  $capabilities
     * @param  list<string>  $modules Internal retrieval resources derived from capabilities.
     * @param  array<string, scalar|null>  $parameters
     */
    public function __construct(
        public bool $isInScope,
        public string $intent,
        public ?string $primaryCapability,
        public array $capabilities,
        public ?string $primaryModule,
        public array $modules,
        public array $parameters,
        public bool $requiresParameterExtraction,
        public bool $requiresUserConfirmation,
        public string $responseTemplate,
        public string $responseLanguage,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'is_in_scope' => $this->isInScope,
            'intent' => $this->intent,
            'primary_capability' => $this->primaryCapability,
            'capabilities' => $this->capabilities,
            'parameters' => $this->parameters,
            'requires_parameter_extraction' => $this->requiresParameterExtraction,
            'requires_user_confirmation' => $this->requiresUserConfirmation,
            'response_template' => $this->responseTemplate,
            'response_language' => $this->responseLanguage,
        ];
    }
}
