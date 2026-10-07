<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Semantic;

/**
 * Scheduled / CLI monitor: probe readiness then report operational state.
 */
final class SemanticServiceHealthMonitor
{
    public function __construct(
        private readonly SemanticServiceHealthProbe $probe,
        private readonly SemanticServiceHealthReporter $reporter,
    ) {}

    public function check(): SemanticServiceHealthSnapshot
    {
        $snapshot = $this->probe->probe();
        $this->reporter->report($snapshot);

        return $snapshot;
    }
}
