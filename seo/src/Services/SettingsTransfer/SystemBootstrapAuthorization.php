<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\SettingsTransfer;

use Omnichannel\Addons\AiPrompt\Exceptions\ConfigurationPackageException;

/**
 * Explicit capability token for Golden Configuration system bootstrap.
 *
 * Cannot be forged from a request payload — only constructed via named factories.
 * Does not weaken {@see SeoSettingsBundleService::apply()} Manager checks for UI/API.
 */
final class SystemBootstrapAuthorization
{
    private const PURPOSE_GOLDEN_BOOTSTRAP = 'golden_configuration_bootstrap';

    private function __construct(
        private readonly string $purpose,
    ) {}

    public static function forGoldenConfigurationBootstrap(): self
    {
        return new self(self::PURPOSE_GOLDEN_BOOTSTRAP);
    }

    public function assertAuthorized(): void
    {
        if ($this->purpose !== self::PURPOSE_GOLDEN_BOOTSTRAP) {
            throw ConfigurationPackageException::rejected(
                'System bootstrap authorization is invalid for this operation.'
            );
        }
    }

    public function purpose(): string
    {
        return $this->purpose;
    }
}
