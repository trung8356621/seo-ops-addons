<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\DataTransfer;

use Omnichannel\Addons\AiPrompt\Support\AiFailureClass;
use Omnichannel\Addons\AiPrompt\Support\AiFailureRuntimeAction;
use Omnichannel\Addons\AiPrompt\Support\AiFailureScope;
use Omnichannel\Addons\AiPrompt\Support\AiRuntimeHealthStatus;

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
    ) {}

    public function shouldContinueRouting(): bool
    {
        return $this->recoverable
            && $this->runtimeAction === AiFailureRuntimeAction::Continue;
    }
}
