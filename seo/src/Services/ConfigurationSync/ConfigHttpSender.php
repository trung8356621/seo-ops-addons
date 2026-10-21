<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\ConfigurationSync;

interface ConfigHttpSender
{
    /**
     * @param  array<string, string>  $headers
     */
    public function send(string $method, string $url, array $headers, string $body): ConfigHttpResult;
}
