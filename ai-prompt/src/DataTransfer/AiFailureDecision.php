<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\DataTransfer;

use Omnichannel\Addons\AiPrompt\Support\AiFailureClass;
use Omnichannel\Addons\AiPrompt\Support\AiFailureRuntimeAction;
use Omnichannel\Addons\AiPrompt\Support\AiFailureScope;
use Omnichannel\Addons\AiPrompt\Support\AiRuntimeHealthStatus;

/**
 * Central AI fallback decision.
 *
 * Default: fallback denied. Only BILLING / PROVIDER_API_REQUEST failures Continue.
 */
final readonly class AiFailureDecision
{
    public function __construct(
        public AiFailureClass $category,
        public AiFailureScope $scope,
        public bool $recoverable,
        public AiFailureRuntimeAction $runtimeAction,
        public ?AiRuntimeHealthStatus $healthStatus = null,
        public bool $manualUnlockRequired = false,
        public ?string $errorCode = null,
        public string $safeMessage = '',
        public ?int $httpStatus = null,
        public bool $applyCooldown = false,
        public bool $markModelUnavailable = false,
        public bool $lockConnection = false,
        public bool $lockConnectionPaid = false,
        public bool $affectsRuntimeHealth = true,
        public ?string $failureStage = null,
        public ?string $providerErrorCode = null,
        public ?bool $requestSent = null,
        public ?bool $responseReceived = null,
        public string $source = 'AiProviderFailureClassifier',
    ) {}

    public function fallbackAllowed(): bool
    {
        return $this->shouldContinueRouting();
    }

    public function shouldContinueRouting(): bool
    {
        return $this->recoverable
            && $this->runtimeAction === AiFailureRuntimeAction::Continue;
    }

    /**
     * @return array<string, mixed>
     */
    public function toAttemptDiagnostics(): array
    {
        return array_filter([
            'normalized_failure_category' => $this->category->value,
            'fallback_allowed' => $this->fallbackAllowed(),
            'fallback_reason' => $this->safeMessage,
            'failure_stage' => $this->failureStage,
            'raw_http_status' => $this->httpStatus,
            'provider_error_code' => $this->providerErrorCode ?? $this->errorCode,
            'request_sent' => $this->requestSent,
            'response_received' => $this->responseReceived,
            'source' => $this->source,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
