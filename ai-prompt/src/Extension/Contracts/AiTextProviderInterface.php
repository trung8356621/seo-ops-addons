<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Extension\Contracts;

use Omnichannel\Addons\AiPrompt\Extension\Contracts\Ai\AiExecutionContext;
use Omnichannel\Addons\AiPrompt\Extension\Contracts\Ai\AiProviderHealthResult;
use Omnichannel\Addons\AiPrompt\Extension\Contracts\Ai\AiTextRequest;
use Omnichannel\Addons\AiPrompt\Extension\Contracts\Ai\AiTextResult;

interface AiTextProviderInterface
{
    public function key(): string;

    public function supportsModel(string $model): bool;

    public function generate(AiTextRequest $request, AiExecutionContext $context): AiTextResult;

    public function health(AiExecutionContext $context): AiProviderHealthResult;
}
