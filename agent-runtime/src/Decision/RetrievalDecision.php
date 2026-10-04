<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Decision;

final readonly class RetrievalDecision
{
    /**
     * @param  list<string>  $modules
     * @param  array<string, scalar|null>  $parameters
     */
    public function __construct(
        public bool $isInScope,
        public string $intent,
        public ?string $primaryModule,
        public array $modules,
        public array $parameters,
        public bool $requiresParameterExtraction,
        public bool $requiresUserConfirmation,
        public string $responseTemplate,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'is_in_scope' => $this->isInScope,
            'intent' => $this->intent,
            'primary_module' => $this->primaryModule,
            'modules' => $this->modules,
            'parameters' => $this->parameters,
            'requires_parameter_extraction' => $this->requiresParameterExtraction,
            'requires_user_confirmation' => $this->requiresUserConfirmation,
            'response_template' => $this->responseTemplate,
        ];
    }
}
