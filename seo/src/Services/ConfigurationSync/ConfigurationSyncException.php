<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\ConfigurationSync;

final class ConfigurationSyncException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 0,
    ) {
        parent::__construct($message, $httpStatus);
    }

    public static function blockedOnH1(): self
    {
        return new self(
            'blocked_on_h1',
            'Live configuration sync is blocked until H1 installation machine credentials are enrolled.',
        );
    }
}
