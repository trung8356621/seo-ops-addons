<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Extension\Contracts;

use Omnichannel\Addons\AiPrompt\Extension\Contracts\Ai\AiExecutionContext;
use Omnichannel\Addons\Media\Extension\Contracts\Ai\AiImageRequest;
use Omnichannel\Addons\Media\Extension\Contracts\Ai\AiImageResult;
use Omnichannel\Addons\AiPrompt\Extension\Contracts\Ai\AiProviderHealthResult;

/**
 * Real image-generation boundary for AI provider extensions.
 */
interface AiImageProviderInterface
{
    /**
     * Registry key, e.g. "gemini", "imagen".
     */
    public function key(): string;

    public function generateImage(AiImageRequest $request, AiExecutionContext $context): AiImageResult;

    public function health(AiExecutionContext $context): AiProviderHealthResult;
}
