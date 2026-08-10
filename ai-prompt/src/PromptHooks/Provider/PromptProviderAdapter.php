<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Provider;

use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\RenderedPromptRequest;

interface PromptProviderAdapter
{
    public function capabilities(): PromptProviderCapabilities;

    public function generate(RenderedPromptRequest $request, PromptStructuredStrategy $strategy): PromptProviderResponse;
}
