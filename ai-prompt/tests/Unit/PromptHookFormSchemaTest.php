<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\PromptHooks\PromptHookFormSchema;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookDefinitionLoader;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookEditorCatalog;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRuntimeRegistry;
use Omnichannel\Addons\Media\Support\ImageToolType;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class PromptHookFormSchemaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $loader = new PromptHookDefinitionLoader(
            PromptHookDefinitionLoader::defaultV01Directory(),
            PromptHookDefinitionLoader::defaultPhase1Directory(),
        );
        $loader->clearCache();
        app()->instance(
            PromptHookEditorCatalog::class,
            new PromptHookEditorCatalog(new PromptHookRuntimeRegistry($loader)),
        );
    }

    public function test_normalize_clears_hook_when_empty(): void
    {
        $data = PromptHookFormSchema::normalizeForSave([
            'hook_key' => '',
            'hook_version' => '0.1.0',
            'hook_settings' => ['max_length' => 65],
            'tools' => 'default',
        ]);

        self::assertNull($data['hook_key']);
        self::assertNull($data['hook_version']);
        self::assertNull($data['hook_settings']);
    }

    public function test_normalize_sets_version_and_settings_from_manifest(): void
    {
        $data = PromptHookFormSchema::normalizeForSave([
            'hook_key' => 'article.title_suggestion',
            'hook_settings' => ['max_length' => 70, 'garbage' => 1],
            'tools' => 'default',
        ]);

        self::assertSame('article.title_suggestion', $data['hook_key']);
        self::assertSame('0.1.0', $data['hook_version']);
        self::assertSame(70, $data['hook_settings']['max_length']);
        self::assertTrue($data['hook_settings']['preserve_meaning']);
        self::assertArrayNotHasKey('garbage', $data['hook_settings']);
    }

    public function test_normalize_falls_back_when_legacy_integer_version(): void
    {
        $data = PromptHookFormSchema::normalizeForSave([
            'hook_key' => 'article.meta_description_suggestion',
            'hook_version' => '1',
            'hook_settings' => ['max_length' => 160, 'min_length' => 100],
            'tools' => 'default',
        ]);

        self::assertSame('article.meta_description_suggestion', $data['hook_key']);
        self::assertSame('0.1.0', $data['hook_version']);
        self::assertSame(160, $data['hook_settings']['max_length']);
        self::assertSame(100, $data['hook_settings']['min_length']);
    }

    public function test_normalize_rejects_image_tool_for_text_hook(): void
    {
        $this->expectException(ValidationException::class);

        PromptHookFormSchema::normalizeForSave([
            'hook_key' => 'article.title_suggestion',
            'tools' => ImageToolType::Image->value,
            'hook_settings' => [],
        ]);
    }

    public function test_normalize_allows_image_tool_for_featured_image_hook(): void
    {
        $data = PromptHookFormSchema::normalizeForSave([
            'hook_key' => 'article.featured_image.generate',
            'tools' => ImageToolType::Image->value,
            'hook_settings' => [],
        ]);

        self::assertSame('article.featured_image.generate', $data['hook_key']);
        self::assertSame('0.1.0', $data['hook_version']);
    }

    public function test_outline_normalize_saves_semver(): void
    {
        $data = PromptHookFormSchema::normalizeForSave([
            'hook_key' => 'article.outline.generate',
            'tools' => 'default',
            'hook_settings' => [],
        ]);
        self::assertSame('article.outline.generate', $data['hook_key']);
        self::assertSame('0.1.0', $data['hook_version']);
    }
}
