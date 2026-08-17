<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Exceptions\ConfigurationPackageException;
use Omnichannel\Addons\AiPrompt\Services\ConfigurationPackages\ConfigurationPackageParser;
use Omnichannel\Addons\AiPrompt\Support\ConfigurationPackageType;
use PHPUnit\Framework\TestCase;

final class ConfigurationPackageParserTest extends TestCase
{
    public function test_requires_explicit_package_type(): void
    {
        $this->expectException(ConfigurationPackageException::class);
        $this->expectExceptionMessage('package_type is required');
        (new ConfigurationPackageParser())->parse(json_encode([
            'schema_version' => '1.0',
            'settings' => ['general' => []],
        ], JSON_THROW_ON_ERROR));
    }

    public function test_rejects_unknown_future_schema(): void
    {
        $this->expectException(ConfigurationPackageException::class);
        $this->expectExceptionMessage('schema 2.0');
        (new ConfigurationPackageParser())->parse(json_encode([
            'package_type' => 'seo_settings',
            'schema_version' => '2.0',
            'settings' => [],
        ], JSON_THROW_ON_ERROR));
    }

    public function test_parses_known_types(): void
    {
        foreach ([
            ConfigurationPackageType::SeoSettings->value,
            ConfigurationPackageType::PromptPack->value,
            ConfigurationPackageType::SeoConfigurationBundle->value,
        ] as $type) {
            $parsed = (new ConfigurationPackageParser())->parse(json_encode([
                'package_type' => $type,
                'schema_version' => '1.0',
                'settings' => [],
                'prompts' => [],
            ], JSON_THROW_ON_ERROR));
            $this->assertSame($type, $parsed['type']->value);
        }
    }

    public function test_prompt_body_may_contain_json_examples_with_schema_version(): void
    {
        $content = "```json\n{\"schema_version\":\"1.0\",\"api_key\":\"example-not-used\"}\n```";
        $parsed = (new ConfigurationPackageParser())->parse(json_encode([
            'package_type' => 'prompt_pack',
            'schema_version' => '1.0',
            'prompts' => [[
                'portable_uuid' => '11111111-1111-4111-8111-111111111111',
                'name' => 'Example',
                'content' => $content,
                'tool' => 'default',
            ]],
        ], JSON_THROW_ON_ERROR));
        $this->assertSame('prompt_pack', $parsed['type']->value);
        $this->assertSame($content, $parsed['data']['prompts'][0]['content']);
    }
}
