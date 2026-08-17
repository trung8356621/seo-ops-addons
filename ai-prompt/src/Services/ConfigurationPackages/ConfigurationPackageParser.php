<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\ConfigurationPackages;

use Omnichannel\Addons\AiPrompt\Exceptions\ConfigurationPackageException;
use Omnichannel\Addons\AiPrompt\Support\ConfigurationPackageType;

final class ConfigurationPackageParser
{
    public function __construct(
        private readonly ConfigurationJsonGuard $guard = new ConfigurationJsonGuard(),
    ) {}

    /**
     * @return array{type: ConfigurationPackageType, schema_version: string, data: array<string, mixed>}
     */
    public function parse(string $rawJson): array
    {
        $probe = $this->guard->decode($rawJson, ConfigurationPackageLimits::fullBundle());
        $typeRaw = trim((string) ($probe['package_type'] ?? ''));
        if ($typeRaw === '' && isset($probe['provider'], $probe['connection'], $probe['endpoints'])) {
            $typeRaw = ConfigurationPackageType::AiProviderTemplate->value;
        }
        $type = ConfigurationPackageType::tryFrom($typeRaw);
        if ($type === null) {
            throw ConfigurationPackageException::rejected('package_type is required and must be an explicit known type.');
        }

        $limits = match ($type) {
            ConfigurationPackageType::AiProviderTemplate => ConfigurationPackageLimits::providerTemplate(),
            ConfigurationPackageType::SeoSettings => ConfigurationPackageLimits::settings(),
            ConfigurationPackageType::PromptPack => ConfigurationPackageLimits::promptPack(),
            ConfigurationPackageType::SeoConfigurationBundle => ConfigurationPackageLimits::fullBundle(),
        };

        $data = $this->guard->decode($rawJson, $limits);
        $version = trim((string) ($data['schema_version'] ?? ''));
        if ($version !== $limits->schemaVersion) {
            throw ConfigurationPackageException::unsupportedVersion($version !== '' ? $version : '(missing)', $limits->schemaVersion);
        }

        return [
            'type' => $type,
            'schema_version' => $version,
            'data' => $data,
        ];
    }
}
