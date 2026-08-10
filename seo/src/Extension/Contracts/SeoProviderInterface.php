<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Extension\Contracts;

use Omnichannel\Addons\AiPrompt\Extension\Contracts\Ai\AiProviderHealthResult;

/**
 * Real SEO data-provider boundary (on-page/local audits, rank tracking, backlinks, ...).
 * Extensions declare what they can actually do via capabilities() — no placeholder
 * method should claim support for a data source (e.g. Ahrefs) that isn't implemented.
 */
interface SeoProviderInterface
{
    /**
     * Registry key, e.g. "local-seo", "ahrefs".
     */
    public function key(): string;

    public function health(): AiProviderHealthResult;

    /**
     * @return list<string>
     */
    public function capabilities(): array;
}
