<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Concerns;

use Filament\Notifications\Notification;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNoteDnaNormalizer;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNoteTargetAllocator;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\DiscoverNewTopicsService;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\DnaPlacement;
use Throwable;

/**
 * SEO Audit — New Topics tab (temporary AI candidates; no Topic DB / article writes).
 */
trait InteractsWithDiscoverNewTopics
{
    /** existing|new */
    public string $auditNotesTab = 'existing';

    /** @var list<array{candidate_key: string, name: string, target_dna_count: int, dna: list<string>}> */
    public array $newTopicCandidates = [];

    /** @var list<array<string, mixed>> */
    public array $newTopicSelectedItems = [];

    public bool $newTopicsGenerating = false;

    public string $newTopicsLastError = '';

    public function mountInteractsWithDiscoverNewTopics(): void
    {
        $this->auditNotesTab = 'existing';
        $this->newTopicCandidates = [];
        $this->newTopicSelectedItems = [];
        $this->newTopicsGenerating = false;
        $this->newTopicsLastError = '';
    }

    public function setAuditNotesTab(string $tab): void
    {
        $tab = strtolower(trim($tab));
        $this->auditNotesTab = in_array($tab, ['existing', 'new'], true) ? $tab : 'existing';
    }

    public function discoverNewTopics(): void
    {
        if ($this->newTopicsGenerating) {
            return;
        }

        $siteId = method_exists($this, 'resolveAuditNotesSiteId')
            ? (int) $this->resolveAuditNotesSiteId()
            : 0;
        if ($siteId <= 0) {
            Notification::make()
                ->title((string) __('seo-content-ai::filament.projects.new_topics_need_site'))
                ->danger()
                ->send();

            return;
        }

        $this->newTopicsGenerating = true;
        $this->newTopicsLastError = '';
        $previousSelected = $this->newTopicSelectedItems;

        try {
            $actorId = auth()->id();
            $result = app(DiscoverNewTopicsService::class)->discover(
                $siteId,
                is_numeric($actorId) ? (int) $actorId : null,
            );
            if (! $result['ok']) {
                $this->newTopicsLastError = (string) $result['message'];
                $this->newTopicSelectedItems = $previousSelected;
                Notification::make()
                    ->title((string) __('seo-content-ai::filament.projects.new_topics_failed'))
                    ->body($this->newTopicsLastError)
                    ->danger()
                    ->send();

                return;
            }

            $this->newTopicCandidates = $result['topics'];
            $this->newTopicSelectedItems = $previousSelected;
            $this->auditNotesTab = 'new';

            Notification::make()
                ->title((string) __('seo-content-ai::filament.projects.new_topics_ready', [
                    'count' => count($result['topics']),
                ]))
                ->success()
                ->send();
        } catch (Throwable $e) {
            $this->newTopicsLastError = $e->getMessage();
            $this->newTopicSelectedItems = $previousSelected;
            Notification::make()
                ->title((string) __('seo-content-ai::filament.projects.new_topics_failed'))
                ->body($this->newTopicsLastError)
                ->danger()
                ->send();
        } finally {
            $this->newTopicsGenerating = false;
        }
    }

    public function removeNewTopicSelected(string $clusterRef): void
    {
        $clusterRef = trim($clusterRef);
        $this->newTopicSelectedItems = array_values(array_filter(
            $this->newTopicSelectedItems,
            static fn (array $item): bool => (string) ($item['cluster_ref'] ?? '') !== $clusterRef,
        ));
        $this->purgeGeneratedDraftIdeasForClusterRef($clusterRef);
    }

    public function toggleNewTopicCandidate(string $candidateKey): void
    {
        $candidateKey = trim($candidateKey);
        if ($candidateKey === '') {
            return;
        }

        $ref = AuditNoteDnaNormalizer::generatedRef($candidateKey);
        foreach ($this->newTopicSelectedItems as $index => $item) {
            if ((string) ($item['cluster_ref'] ?? '') === $ref
                || (string) ($item['candidate_key'] ?? '') === $candidateKey
            ) {
                unset($this->newTopicSelectedItems[$index]);
                $this->newTopicSelectedItems = array_values($this->newTopicSelectedItems);
                $this->purgeGeneratedDraftIdeasForClusterRef($ref);

                return;
            }
        }

        $candidate = null;
        foreach ($this->newTopicCandidates as $row) {
            if ((string) ($row['candidate_key'] ?? '') === $candidateKey) {
                $candidate = $row;
                break;
            }
        }
        if ($candidate === null) {
            return;
        }

        $item = AuditNoteDnaNormalizer::noteItemFromGeneratedCandidate($candidate);
        if ($item === null) {
            return;
        }

        $this->newTopicSelectedItems[] = $item;
    }

    public function clearNewTopicSelected(): void
    {
        $refs = [];
        foreach ($this->newTopicSelectedItems as $item) {
            $ref = trim((string) ($item['cluster_ref'] ?? ''));
            if ($ref !== '') {
                $refs[] = $ref;
            }
        }
        $this->newTopicSelectedItems = [];
        foreach ($refs as $ref) {
            $this->purgeGeneratedDraftIdeasForClusterRef($ref);
        }
    }

    /**
     * Clear temporary Livewire generated state after successful final materialization.
     *
     * @param  list<string>  $candidateKeys
     */
    public function consumeGeneratedTopicsAfterMaterialize(array $candidateKeys): void
    {
        $keys = [];
        foreach ($candidateKeys as $key) {
            $key = trim((string) $key);
            if ($key !== '') {
                $keys[$key] = true;
            }
        }
        if ($keys === []) {
            return;
        }

        $this->newTopicSelectedItems = array_values(array_filter(
            $this->newTopicSelectedItems,
            static function (array $item) use ($keys): bool {
                $key = (string) ($item['candidate_key'] ?? AuditNoteDnaNormalizer::generatedCandidateKey((string) ($item['cluster_ref'] ?? '')));

                return $key === '' || ! isset($keys[$key]);
            },
        ));
        $this->newTopicCandidates = array_values(array_filter(
            $this->newTopicCandidates,
            static fn (array $row): bool => ! isset($keys[(string) ($row['candidate_key'] ?? '')]),
        ));
    }

    /**
     * Removing a generated candidate must not leave orphan Draft ideas.
     */
    protected function purgeGeneratedDraftIdeasForClusterRef(string $clusterRef): void
    {
        $clusterRef = trim($clusterRef);
        if ($clusterRef === '' || ! AuditNoteDnaNormalizer::isGeneratedRef($clusterRef)) {
            return;
        }
        if (! method_exists($this, 'resolveNewContentProject')) {
            return;
        }
        $project = $this->resolveNewContentProject();
        if (! $project instanceof \Omnichannel\Addons\ContentProjects\Models\SeoProject) {
            return;
        }

        $taskIds = \Omnichannel\Addons\ContentProjects\Models\SeoProjectTask::query()
            ->where('project_id', (int) $project->getKey())
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        if ($taskIds === []) {
            return;
        }

        $attributedTaskIds = \Omnichannel\Addons\ContentProjects\Models\SeoContentProjectTaskPlanningAttribution::query()
            ->whereIn('project_task_id', $taskIds)
            ->where('cluster_ref', $clusterRef)
            ->pluck('project_task_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        if ($attributedTaskIds === []) {
            return;
        }

        \Omnichannel\Addons\ContentProjects\Models\SeoContentProjectTaskPlanningAttribution::query()
            ->whereIn('project_task_id', $attributedTaskIds)
            ->delete();
        \Omnichannel\Addons\ContentProjects\Models\SeoContentProjectItemOrigin::query()
            ->whereIn('project_task_id', $attributedTaskIds)
            ->delete();
        \Omnichannel\Addons\ContentProjects\Models\SeoProjectTask::query()
            ->whereIn('id', $attributedTaskIds)
            ->delete();
    }

    public function updateNewTopicName(string $clusterRef, string $name): void
    {
        $name = AuditNoteDnaNormalizer::displayPhrase($name);
        if ($name === '') {
            return;
        }
        foreach ($this->newTopicSelectedItems as $index => $item) {
            if ((string) ($item['cluster_ref'] ?? '') !== $clusterRef) {
                continue;
            }
            $item['cluster_name_snapshot'] = $name;
            $normalized = AuditNoteDnaNormalizer::normalizeNoteItem($item);
            if ($normalized !== null) {
                $this->newTopicSelectedItems[$index] = $normalized;
            }

            return;
        }
    }

    public function updateNewTopicTargetDna(string $clusterRef, int $target): void
    {
        foreach ($this->newTopicSelectedItems as $index => $item) {
            if ((string) ($item['cluster_ref'] ?? '') !== $clusterRef) {
                continue;
            }
            $item['target_dna_count'] = $target;
            $item['target_mode'] = AuditNoteTargetAllocator::TARGET_MODE_MANUAL;
            $normalized = AuditNoteDnaNormalizer::normalizeNoteItem($item);
            if ($normalized !== null) {
                $this->newTopicSelectedItems[$index] = $normalized;
            }

            return;
        }
    }

    public function addNewTopicDna(string $clusterRef, string $phrase): void
    {
        $phrase = AuditNoteDnaNormalizer::displayPhrase($phrase);
        if ($phrase === '') {
            return;
        }
        foreach ($this->newTopicSelectedItems as $index => $item) {
            if ((string) ($item['cluster_ref'] ?? '') !== $clusterRef) {
                continue;
            }
            $dna = is_array($item['dna'] ?? null) ? $item['dna'] : [];
            $dna[] = [
                'phrase' => $phrase,
                'slots' => AuditNoteDnaNormalizer::DEFAULT_SLOTS,
                'source' => AuditNoteDnaNormalizer::SOURCE_MANUAL,
                'placement' => DnaPlacement::DEFAULT,
            ];
            $item['dna'] = $dna;
            $normalized = AuditNoteDnaNormalizer::normalizeNoteItem($item);
            if ($normalized !== null) {
                $this->newTopicSelectedItems[$index] = $normalized;
            }

            return;
        }
    }

    public function removeNewTopicDna(string $clusterRef, int $dnaIndex): void
    {
        foreach ($this->newTopicSelectedItems as $index => $item) {
            if ((string) ($item['cluster_ref'] ?? '') !== $clusterRef) {
                continue;
            }
            $dna = is_array($item['dna'] ?? null) ? $item['dna'] : [];
            if (! isset($dna[$dnaIndex])) {
                return;
            }
            unset($dna[$dnaIndex]);
            $item['dna'] = array_values($dna);
            $normalized = AuditNoteDnaNormalizer::normalizeNoteItem($item);
            if ($normalized !== null) {
                $this->newTopicSelectedItems[$index] = $normalized;
            }

            return;
        }
    }

    /**
     * @return array{
     *   tab: string,
     *   generating: bool,
     *   error: string,
     *   candidates: list<array<string, mixed>>,
     *   selected: list<array<string, mixed>>,
     *   selected_keys: list<string>
     * }
     */
    public function getDiscoverNewTopicsPayloadProperty(): array
    {
        $selectedKeys = [];
        foreach ($this->newTopicSelectedItems as $item) {
            $key = (string) ($item['candidate_key'] ?? AuditNoteDnaNormalizer::generatedCandidateKey((string) ($item['cluster_ref'] ?? '')));
            if ($key !== '') {
                $selectedKeys[] = $key;
            }
        }

        return [
            'tab' => $this->auditNotesTab,
            'generating' => $this->newTopicsGenerating,
            'error' => $this->newTopicsLastError,
            'candidates' => $this->newTopicCandidates,
            'selected' => $this->newTopicSelectedItems,
            'selected_keys' => $selectedKeys,
        ];
    }
}
