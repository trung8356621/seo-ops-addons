<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultProductGalleryPromptsInstaller;
use Omnichannel\Addons\AiPrompt\Support\ProductGallery\ProductGalleryPromptVariableNormalizer;
use PHPUnit\Framework\TestCase;

final class ProductGalleryPromptRenderingTest extends TestCase
{
    public function test_plan_markdown_contains_required_placeholders(): void
    {
        $md = DefaultProductGalleryPromptsInstaller::MARKDOWN_PLAN;
        foreach (['product_title', 'requested_image_count', 'negative_constraints', 'keyword'] as $key) {
            $this->assertTrue(
                str_contains($md, '{{ '.$key.' }}') || str_contains($md, '{{'.$key.'}}'),
                'plan missing '.$key,
            );
        }
        $this->assertStringContainsString('"shots"', $md);
    }

    public function test_parent_markdown_has_no_base64_and_notes_runtime_attachment(): void
    {
        $md = DefaultProductGalleryPromptsInstaller::MARKDOWN_PARENT;
        $this->assertStringNotContainsString('data:image/', $md);
        $this->assertStringContainsString('inlineData', $md);
        $this->assertStringContainsString('not embedded in this text prompt', $md);
        foreach (['product_title', 'negative_constraints', 'primary_color'] as $key) {
            $this->assertTrue(str_contains($md, '{{ '.$key.' }}'));
        }
    }

    public function test_child_markdown_requires_shot_fields(): void
    {
        $md = DefaultProductGalleryPromptsInstaller::MARKDOWN_CHILD;
        foreach (['shot_key', 'shot_label', 'aspect_ratio', 'shot_instruction', 'product_identity'] as $key) {
            $this->assertTrue(str_contains($md, '{{ '.$key.' }}'), 'child missing '.$key);
        }
        $this->assertStringContainsString('inlineData', $md);
    }

    public function test_normalizer_maps_legacy_title_aliases(): void
    {
        $plan = ProductGalleryPromptVariableNormalizer::forPlan([
            'title' => 'Bag A',
            'description' => 'Desc',
            'requested_image_count' => 3,
        ]);
        $this->assertSame('Bag A', $plan['product_title']);
        $this->assertSame('Desc', $plan['product_description']);

        $parent = ProductGalleryPromptVariableNormalizer::forParent([
            'title' => 'Bag B',
            'category' => 'Bags',
            'brand' => 'X',
            'shape' => 'tote',
            'original_media_ids' => [10, 11],
        ]);
        $this->assertSame('Bag B', $parent['product_title']);
        $this->assertSame('Bags', $parent['product_category']);
        $this->assertSame('10,11', $parent['original_media_ids']);

        $child = ProductGalleryPromptVariableNormalizer::forChild([
            'title' => 'Bag C',
            'shot_key' => 'front',
            'shot_instruction' => 'Front view',
        ]);
        $this->assertSame('Bag C', $child['product_title']);
        $this->assertSame('front', $child['shot_key']);
    }

    public function test_normalizer_strips_data_uri_from_text_variables(): void
    {
        $parent = ProductGalleryPromptVariableNormalizer::forParent([
            'product_title' => 'Bag',
            'distinctive_features' => 'data:image/png;base64,AAAA',
        ]);
        $this->assertSame('', $parent['distinctive_features']);
    }

    public function test_sample_variables_cover_three_hooks(): void
    {
        foreach ([
            'product.gallery.plan',
            'product.gallery.parent.generate',
            'product.gallery.child.generate',
        ] as $hook) {
            $sample = ProductGalleryPromptVariableNormalizer::sampleForHook($hook);
            $this->assertNotSame('', (string) ($sample['product_title'] ?? ''));
            $encoded = json_encode($sample) ?: '';
            $this->assertStringNotContainsString('data:image/', $encoded);
        }
    }

    public function test_simple_placeholder_compile_plan_sample(): void
    {
        $md = DefaultProductGalleryPromptsInstaller::MARKDOWN_PLAN;
        $vars = ProductGalleryPromptVariableNormalizer::sampleForHook('product.gallery.plan');
        $compiled = $md;
        foreach ($vars as $key => $value) {
            $compiled = str_replace(
                ['{{ '.$key.' }}', '{{'.$key.'}}'],
                [(string) $value, (string) $value],
                $compiled,
            );
        }
        $this->assertStringNotContainsString('{{ product_title }}', $compiled);
        $this->assertStringContainsString('Sample Product Bag', $compiled);
        $this->assertStringContainsString('3', $compiled);
    }
}
