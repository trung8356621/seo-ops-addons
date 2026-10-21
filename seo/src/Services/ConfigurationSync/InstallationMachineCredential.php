<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\ConfigurationSync;

/**
 * H1 machine credential. Secret stays on private properties so json_encode cannot leak it.
 */
final class InstallationMachineCredential
{
    public function __construct(
        private readonly string $credentialId,
        private readonly string $installationId,
        private readonly string $secretBase64Url,
    ) {}

    public function credentialId(): string
    {
        return $this->credentialId;
    }

    public function installationId(): string
    {
        return $this->installationId;
    }

    public function secretBase64Url(): string
    {
        return $this->secretBase64Url;
    }
}
