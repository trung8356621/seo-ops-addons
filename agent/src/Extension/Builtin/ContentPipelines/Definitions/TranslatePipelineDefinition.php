<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Extension\Builtin\ContentPipelines\Definitions;

use Omnichannel\Addons\Agent\Extension\Contracts\PipelineDefinitionInterface;

final class TranslatePipelineDefinition implements PipelineDefinitionInterface
{
    public function key(): string
    {
        return 'translate';
    }

    public function name(): string
    {
        return 'Dịch bài viết (Translate)';
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
            ['key' => 'article.translate.generate', 'label' => 'Dịch nội dung sang ngôn ngữ đích', 'stage' => 'translate', 'required' => true],
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
            $errors[] = 'connection_id là bắt buộc để chạy pipeline translate.';
        }

        if (blank($context['source_content'] ?? null)) {
            $errors[] = 'source_content là bắt buộc để dịch.';
        }

        if (blank($context['target_language'] ?? null)) {
            $errors[] = 'target_language là bắt buộc để dịch.';
        }

        return ['ok' => $errors === [], 'errors' => $errors];
    }
}
