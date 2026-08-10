<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Extension\Builtin\LocalSeo;

use Omnichannel\Addons\Seo\Extension\Contracts\SeoProviderDriver;

/**
 * Legacy `SeoProviderDriver` adapter so ExtensionHealthService (which keys drivers by
 * extension id) can report health for the "local-seo" builtin extension.
 */
final class LocalSeoHealthDriver implements SeoProviderDriver
{
    public function __construct(
        private readonly LocalSeoProvider $provider,
    ) {}

    public function id(): string
    {
        return 'local-seo';
    }

    public function label(): string
    {
        return 'Local SEO Provider';
    }

    /**
     * @return list<string>
     */
    public function capabilities(): array
    {
        return $this->provider->capabilities();
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function health(): array
    {
        return $this->provider->health()->toArray();
    }
}
