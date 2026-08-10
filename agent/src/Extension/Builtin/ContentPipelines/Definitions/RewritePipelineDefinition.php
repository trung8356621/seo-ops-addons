<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Extension\Builtin\ContentPipelines\Definitions;

use Omnichannel\Addons\Agent\Extension\Contracts\PipelineDefinitionInterface;

final class RewritePipelineDefinition implements PipelineDefinitionInterface
{
    public function key(): string
    {
        return 'rewrite';
    }

    public function name(): string
    {
        return 'Viết lại bài viết (Rewrite)';
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
            ['key' => 'article.rewrite.generate', 'label' => 'Viết lại nội dung nguồn', 'stage' => 'article', 'required' => true],
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
        $errors = [];

        if (blank($context['connection_id'] ?? null)) {
            $errors[] = 'connection_id là bắt buộc để chạy pipeline rewrite.';
        }

        if (blank($context['source_content'] ?? null)) {
            $errors[] = 'source_content là bắt buộc để viết lại.';
        }

        return ['ok' => $errors === [], 'errors' => $errors];
    }
}
