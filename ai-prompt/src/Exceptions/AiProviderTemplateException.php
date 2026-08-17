<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Exceptions;

final class AiProviderTemplateException extends ConfigurationPackageException
{
    public static function rejected(string $reason): self
    {
        $reason = preg_replace('/^(Package|Template) rejected:\s*/i', '', $reason) ?? $reason;

        return new self('Template rejected: '.$reason);
    }
}
