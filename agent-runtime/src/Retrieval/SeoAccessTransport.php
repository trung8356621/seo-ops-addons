<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Retrieval;

interface SeoAccessTransport
{
    /**
     * @param  array<string, scalar>  $query
     * @param  array<string, mixed>|null  $jsonBody
     * @return array{status: int, json: array<string, mixed>}
     */
    public function request(string $method, string $url, array $query = [], ?string $bearer = null, ?array $jsonBody = null): array;
}
