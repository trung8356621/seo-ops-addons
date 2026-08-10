<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Services;

use Omnichannel\Addons\Agent\Jobs\RunAgentAutomationJob;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Contracts\AgentAutomationRepository;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Contracts\AgentAutomationScheduleResolver;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Finds due automations, claims occurrences, dispatches jobs.
 * Does not execute workflow business logic.
 */
final class AgentAutomationDispatcher
{
    public function __construct(
        private readonly AgentAutomationRepository $repository,
        private readonly AgentAutomationLockService $locks,
        private readonly AgentAutomationScheduleResolver $schedules,
    ) {}

    /**
     * @return array{claimed: int, dispatched: int, skipped: int}
     */
    public function dispatchDue(int $limit = 100): array
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $due = $this->repository->findDue($now, $limit);
        $claimed = 0;
        $dispatched = 0;
        $skipped = 0;

        foreach ($due as $automation) {
            $scheduledAt = $automation->next_run_at !== null
                ? DateTimeImmutable::createFromInterface($automation->next_run_at)->setTimezone(new DateTimeZone('UTC'))
                : $now;
            $scheduledKey = $scheduledAt->format('Y-m-d\TH:i:00\Z');
            $occurrenceKey = $this->locks->occurrenceKey((int) $automation->id, $scheduledKey);

            $existing = null;
            foreach ($this->repository->listRuns((int) $automation->id, 5) as $run) {
                if ((string) $run->occurrence_key === $occurrenceKey) {
                    $existing = $run;
                    break;
                }
            }
            if ($existing !== null) {
                $skipped++;
                $this->advanceNext($automation);
                continue;
            }

            $run = $this->repository->claimOccurrence(
                $automation,
                $occurrenceKey,
                $scheduledAt,
                'schedule',
                'queued',
            );
            $claimed++;

            // If claim returned pre-existing (unique race), skip re-dispatch when already terminal/running
            if ((string) $run->occurrence_key === $occurrenceKey && in_array((string) $run->status, ['running', 'succeeded', 'no_change', 'skipped'], true)
                && (int) $run->id !== 0) {
                // newly created is queued — dispatch
            }

            if ((string) $run->status === 'queued' && (int) $run->attempt === 1
                || ((string) $run->status === 'queued')) {
                RunAgentAutomationJob::dispatch((int) $run->id);
                $dispatched++;
            } else {
                $skipped++;
            }

            $this->advanceNext($automation);
        }

        return [
            'claimed' => $claimed,
            'dispatched' => $dispatched,
            'skipped' => $skipped,
        ];
    }

    private function advanceNext(\Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentAutomation $automation): void
    {
        $trigger = is_array($automation->trigger_json) ? $automation->trigger_json : [];
        $resolved = $this->schedules->resolve($trigger);
        if (($resolved['ok'] ?? false) && isset($resolved['next_run_at'])) {
            $this->repository->updateAutomation($automation, [
                'next_run_at' => $resolved['next_run_at'],
            ]);
        }
    }
}
