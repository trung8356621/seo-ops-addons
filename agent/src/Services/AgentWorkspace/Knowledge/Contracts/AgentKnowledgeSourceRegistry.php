<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Contracts;

interface AgentKnowledgeSourceRegistry
{
    /**
     * @return list<string>
     */
    public function supportedSources(): array;

    public function supports(string $sourceType): bool;

    /**
     * @param  array<string, mixed>  $payload
     * @return array{title: string, content: string, metadata: array<string, mixed>}
     */
    public function extract(string $sourceType, array $payload): array;
}
