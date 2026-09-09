<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\DataTransfer;

use Omnichannel\Addons\AiPrompt\Support\AiFailureCategory;
use Omnichannel\Addons\AiPrompt\Support\AiNormalizedFailureCode;

/**
 * Normalized execution failure for History / UI / diagnostics.
 */
final class AiNormalizedFailure
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public readonly AiFailureCategory $category,
        public readonly AiNormalizedFailureCode|string $code,
        public readonly string $userMessage,
        public readonly ?string $debugMessage = null,
        public readonly ?string $promptKey = null,
        public readonly ?string $stage = null,
        public readonly ?string $provider = null,
        public readonly ?int $connectionId = null,
        public readonly ?string $connectionName = null,
        public readonly ?string $logicalModel = null,
        public readonly ?string $physicalRoute = null,
        public readonly ?string $providerModel = null,
        public readonly ?int $attemptCount = null,
        public readonly ?int $httpStatus = null,
        public readonly ?string $validationContract = null,
        public readonly ?string $routingTerminalReason = null,
        public readonly ?string $exceptionClass = null,
        public readonly ?string $correlationId = null,
        public readonly array $extra = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $code = $this->code instanceof AiNormalizedFailureCode
            ? $this->code->value
            : (string) $this->code;

        return array_filter([
            'category' => $this->category->value,
            'code' => $code,
            'user_message' => $this->userMessage,
            'debug_message' => $this->debugMessage,
            'prompt_key' => $this->promptKey,
            'stage' => $this->stage,
            'provider' => $this->provider,
            'connection_id' => $this->connectionId,
            'connection_name' => $this->connectionName,
            'logical_model' => $this->logicalModel,
            'physical_route' => $this->physicalRoute,
            'provider_model' => $this->providerModel,
            'attempt_count' => $this->attemptCount,
            'http_status' => $this->httpStatus,
            'validation_contract' => $this->validationContract,
            'routing_terminal_reason' => $this->routingTerminalReason,
            'exception_class' => $this->exceptionClass,
            'correlation_id' => $this->correlationId,
            ...$this->extra,
        ], static fn (mixed $v): bool => $v !== null && $v !== '' && $v !== []);
    }
}
