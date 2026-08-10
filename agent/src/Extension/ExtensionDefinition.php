<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Extension;

final class ExtensionDefinition
{
    /**
     * @param  array<string, mixed>  $health
     */
    public function __construct(
        public readonly ExtensionManifest $manifest,
        public readonly string $path,
        public readonly string $providerClass,
        public string $status = 'healthy',
        public array $health = [],
    ) {}
}
