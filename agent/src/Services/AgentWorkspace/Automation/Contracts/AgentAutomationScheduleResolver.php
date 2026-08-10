<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Contracts;

use DateTimeImmutable;

interface AgentAutomationScheduleResolver
{
    /**
     * @param  array<string, mixed>  $trigger
     * @return array{
     *     ok: bool,
     *     normalized?: array<string, mixed>,
     *     next_run_at?: ?string,
     *     preview_occurrences?: list<string>,
     *     warnings?: list<string>,
     *     errors?: list<string>
     * }
     */
    public function resolve(array $trigger, ?DateTimeImmutable $fromUtc = null): array;

    /**
     * Minimum allowed interval in minutes.
     */
    public function minimumIntervalMinutes(): int;
}
