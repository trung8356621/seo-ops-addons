<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Exceptions;

class ConfigurationPackageException extends \RuntimeException
{
    public static function rejected(string $reason): self
    {
        $reason = preg_replace('/^(Package|Template) rejected:\s*/i', '', $reason) ?? $reason;

        return new self('Package rejected: '.$reason);
    }

    public static function unsupportedVersion(string $got, string $supported): self
    {
        return new self('This package uses schema '.$got.'. This seo-ops version supports up to '.$supported.'.');
    }
}
