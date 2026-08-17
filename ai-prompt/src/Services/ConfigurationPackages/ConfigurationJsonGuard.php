<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\ConfigurationPackages;

use Omnichannel\Addons\AiPrompt\Exceptions\ConfigurationPackageException;
use Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\AiProviderTemplateDocumentationStripper;
use Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\AiProviderTemplateLimits;
use Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\AiProviderTemplateSecretScanner;

final class ConfigurationJsonGuard
{
    public function __construct(
        private readonly AiProviderTemplateDocumentationStripper $stripper = new AiProviderTemplateDocumentationStripper(),
        private readonly AiProviderTemplateSecretScanner $secrets = new AiProviderTemplateSecretScanner(),
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function decode(string $rawJson, ConfigurationPackageLimits $limits): array
    {
        if (! mb_check_encoding($rawJson, 'UTF-8')) {
            throw ConfigurationPackageException::rejected('invalid UTF-8.');
        }
        if (strlen($rawJson) > $limits->maxBytes) {
            throw ConfigurationPackageException::rejected('file exceeds maximum size.');
        }
        if (trim($rawJson) === '') {
            throw ConfigurationPackageException::rejected('invalid JSON.');
        }

        json_decode($rawJson);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw ConfigurationPackageException::rejected('invalid JSON.');
        }

        if ($limits->checkDuplicateSensitiveKeys) {
            $this->assertSensitiveKeyUniqueness($rawJson);
        }

        try {
            $decoded = json_decode($rawJson, true, $limits->maxDepth, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ConfigurationPackageException::rejected('invalid JSON.');
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw ConfigurationPackageException::rejected('unexpected root type.');
        }

        $this->assertBounds($decoded, 0, $limits);
        if ($limits->checkDangerousFields) {
            $this->assertNoDangerousFields($decoded);
        }
        $this->secrets->assertClean($decoded, $limits->secretValueSkipKeys);

        $clean = $this->stripper->strip($decoded);
        if (! is_array($clean) || array_is_list($clean)) {
            throw ConfigurationPackageException::rejected('unexpected root type.');
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $node
     */
    private function assertBounds(array $node, int $depth, ConfigurationPackageLimits $limits): void
    {
        if ($depth > $limits->maxDepth) {
            throw ConfigurationPackageException::rejected('JSON nesting exceeds maximum depth.');
        }
        if (count($node) > $limits->maxArrayLength) {
            throw ConfigurationPackageException::rejected('JSON array exceeds maximum length.');
        }
        if (! array_is_list($node) && count($node) > $limits->maxObjectKeys) {
            throw ConfigurationPackageException::rejected('JSON object has too many keys.');
        }
        foreach ($node as $key => $value) {
            $skip = is_string($key) && in_array(strtolower($key), $limits->secretValueSkipKeys, true);
            if (is_string($value) && ! $skip && strlen($value) > $limits->maxStringLength) {
                throw ConfigurationPackageException::rejected('JSON string exceeds maximum length.');
            }
            if (is_array($value)) {
                $this->assertBounds($value, $depth + 1, $limits);
            }
        }
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $node
     */
    private function assertNoDangerousFields(array $node): void
    {
        foreach ($node as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), AiProviderTemplateLimits::FORBIDDEN_FIELD_NAMES, true)) {
                throw ConfigurationPackageException::rejected('unknown dangerous field "'.$key.'".');
            }
            if (is_array($value)) {
                $this->assertNoDangerousFields($value);
            }
        }
    }

    private function assertSensitiveKeyUniqueness(string $raw): void
    {
        foreach (['"base_url"', '"protocol"', '"schema_version"', '"api_key"'] as $needle) {
            if ($needle === '"api_key"' && substr_count(strtolower($raw), strtolower($needle)) > 1) {
                throw ConfigurationPackageException::rejected('API credentials must not be stored inside configuration packages.');
            }
            if (in_array($needle, ['"base_url"', '"protocol"', '"schema_version"'], true)
                && substr_count(strtolower($raw), strtolower($needle)) > 1) {
                throw ConfigurationPackageException::rejected('duplicate security-sensitive JSON keys are not allowed.');
            }
        }
    }
}
