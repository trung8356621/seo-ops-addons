<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\ConfigurationPackages;

final readonly class ConfigurationPackageLimits
{
    /**
     * @param  list<string>  $supportedSchemaVersions
     * @param  list<string>  $secretValueSkipKeys
     */
    public function __construct(
        public int $maxBytes = 65536,
        public int $maxDepth = 12,
        public int $maxArrayLength = 200,
        public int $maxObjectKeys = 80,
        public int $maxStringLength = 2048,
        public string $schemaVersion = '1.0',
        public array $supportedSchemaVersions = ['1.0'],
        public array $secretValueSkipKeys = [],
        public bool $checkDuplicateSensitiveKeys = false,
        public bool $checkDangerousFields = false,
    ) {}

    public static function providerTemplate(): self
    {
        return new self(
            checkDuplicateSensitiveKeys: true,
            checkDangerousFields: true,
        );
    }

    public static function settings(): self
    {
        return new self(
            maxBytes: 262144,
            maxDepth: 16,
            maxArrayLength: 400,
            maxObjectKeys: 120,
            maxStringLength: 8192,
            secretValueSkipKeys: ['tone_text', 'keyword_density_product', 'keyword_density_default'],
        );
    }

    public static function promptPack(): self
    {
        return new self(
            maxBytes: 1048576,
            maxDepth: 12,
            maxArrayLength: 250,
            maxObjectKeys: 80,
            maxStringLength: 120000,
            secretValueSkipKeys: ['content', 'markdown_content', 'description', 'name'],
        );
    }

    public static function fullBundle(): self
    {
        return new self(
            maxBytes: 2097152,
            maxDepth: 16,
            maxArrayLength: 500,
            maxObjectKeys: 150,
            maxStringLength: 120000,
            schemaVersion: '1.1',
            supportedSchemaVersions: ['1.0', '1.1'],
            secretValueSkipKeys: ['content', 'markdown_content', 'description', 'name', 'tone_text'],
        );
    }
}
