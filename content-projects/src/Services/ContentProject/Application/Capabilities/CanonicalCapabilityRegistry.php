<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Capabilities;

/**
 * Canonical capability surface for the Agent Gateway/MCP — core content_project.* only.
 */
final class CanonicalCapabilityRegistry
{
    public function __construct(
        private readonly ContentProjectCapabilityRegistry $core,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->core->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $name): ?array
    {
        return $this->core->get($name);
    }

    /**
     * @return list<array{name: string, sources: list<string>}>
     */
    public function conflicts(): array
    {
        return [];
    }

    public function isAgentWriteExposed(string $name): bool
    {
        return $this->core->isAgentWriteExposed($name);
    }

    public function isMcpWriteExposed(string $name): bool
    {
        return $this->core->isMcpWriteExposed($name);
    }
}
