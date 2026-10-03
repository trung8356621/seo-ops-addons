<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Illuminate\Validation\ValidationException;
use Omnichannel\Addons\AiPrompt\Filament\Resources\PromptResource;
use Omnichannel\Addons\AiPrompt\PromptHooks\PromptHookFormSchema;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookDefinitionLoader;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookEditorCatalog;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRuntimeRegistry;
use Omnichannel\Addons\Media\Support\ImageToolType;
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

    public function test_hook_variables_only_come_from_selected_hook_definition(): void
    {
        $variables = PromptHookFormSchema::hookVariables('article.comment.generate');
        $keys = array_column($variables, 'name');

        self::assertNotEmpty($variables);
        self::assertContains('post_title', $keys);
        self::assertContains('comment_count', $keys);
        self::assertNotContains('article_length_product', $keys);
        self::assertNotContains('keyword_density_default', $keys);
    }

    public function test_hook_variables_keep_required_and_optional_metadata(): void
    {
        $variables = collect(PromptHookFormSchema::hookVariables('article.comment.generate'))
            ->keyBy('name');

        self::assertTrue($variables->get('post_title')['required']);
        self::assertFalse($variables->get('comment_count')['required']);
        self::assertNotSame('', $variables->get('post_title')['label']);
        self::assertNotSame('', $variables->get('post_title')['description']);
    }

    public function test_no_hook_has_no_hook_variables(): void
    {
        self::assertSame([], PromptHookFormSchema::hookVariables(''));
    }

    public function test_hook_variable_badges_copy_exact_placeholder(): void
    {
        $html = PromptHookFormSchema::hookVariablesHtml('article.comment.generate')->toHtml();

        self::assertStringContainsString('{{post_title}}', $html);
        self::assertStringContainsString('window.omiCopyText', $html);
        self::assertStringContainsString('execCommand', $html);
        self::assertStringNotContainsString('article_length_product', $html);
    }

    public function test_prompt_resource_form_keeps_hook_key_above_prompt_editor(): void
    {
        $source = (string) file_get_contents((new \ReflectionClass(PromptResource::class))->getFileName());
        $hookFields = strpos($source, '...PromptHookFormSchema::fields()');
        $editor = strpos($source, "MarkdownCodeEditor::make('markdown_content')");

        self::assertNotFalse($hookFields);
        self::assertNotFalse($editor);
        self::assertLessThan($editor, $hookFields);
        self::assertStringContainsString("Select::make('hook_key')", (string) file_get_contents(
            (new \ReflectionClass(PromptHookFormSchema::class))->getFileName(),
        ));
    }

    public function test_prompt_resource_omits_custom_variables_and_hook_guidance(): void
    {
        $source = (string) file_get_contents((new \ReflectionClass(PromptResource::class))->getFileName());

        self::assertStringNotContainsString("Repeater::make('variables')", $source);
        self::assertStringNotContainsString('PromptHookFormSchema::guidanceSection()', $source);
        self::assertStringContainsString('...PromptHookFormSchema::variableBadgeFields()', $source);
    }
}
