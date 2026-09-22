<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\DataTransfer;

use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionTransport;
use Omnichannel\Addons\AiPrompt\Support\AiRoutingPolicy;

/**
 * Generic module contract for shared Prompt / AI execution.
 *
 * Callers specify hook/prompt, context, optional overrides, transport, and routing policy.
 * They must NOT know OpenRouter / Gemini / DeepSeek or own a planner.
 */
final class PromptExecutionRequest
{
    /**
     * @param  array<string, mixed>  $variables
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public readonly string $hookKey,
        public readonly ?string $compiledPrompt = null,
        public readonly ?SeoPrompt $prompt = null,
        public readonly array $variables = [],
        public readonly ?AiExecutionProfile $executionProfileOverride = null,
        public readonly ?AiRoutingPolicy $routingPolicy = null,
        public readonly AiExecutionTransport $transport = AiExecutionTransport::Background,
        public readonly array $options = [],
        public readonly ?AiRoutingContext $routingContext = null,
    ) {}
}
