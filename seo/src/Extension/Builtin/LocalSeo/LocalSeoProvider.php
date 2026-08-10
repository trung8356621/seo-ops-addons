<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Extension\Builtin\LocalSeo;

use Omnichannel\Addons\AiPrompt\Extension\Contracts\Ai\AiProviderHealthResult;
use Omnichannel\Addons\Seo\Extension\Contracts\SeoProviderInterface;

/**
 * Built-in local SEO provider — boundary only, no Ahrefs/GSC/Semrush methods.
 */
final class LocalSeoProvider implements SeoProviderInterface
{
    public function key(): string
    {
        return 'local-seo';
    }

    public function health(): AiProviderHealthResult
    {
        return AiProviderHealthResult::healthy('Local SEO provider ready (internal capabilities only).');
    }

    /**
     * @return list<string>
     */
    public function capabilities(): array
    {
        return [
            'seo.local.health',
            'seo.local.audit_placeholder',
        ];
    }
}
