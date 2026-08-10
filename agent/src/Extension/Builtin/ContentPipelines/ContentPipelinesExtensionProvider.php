<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Extension\Builtin\ContentPipelines;

use Omnichannel\Addons\Content\Extension\Builtin\ContentPipelines\Definitions\ArticlePipelineDefinition;
use Omnichannel\Addons\Agent\Extension\Builtin\ContentPipelines\Definitions\ImprovePipelineDefinition;
use Omnichannel\Addons\Agent\Extension\Builtin\ContentPipelines\Definitions\ProductPipelineDefinition;
use Omnichannel\Addons\Agent\Extension\Builtin\ContentPipelines\Definitions\RewritePipelineDefinition;
use Omnichannel\Addons\Agent\Extension\Builtin\ContentPipelines\Definitions\TranslatePipelineDefinition;
use Omnichannel\Addons\Agent\Extension\Contracts\ExtensionProvider;
use Omnichannel\Addons\Agent\Extension\ExtensionContext;

final class ContentPipelinesExtensionProvider implements ExtensionProvider
{
    public function __construct(
        private readonly ArticlePipelineDefinition $article,
        private readonly RewritePipelineDefinition $rewrite,
        private readonly ImprovePipelineDefinition $improve,
        private readonly TranslatePipelineDefinition $translate,
        private readonly ProductPipelineDefinition $product,
        private readonly ContentPipelinesHealthDriver $healthDriver,
    ) {}

    public function id(): string
    {
        return 'content-pipelines';
    }

    public function register(ExtensionContext $ctx): void
    {
        $ctx->pipelines()->registerDefinition($this->article);
        $ctx->pipelines()->registerDefinition($this->rewrite);
        $ctx->pipelines()->registerDefinition($this->improve);
        $ctx->pipelines()->registerDefinition($this->translate);
        $ctx->pipelines()->registerDefinition($this->product);
        $ctx->pipelines()->register($this->id(), $this->healthDriver);
    }

    public function boot(ExtensionContext $ctx): void
    {
        unset($ctx);
    }
}
