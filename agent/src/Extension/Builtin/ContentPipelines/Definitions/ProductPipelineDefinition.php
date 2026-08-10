<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Extension\Builtin\ContentPipelines\Definitions;

use Omnichannel\Addons\Agent\Extension\Contracts\PipelineDefinitionInterface;

final class ProductPipelineDefinition implements PipelineDefinitionInterface
{
    public function key(): string
    {
        return 'product';
    }

    public function name(): string
    {
        return 'Bài viết sản phẩm (Product outline → gallery → SEO Audit)';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    /**
     * @return list<string>
     */
    public function supportedContentTypes(): array
    {
        return ['product'];
    }

    /**
     * @return list<array{key: string, label: string, stage: string, required: bool}>
     */
    public function steps(): array
    {
        return [
            ['key' => 'product.outline.generate', 'label' => 'Sinh dàn ý bài sản phẩm', 'stage' => 'outline', 'required' => true],
            ['key' => 'product.content.generate', 'label' => 'Sinh nội dung bài sản phẩm', 'stage' => 'article', 'required' => true],
            ['key' => 'product.gallery.generate', 'label' => 'Sinh gallery ảnh sản phẩm', 'stage' => 'image', 'required' => false],
            ['key' => 'product.seo_audit.run', 'label' => 'Audit SEO on-page', 'stage' => 'seo_audit', 'required' => false],
        ];
    }

    /**
     * @return list<string>
     */
    public function requiredCapabilities(): array
    {
        return ['ai.text.generate'];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{ok: bool, errors: list<string>}
     */
    public function validate(array $context): array
    {
        $errors = [];

        if (blank($context['connection_id'] ?? null)) {
            $errors[] = 'connection_id là bắt buộc để chạy pipeline product.';
        }

        if (blank($context['product_id'] ?? null) && blank($context['product_name'] ?? null)) {
            $errors[] = 'product_id hoặc product_name là bắt buộc.';
        }

        return ['ok' => $errors === [], 'errors' => $errors];
    }
}
