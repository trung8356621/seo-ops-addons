<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\ConfigurationSync;

final class ConfigHttpResult
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers,
    ) {}

    public function header(string $name): string
    {
        $target = strtolower($name);
        foreach ($this->headers as $key => $value) {
            if (strtolower((string) $key) === $target) {
                return trim((string) $value);
            }
        }

        return '';
    }
}
