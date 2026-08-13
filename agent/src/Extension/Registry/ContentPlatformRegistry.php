<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Extension\Registry;

use Omnichannel\Addons\AiPrompt\Extension\Contracts\AiProviderDriver;
use Omnichannel\Addons\AiPrompt\Extension\Contracts\PromptHookContributor;
use Omnichannel\Addons\AiPrompt\Extension\Registry\AiProviderRegistry;
use Omnichannel\Addons\AiPrompt\Extension\Registry\PromptHookExtensionRegistry;
use Omnichannel\Addons\Agent\Extension\Contracts\CapabilityContributor;
use Omnichannel\Addons\Agent\Extension\Contracts\PipelineStepDriver;
use Omnichannel\Addons\Agent\Extension\Contracts\WorkflowContributor;
use Omnichannel\Addons\Media\Extension\Contracts\MediaProcessorDriver;
use Omnichannel\Addons\Media\Extension\Registry\MediaProcessorRegistry;
use Omnichannel\Addons\Publishing\Extension\Contracts\PublisherDriver;
use Omnichannel\Addons\Seo\Extension\Contracts\SeoProviderDriver;
use Omnichannel\Addons\Seo\Extension\Registry\SeoProviderRegistry;

final class ContentPlatformRegistry
{
    public function __construct(
        private readonly PublisherRegistry $publishers,
        private readonly AiProviderRegistry $aiProviders,
        private readonly SeoProviderRegistry $seoProviders,
        private readonly PipelineRegistry $pipelines,
        private readonly ExtensionCapabilityRegistry $capabilities,
        private readonly PromptHookExtensionRegistry $promptHooks,
        private readonly MediaProcessorRegistry $mediaProcessors,
        private readonly WorkflowExtensionRegistry $workflows,
        private readonly ExtensionRegistry $extensions,
    ) {}

    public function getPublisher(string $id): ?PublisherDriver
    {
        return $this->publishers->get($id);
    }

    public function getAi(string $id): ?AiProviderDriver
    {
        return $this->aiProviders->get($id);
    }

    public function getSeo(string $id): ?SeoProviderDriver
    {
        return $this->seoProviders->get($id);
    }

    public function getPipeline(string $id): ?PipelineStepDriver
    {
        return $this->pipelines->get($id);
    }

    public function getCapabilityContributor(string $id): ?CapabilityContributor
    {
        return $this->capabilities->get($id);
    }

    public function getPromptHookContributor(string $id): ?PromptHookContributor
    {
        return $this->promptHooks->get($id);
    }

    public function getMediaProcessor(string $id): ?MediaProcessorDriver
    {
        return $this->mediaProcessors->get($id);
    }

    public function getWorkflowContributor(string $id): ?WorkflowContributor
    {
        return $this->workflows->get($id);
    }

    public function publishers(): PublisherRegistry
    {
        return $this->publishers;
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

    public function extensions(): ExtensionRegistry
    {
        return $this->extensions;
    }
}
