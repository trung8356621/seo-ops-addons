<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\Commerce\Services\ProductGallery\GeminiProductGalleryParentChildAiAdapter;
use Omnichannel\Addons\Commerce\Services\ProductGallery\ProductGalleryPromptHookRuntime;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultProductGalleryPromptsInstaller;
use Omnichannel\Addons\AiPrompt\Support\ProductGallery\ProductGalleryPromptVariableNormalizer;
use PHPUnit\Framework\TestCase;

final class ProductGalleryPromptHookBindingTest extends TestCase
{
    public function test_three_hooks_json_exist_with_expected_keys(): void
    {
        $dir = ProjectRoot::addonsPath().'/ai-prompt'.'/resources/prompt-hooks/v01';
        foreach ([
            'product.gallery.plan@0.1.0.json',
            'product.gallery.parent.generate@0.1.0.json',
            'product.gallery.child.generate@0.1.0.json',
        ] as $file) {
            $path = $dir.'/'.$file;
            $this->assertFileExists($path);
            $json = json_decode((string) file_get_contents($path), true);
            $this->assertIsArray($json);
            $this->assertSame('0.1', $json['spec_version'] ?? null);
            $this->assertSame('legacy_prompt_content', $json['template']['source'] ?? null);
            $this->assertTrue((bool) ($json['settings_visible'] ?? false));
        }
    }

    public function test_installer_hook_constants_match_bindings(): void
    {
        $this->assertSame('product.gallery.plan', DefaultProductGalleryPromptsInstaller::HOOK_PLAN);
        $this->assertSame('product.gallery.parent.generate', DefaultProductGalleryPromptsInstaller::HOOK_PARENT);
        $this->assertSame('product.gallery.child.generate', DefaultProductGalleryPromptsInstaller::HOOK_CHILD);
        $this->assertSame(
            DefaultProductGalleryPromptsInstaller::HOOK_PLAN,
            GeminiProductGalleryParentChildAiAdapter::HOOK_PLAN,
        );
    }

    public function test_installer_does_not_overwrite_existing_binding(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/ai-prompt'.'/Services/PromptOwnership/DefaultProductGalleryPromptsInstaller.php',
        );
        $this->assertStringContainsString('if (! isset($bindings[$hookKey]))', $source);
        $this->assertStringContainsString('savePromptHookBindings', $source);
        $this->assertStringContainsString('where(\'hook_key\', $hookKey)', $source);
        $this->assertStringContainsString('where(\'name\', $name)', $source);
    }

    public function test_plan_hook_variables_declared(): void
    {
        $path = ProjectRoot::addonsPath().'/ai-prompt'.'/resources/prompt-hooks/v01/product.gallery.plan@0.1.0.json';
        $json = json_decode((string) file_get_contents($path), true);
        foreach (ProductGalleryPromptVariableNormalizer::requiredKeysForHook('product.gallery.plan') as $key) {
            $this->assertArrayHasKey($key, $json['input_schema']);
        }
    }

    public function test_parent_and_child_hooks_declare_identity_fields(): void
    {
        foreach (['parent', 'child'] as $which) {
            $hook = $which === 'parent'
                ? 'product.gallery.parent.generate'
                : 'product.gallery.child.generate';
            $file = $which === 'parent'
                ? 'product.gallery.parent.generate@0.1.0.json'
                : 'product.gallery.child.generate@0.1.0.json';
            $json = json_decode(
                (string) file_get_contents(ProjectRoot::addonsPath().'/ai-prompt'.'/resources/prompt-hooks/v01/'.$file),
                true,
            );
            foreach (ProductGalleryPromptVariableNormalizer::requiredKeysForHook($hook) as $key) {
                $this->assertArrayHasKey($key, $json['input_schema'], $hook.' missing '.$key);
            }
        }
    }

    public function test_runtime_version_pinned(): void
    {
        $this->assertSame('0.1.0', ProductGalleryPromptHookRuntime::VERSION);
    }
}
