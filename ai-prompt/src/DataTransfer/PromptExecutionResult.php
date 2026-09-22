<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\DataTransfer;

use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionTransport;
use Omnichannel\Addons\AiPrompt\Support\AiRoutingPolicy;

/**
 * Normalized result from {@see \Omnichannel\Addons\AiPrompt\Services\InteractivePromptExecutor}.
 *
 * @phpstan-type UsageMap array<string, mixed>|null
 */
final class PromptExecutionResult
{
    /**
     * @param  UsageMap  $usage
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly string $text,
        public readonly ?array $usage,
        public readonly ?RoutedAiCandidate $candidate,
        public readonly AiExecutionProfile $executionProfile,
        public readonly AiRoutingPolicy $routingPolicyRequested,
        public readonly AiRoutingPolicy $routingPolicyEffective,
        public readonly AiExecutionTransport $executionTransport,
        public readonly array $meta = [],
    ) {}
}
