<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Extension\Builtin\ContentPipelines\Definitions;

use Omnichannel\Addons\Agent\Extension\Contracts\PipelineDefinitionInterface;

final class ArticlePipelineDefinition implements PipelineDefinitionInterface
{
    public function key(): string
    {
        return 'article';
    }

    public function name(): string
    {
        return 'Bài viết mới (Outline → Article → SEO Audit)';
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
        return ['article'];
    }

    /**
     * @return list<array{key: string, label: string, stage: string, required: bool}>
     */
    public function steps(): array
    {
        return [
            ['key' => 'article.outline.generate', 'label' => 'Sinh dàn ý (outline)', 'stage' => 'outline', 'required' => true],
            ['key' => 'article.content.generate', 'label' => 'Sinh nội dung bài viết', 'stage' => 'article', 'required' => true],
            ['key' => 'article.image.generate', 'label' => 'Sinh ảnh minh hoạ', 'stage' => 'image', 'required' => false],
            ['key' => 'article.seo_audit.run', 'label' => 'Audit SEO on-page', 'stage' => 'seo_audit', 'required' => false],
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
        // Project-level generate: workflow engine owns connection/keyword resolution.
        if (! blank($context['project_id'] ?? null)) {
            return ['ok' => true, 'errors' => []];
        }

        $errors = [];

        if (blank($context['connection_id'] ?? null)) {
            $errors[] = 'connection_id là bắt buộc để chạy pipeline article.';
        }

        if (blank($context['keyword'] ?? null) && blank($context['topic'] ?? null)) {
            $errors[] = 'keyword hoặc topic là bắt buộc để sinh outline.';
        }

        return ['ok' => $errors === [], 'errors' => $errors];
    }
}
