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
            0,
            null,
            [
                'failure_code' => 'NO_AI_CONNECTION',
                'profile' => $profile,
                'capability' => $capability,
                'retryable' => false,
            ],
        );
    }

    public static function noValidFreeConnection(string $profile): self
    {
        return new self(
            'NO_VALID_FREE_CONNECTION: no configured free AI connection/model for profile "'.$profile.'". '
            .'Configure a free route in AI Center — runtime will not create API keys or connections.',
            0,
            null,
            [
                'failure_code' => 'NO_VALID_FREE_CONNECTION',
                'profile' => $profile,
                'retryable' => false,
                'credential_source' => 'configured_connection_required',
                'auto_create_credential' => false,
                'user_message' => 'No valid free AI connection is configured.',
            ],
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
