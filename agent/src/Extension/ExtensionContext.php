<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Extension;

use Omnichannel\Addons\AiPrompt\Extension\Registry\AiProviderRegistry;
use Omnichannel\Addons\Agent\Extension\Registry\ExtensionCapabilityRegistry;
use Omnichannel\Addons\Agent\Extension\Registry\ExtensionRegistry;
use Omnichannel\Addons\Media\Extension\Registry\MediaProcessorRegistry;
use Omnichannel\Addons\Agent\Extension\Registry\PipelineRegistry;
use Omnichannel\Addons\AiPrompt\Extension\Registry\PromptHookExtensionRegistry;
use Omnichannel\Addons\Publishing\Extension\Registry\PublisherRegistry;
use Omnichannel\Addons\Seo\Extension\Registry\SeoProviderRegistry;
use Omnichannel\Addons\Agent\Extension\Registry\WorkflowExtensionRegistry;
use Omnichannel\Addons\Publishing\Application\Publishing\ContentPublisherRegistry;

final class ExtensionContext
{
    public function __construct(
        private readonly PublisherRegistry $publishers,
        private readonly ContentPublisherRegistry $contentPublishers,
        private readonly AiProviderRegistry $aiProviders,
        private readonly SeoProviderRegistry $seoProviders,
        private readonly PipelineRegistry $pipelines,
        private readonly ExtensionCapabilityRegistry $capabilities,
        private readonly PromptHookExtensionRegistry $promptHooks,
        private readonly MediaProcessorRegistry $mediaProcessors,
        private readonly WorkflowExtensionRegistry $workflows,
        private readonly ExtensionEventBus $events,
        private readonly ExtensionRegistry $extensions,
    ) {}

    public function publishers(): PublisherRegistry
    {
        return $this->publishers;
    }

    public function contentPublishers(): ContentPublisherRegistry
    {
        return $this->contentPublishers;
    }

    public function aiProviders(): AiProviderRegistry
    {
        return $this->aiProviders;
    }

    public function seoProviders(): SeoProviderRegistry
    {
        return $this->seoProviders;
    }

    public function pipelines(): PipelineRegistry
    {
        return $this->pipelines;
    }

    public function capabilities(): ExtensionCapabilityRegistry
    {
        return $this->capabilities;
    }

    public function promptHooks(): PromptHookExtensionRegistry
    {
        return $this->promptHooks;
    }

    public function mediaProcessors(): MediaProcessorRegistry
    {
        return $this->mediaProcessors;
    }

    public function workflows(): WorkflowExtensionRegistry
    {
        return $this->workflows;
    }

    public function events(): ExtensionEventBus
    {
        return $this->events;
    }

    public function extensions(): ExtensionRegistry
    {
        return $this->extensions;
    }
}
