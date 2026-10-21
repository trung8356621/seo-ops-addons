<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\ConfigurationSync;

/**
 * Supplied by the Client shell when H1 enrollment exists.
 * Configuration sync must not invent a second enrollment store.
 */
interface InstallationMachineCredentialProvider
{
    public function current(): ?InstallationMachineCredential;
}
