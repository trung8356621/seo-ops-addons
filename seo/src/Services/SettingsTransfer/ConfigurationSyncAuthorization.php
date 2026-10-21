<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\SettingsTransfer;

use Omnichannel\Addons\AiPrompt\Exceptions\ConfigurationPackageException;

/**
 * Explicit token for read-only full-configuration export used by sync.
 * Does not authorize apply() and does not satisfy Manager UI checks.
 */
final class ConfigurationSyncAuthorization
{
    private const PURPOSE_READ_EXPORT = 'configuration_sync_read_export';

    private function __construct(
        private readonly string $purpose,
    ) {}

    public static function forReadExport(): self
    {
        return new self(self::PURPOSE_READ_EXPORT);
    }

    public function assertAuthorized(): void
    {
        if ($this->purpose !== self::PURPOSE_READ_EXPORT) {
            throw ConfigurationPackageException::rejected(
                'Configuration sync export authorization is invalid for this operation.'
            );
        }
    }
}
