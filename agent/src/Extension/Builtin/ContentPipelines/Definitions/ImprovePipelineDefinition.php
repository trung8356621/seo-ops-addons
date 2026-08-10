<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Extension\Builtin\ContentPipelines\Definitions;

use Omnichannel\Addons\Agent\Extension\Contracts\PipelineDefinitionInterface;

final class ImprovePipelineDefinition implements PipelineDefinitionInterface
{
    public function key(): string
    {
        return 'improve';
    }

    public function name(): string
    {
        return 'Cải thiện bài viết hiện có (Improve)';
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
            ['key' => 'article.review.analyze', 'label' => 'Phân tích nội dung hiện tại', 'stage' => 'review', 'required' => true],
            ['key' => 'article.content.improve', 'label' => 'Cải thiện nội dung', 'stage' => 'article', 'required' => true],
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
            $errors[] = 'connection_id là bắt buộc để chạy pipeline improve.';
        }

        if (blank($context['article_id'] ?? null)) {
            $errors[] = 'article_id là bắt buộc để cải thiện bài viết đã có.';
        }

        return ['ok' => $errors === [], 'errors' => $errors];
    }
}
