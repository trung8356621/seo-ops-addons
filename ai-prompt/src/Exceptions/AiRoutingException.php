<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Exceptions;

final class AiRoutingException extends PromptRunException
{
    public static function noCandidate(string $profile, string $capability): self
    {
        return new self(
            'No active model supports "'.$capability.'" for profile "'.$profile.'". '
            .'Configure AI Routing in Settings → AI Routing.',
        );
    }

    public static function modelLacksCapability(string $model, string $capability): self
    {
        return new self('Selected model does not support '.$capability.'.');
    }

    public static function crossTenant(): self
    {
        return new self('Cannot add a connection or model from another account to this routing profile.');
    }
}
