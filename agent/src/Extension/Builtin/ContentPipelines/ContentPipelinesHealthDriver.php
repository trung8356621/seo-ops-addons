<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Extension\Builtin\ContentPipelines;

use Omnichannel\Addons\Content\Extension\Builtin\ContentPipelines\Definitions\ArticlePipelineDefinition;
use Omnichannel\Addons\Agent\Extension\Builtin\ContentPipelines\Definitions\ImprovePipelineDefinition;
use Omnichannel\Addons\Agent\Extension\Builtin\ContentPipelines\Definitions\ProductPipelineDefinition;
use Omnichannel\Addons\Agent\Extension\Builtin\ContentPipelines\Definitions\RewritePipelineDefinition;
use Omnichannel\Addons\Agent\Extension\Builtin\ContentPipelines\Definitions\TranslatePipelineDefinition;
use Omnichannel\Addons\Agent\Extension\Contracts\PipelineStepDriver;

/**
 * Legacy `PipelineStepDriver` adapter so ExtensionHealthService (which keys drivers by
 * extension id) can report aggregate health for the "content-pipelines" builtin extension.
 */
final class ContentPipelinesHealthDriver implements PipelineStepDriver
{
    /** @var list<ArticlePipelineDefinition|RewritePipelineDefinition|ImprovePipelineDefinition|TranslatePipelineDefinition|ProductPipelineDefinition> */
    private array $definitions;

    public function __construct(
        ArticlePipelineDefinition $article,
        RewritePipelineDefinition $rewrite,
        ImprovePipelineDefinition $improve,
        TranslatePipelineDefinition $translate,
        ProductPipelineDefinition $product,
    ) {
        $this->definitions = [$article, $rewrite, $improve, $translate, $product];
    }

    public function id(): string
    {
        return 'content-pipelines';
    }

    public function label(): string
    {
        return 'Built-in Content Pipelines (article, rewrite, improve, translate, product)';
    }

    public function stage(): string
    {
        return 'custom';
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function health(): array
    {
        $keys = array_map(static fn ($definition): string => $definition->key(), $this->definitions);

        return [
            'ok' => count($keys) === count(array_unique($keys)),
            'message' => 'Registered pipelines: '.implode(', ', $keys),
        ];
    }
}
