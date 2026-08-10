<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Packs;

/**
 * Loads pack definitions into registry (isolate failures).
 */
final class AgentPackLoader
{
    public function __construct(
        private readonly AgentPackRegistry $registry,
        private readonly AgentPackDiscoveryService $discovery,
    ) {}

    /**
     * @return array{loaded: int, failed: int}
     */
    public function loadEnabled(): array
    {
        $this->discovery->discover();
        $this->registry->invalidate();
        $skills = $this->registry->enabledSkillDefinitions();

        return ['loaded' => count($skills), 'failed' => 0];
    }
}
