<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\DataTransfer;

final readonly class ResolvedAiProviderConnection
{
    public function __construct(
        public NormalizedAiProviderTemplate $template,
        public string $effectiveBaseUrl,
        public string $source,
        public bool $overrideApplied,
    ) {}

    public function effectiveTemplate(): NormalizedAiProviderTemplate
    {
        return $this->template->withBaseUrl($this->effectiveBaseUrl);
    }
}
