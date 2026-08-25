<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use RuntimeException;

/**
 * Write-time capacity session: recount from DB under row lock, then fill source
 * and exact monthly continuations. Never trusts a caller-supplied item count.
 */
final class ContentProjectAllocationSession
{
    /** @var array<int, int> projectId => occupied planned items (DB + claimed this session) */
    private array $occupied = [];

    /** @var array<int, array{project_id:int, month:string, added:int}> */
    private array $allocations = [];

    /** @var array<int, SeoProject> */
    private array $projects = [];

    private int $virtualSeq = 0;

    public function __construct(
        private readonly SeoProject $source,
        private readonly ContentProjectContinuationService $continuation,
        private readonly bool $dryRun = false,
    ) {
        $locked = $this->lockProject($source);
        $this->cursor = $locked;
        $this->remember($locked, $this->recountPlanned($locked));
    }

    private SeoProject $cursor;

    public function projectWithRemainingCapacity(): ?SeoProject
    {
        for ($hop = 0; $hop < ContentProjectContinuationService::MAX_MONTH_HOPS; $hop++) {
            $remaining = $this->remainingOn($this->cursor);
            if ($remaining > 0) {
                return $this->cursor;
            }

            $nextMonth = $this->continuation->nextMonth($this->cursor->monthCarbon());
            $this->cursor = $this->advanceToMonth($nextMonth);
        }

        return null;
    }

    public function occupiedCount(SeoProject $project): int
    {
        $key = $this->key($project);

        return $this->occupied[$key] ?? $this->recountPlanned($project);
    }

    public function recordAdded(SeoProject $project): void
    {
        $key = $this->key($project);
        $this->occupied[$key] = ($this->occupied[$key] ?? $this->recountPlanned($project)) + 1;
        $month = $project->monthCarbon()->format('m/Y');

        if (! isset($this->allocations[$key])) {
            $this->allocations[$key] = [
                'project_id' => (int) ($project->getKey() ?: 0),
                'month' => $month,
                'added' => 0,
            ];
        }

        $this->allocations[$key]['added']++;
        $this->projects[$key] = $project;
    }

    /**
     * @return list<array{project_id:int, month:string, added:int}>
     */
    public function allocations(): array
    {
        return array_values(array_filter(
            $this->allocations,
            static fn (array $row): bool => (int) ($row['added'] ?? 0) > 0,
        ));
    }

    /**
     * @return list<SeoProject>
     */
    public function touchedProjects(): array
    {
        return array_values(array_filter(
            $this->projects,
            static fn (SeoProject $project): bool => (int) $project->getKey() > 0,
        ));
    }

    public function syncTouchedCounters(): void
    {
        if ($this->dryRun) {
            return;
        }

        foreach ($this->touchedProjects() as $project) {
            $project->syncTotalTasksCounter();
        }
    }

    private function advanceToMonth(\Carbon\Carbon $month): SeoProject
    {
        if ($this->dryRun) {
            $existing = $this->continuation->findInChainForMonth($this->source, $month, lock: true);
            if ($existing instanceof SeoProject) {
                $locked = $this->lockProject($existing);
                $this->remember($locked, $this->occupied[$this->key($locked)] ?? $this->recountPlanned($locked));

                return $locked;
            }

            return $this->virtualContinuation($month);
        }

        $created = $this->continuation->findOrCreateContinuation($this->source, $month);
        $locked = $this->lockProject($created);
        $this->remember($locked, $this->occupied[$this->key($locked)] ?? $this->recountPlanned($locked));

        return $locked;
    }

    private function virtualContinuation(\Carbon\Carbon $month): SeoProject
    {
        $this->virtualSeq++;
        $virtual = new SeoProject();
        $virtual->forceFill($this->continuation->continuationAttributes($this->source, $month));
        $virtual->setAttribute('id', -1 * $this->virtualSeq);
        $this->remember($virtual, 0);

        return $virtual;
    }

    private function remainingOn(SeoProject $project): int
    {
        if ($project->isArchive()) {
            return 0;
        }

        // Month is reporting period only — no day-based hard capacity.
        return PHP_INT_MAX;
    }

    private function lockProject(SeoProject $project): SeoProject
    {
        $id = (int) $project->getKey();
        if ($id <= 0) {
            return $project;
        }

        /** @var SeoProject|null $locked */
        $locked = SeoProject::query()
            ->whereKey($id)
            ->lockForUpdate()
            ->first();

        if (! $locked instanceof SeoProject) {
            throw new RuntimeException('Content project disappeared during allocation.');
        }

        return $locked;
    }

    private function recountPlanned(SeoProject $project): int
    {
        if ((int) $project->getKey() <= 0) {
            return 0;
        }

        $project->unsetRelation('tasks');

        return $project->registeredTaskCount();
    }

    private function remember(SeoProject $project, int $occupied): void
    {
        $key = $this->key($project);
        $this->occupied[$key] = $occupied;
        $this->projects[$key] = $project;
    }

    private function key(SeoProject $project): int
    {
        $id = (int) $project->getKey();

        return $id !== 0 ? $id : -1 * ($this->virtualSeq ?: 1);
    }
}
