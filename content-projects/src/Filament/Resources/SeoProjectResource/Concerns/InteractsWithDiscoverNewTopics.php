<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Concerns;

use Filament\Notifications\Notification;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNoteDnaNormalizer;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNoteTargetAllocator;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\DiscoverNewTopicsService;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\DnaPlacement;
use Omnichannel\Addons\Seo\Support\DomainContextResolver;
use Throwable;

/**
 * SEO Audit — New Topics tab (temporary AI candidates; no Topic DB / article writes).
 *
 * Canonical temporary authoring state: newTopicSelectedItems (aka newTopicItems).
 * newTopicCandidates is kept as an internal mirror for PR4 / payload compatibility.
 */
trait InteractsWithDiscoverNewTopics
{
    public const NEW_TOPICS_STATE_NOT_RUN = 'not_run';

    public const NEW_TOPICS_STATE_LOADING = 'loading';

    public const NEW_TOPICS_STATE_COMPLETED = 'completed';

    public const NEW_TOPICS_STATE_FAILED = 'failed';

    public const NEW_TOPICS_STORAGE_SCHEMA = 1;

    /** existing|new */
    public string $auditNotesTab = 'existing';

    /**
     * Internal mirror of generated batch (candidate_key + raw dna strings).
     *
     * @var list<array{candidate_key: string, name: string, target_dna_count: int, dna: list<string>}>
     */
    public array $newTopicCandidates = [];

    /**
     * Canonical temporary authoring list (all generated topics are editable here).
     *
     * @var list<array<string, mixed>>
     */
    public array $newTopicSelectedItems = [];

    public bool $newTopicsGenerating = false;

    /** not_run|loading|completed|failed */
    public string $newTopicsGenerationState = self::NEW_TOPICS_STATE_NOT_RUN;

    public string $newTopicsLastError = '';

    public function mountInteractsWithDiscoverNewTopics(): void
    {
        $this->auditNotesTab = 'existing';
        $this->newTopicCandidates = [];
        $this->newTopicSelectedItems = [];
        $this->newTopicsGenerating = false;
        $this->newTopicsGenerationState = self::NEW_TOPICS_STATE_NOT_RUN;
        $this->newTopicsLastError = '';
    }

    public function setAuditNotesTab(string $tab): void
    {
        $tab = strtolower(trim($tab));
        if ($tab === 'new' && $this->newTopicsGenerationState !== self::NEW_TOPICS_STATE_COMPLETED) {
            $this->auditNotesTab = 'existing';

            return;
        }
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

        if (property_exists($this, 'auditNoteSuggestionsReady')
            && property_exists($this, 'auditNoteSuggestionTotal')
            && (bool) $this->auditNoteSuggestionsReady
            && (int) $this->auditNoteSuggestionTotal <= 0
        ) {
            Notification::make()
                ->title((string) __('seo-content-ai::filament.projects.new_topics_requires_topics'))
                ->warning()
                ->send();

            return;
        }

        if (property_exists($this, 'auditNoteSuggestionsReady')
            && property_exists($this, 'auditNoteSuggestionsLoading')
            && (! (bool) $this->auditNoteSuggestionsReady || (bool) $this->auditNoteSuggestionsLoading)
        ) {
            return;
        }

        $previousItems = $this->newTopicSelectedItems;
        $previousCandidates = $this->newTopicCandidates;
        $hadCompletedBatch = $this->newTopicsGenerationState === self::NEW_TOPICS_STATE_COMPLETED
            || $previousItems !== []
            || $previousCandidates !== [];

        $this->newTopicsGenerating = true;
        $this->newTopicsGenerationState = self::NEW_TOPICS_STATE_LOADING;
        $this->newTopicsLastError = '';

        try {
            $actorId = auth()->id();
            $project = method_exists($this, 'resolveNewContentProject')
                ? $this->resolveNewContentProject()
                : null;
            $result = app(DiscoverNewTopicsService::class)->discover(
                $siteId,
                is_numeric($actorId) ? (int) $actorId : null,
                DiscoverNewTopicsService::DEFAULT_COUNT,
                $project instanceof \Omnichannel\Addons\ContentProjects\Models\SeoProject ? $project : null,
            );
            if (! $result['ok']) {
                $this->newTopicsLastError = (string) $result['message'];
                $this->newTopicsGenerationState = $hadCompletedBatch
                    ? self::NEW_TOPICS_STATE_COMPLETED
                    : self::NEW_TOPICS_STATE_FAILED;
                $this->newTopicSelectedItems = $previousItems;
                $this->newTopicCandidates = $previousCandidates;
                if (! $hadCompletedBatch) {
                    $this->auditNotesTab = 'existing';
                }
                Notification::make()
                    ->title((string) __('seo-content-ai::filament.projects.new_topics_failed'))
                    ->body($this->newTopicsLastError)
                    ->danger()
                    ->send();

                return;
            }

            $this->applyGeneratedTopicBatch($result['topics']);
            $this->newTopicsGenerationState = self::NEW_TOPICS_STATE_COMPLETED;
            $this->auditNotesTab = 'new';
            $this->dispatchNewTopicsPersist();

            Notification::make()
                ->title((string) __('seo-content-ai::filament.projects.new_topics_ready', [
                    'count' => count($result['topics']),
                ]))
                ->success()
                ->send();
        } catch (Throwable $e) {
            $this->newTopicsLastError = $e->getMessage();
            $this->newTopicsGenerationState = $hadCompletedBatch
                ? self::NEW_TOPICS_STATE_COMPLETED
                : self::NEW_TOPICS_STATE_FAILED;
            $this->newTopicSelectedItems = $previousItems;
            $this->newTopicCandidates = $previousCandidates;
            if (! $hadCompletedBatch) {
                $this->auditNotesTab = 'existing';
            }
            Notification::make()
                ->title((string) __('seo-content-ai::filament.projects.new_topics_failed'))
                ->body($this->newTopicsLastError)
                ->danger()
                ->send();
        } finally {
            $this->newTopicsGenerating = false;
            if ($this->newTopicsGenerationState === self::NEW_TOPICS_STATE_LOADING) {
                $this->newTopicsGenerationState = $hadCompletedBatch
                    ? self::NEW_TOPICS_STATE_COMPLETED
                    : self::NEW_TOPICS_STATE_FAILED;
            }
        }
    }

    /**
     * Hydrate temporary authoring state from scoped LocalStorage (untrusted).
     *
     * @param  array<string, mixed>  $payload
     */
    public function restoreDiscoverNewTopicsFromStorage(array $payload): void
    {
        if ($this->newTopicsGenerating) {
            return;
        }
        // Livewire already has a completed batch — do not clobber newer server state.
        if ($this->newTopicsGenerationState === self::NEW_TOPICS_STATE_COMPLETED
            && ($this->newTopicSelectedItems !== [] || $this->newTopicCandidates !== [])
        ) {
            return;
        }

        $schema = (int) ($payload['schema_version'] ?? 0);
        if ($schema !== self::NEW_TOPICS_STORAGE_SCHEMA) {
            return;
        }

        $state = strtolower(trim((string) ($payload['generation_state'] ?? '')));
        if ($state !== self::NEW_TOPICS_STATE_COMPLETED) {
            return;
        }

        $siteId = method_exists($this, 'resolveAuditNotesSiteId')
            ? (int) $this->resolveAuditNotesSiteId()
            : 0;
        $payloadSite = (int) ($payload['site_id'] ?? 0);
        if ($siteId > 0 && $payloadSite > 0 && $payloadSite !== $siteId) {
            return;
        }

        $projectId = $this->resolveDiscoverProjectId();
        $payloadProject = (int) ($payload['project_id'] ?? 0);
        if ($projectId > 0 && $payloadProject > 0 && $payloadProject !== $projectId) {
            return;
        }

        $rawItems = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        $items = [];
        foreach ($rawItems as $row) {
            if (! is_array($row)) {
                continue;
            }
            $normalized = $this->normalizeStorageItem($row);
            if ($normalized !== null) {
                $items[] = $normalized;
            }
        }

        $this->newTopicSelectedItems = $items;
        $this->newTopicCandidates = $this->candidatesFromSelectedItems($items);
        $this->newTopicsGenerationState = self::NEW_TOPICS_STATE_COMPLETED;
        $this->newTopicsLastError = '';
        if ($items !== [] || $state === self::NEW_TOPICS_STATE_COMPLETED) {
            $this->auditNotesTab = $items === [] ? 'new' : $this->auditNotesTab;
            if ($this->auditNotesTab !== 'new' && $items !== []) {
                // Keep existing tab unless user already on new; still show New Topics tab.
            }
        }
    }

    public function keywordsTopicsUrlForAuditSite(): string
    {
        $siteId = method_exists($this, 'resolveAuditNotesSiteId')
            ? (int) $this->resolveAuditNotesSiteId()
            : 0;
        $url = KeywordResource::getUrl('clusters');
        if ($siteId <= 0) {
            return $url;
        }

        return app(DomainContextResolver::class)->appendSiteToUrl($url, $siteId);
    }

    public function removeNewTopicSelected(string $clusterRef): void
    {
        $clusterRef = trim($clusterRef);
        $this->newTopicSelectedItems = array_values(array_filter(
            $this->newTopicSelectedItems,
            static fn (array $item): bool => (string) ($item['cluster_ref'] ?? '') !== $clusterRef,
        ));
        $this->syncCandidatesFromSelected();
        $this->purgeGeneratedDraftIdeasForClusterRef($clusterRef);
        if ($this->newTopicSelectedItems === []) {
            // Completed zero-results / emptied list remains a completed generation.
            $this->newTopicsGenerationState = self::NEW_TOPICS_STATE_COMPLETED;
        }
        $this->dispatchNewTopicsPersist();
    }

    /**
     * @deprecated Checkbox select stage removed — all generated topics are editable by default.
     */
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
                $this->syncCandidatesFromSelected();
                $this->purgeGeneratedDraftIdeasForClusterRef($ref);
                $this->dispatchNewTopicsPersist();

                return;
            }
        }
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
        $this->newTopicCandidates = [];
        foreach ($refs as $ref) {
            $this->purgeGeneratedDraftIdeasForClusterRef($ref);
        }
        $this->newTopicsGenerationState = self::NEW_TOPICS_STATE_COMPLETED;
        $this->dispatchNewTopicsPersist();
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
        $this->syncCandidatesFromSelected();

        if ($this->newTopicSelectedItems === []) {
            $this->newTopicsGenerationState = self::NEW_TOPICS_STATE_NOT_RUN;
            $this->auditNotesTab = 'existing';
            $siteId = method_exists($this, 'resolveAuditNotesSiteId')
                ? (int) $this->resolveAuditNotesSiteId()
                : 0;
            $projectId = $this->resolveDiscoverProjectId();
            $this->js(
                'window.cpNewTopicsStorage && window.cpNewTopicsStorage.clear('
                .(int) $siteId.','
                .(int) $projectId.')'
            );
        } else {
            $this->dispatchNewTopicsPersist();
        }
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
            $this->syncCandidatesFromSelected();
            $this->dispatchNewTopicsPersist();

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
            $this->syncCandidatesFromSelected();
            $this->dispatchNewTopicsPersist();

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
            $this->syncCandidatesFromSelected();
            $this->dispatchNewTopicsPersist();

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
            $this->syncCandidatesFromSelected();
            $this->dispatchNewTopicsPersist();

            return;
        }
    }

    /**
     * @return array{
     *   tab: string,
     *   generating: bool,
     *   generation_state: string,
     *   show_new_tab: bool,
     *   error: string,
     *   candidates: list<array<string, mixed>>,
     *   selected: list<array<string, mixed>>,
     *   selected_keys: list<string>,
     *   topics_url: string,
     *   site_id: int,
     *   project_id: int,
     *   storage_schema: int
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

        $state = $this->newTopicsGenerationState;
        if ($this->newTopicsGenerating) {
            $state = self::NEW_TOPICS_STATE_LOADING;
        }

        $siteId = method_exists($this, 'resolveAuditNotesSiteId')
            ? (int) $this->resolveAuditNotesSiteId()
            : 0;

        return [
            'tab' => $this->auditNotesTab,
            'generating' => $this->newTopicsGenerating,
            'generation_state' => $state,
            'show_new_tab' => $state === self::NEW_TOPICS_STATE_COMPLETED,
            'error' => $this->newTopicsLastError,
            'candidates' => $this->newTopicCandidates,
            'selected' => $this->newTopicSelectedItems,
            'selected_keys' => $selectedKeys,
            'topics_url' => $this->keywordsTopicsUrlForAuditSite(),
            'site_id' => $siteId,
            'project_id' => $this->resolveDiscoverProjectId(),
            'storage_schema' => self::NEW_TOPICS_STORAGE_SCHEMA,
        ];
    }

    /**
     * @param  list<array{candidate_key: string, name: string, target_dna_count: int, dna: list<string>}>  $topics
     */
    protected function applyGeneratedTopicBatch(array $topics): void
    {
        $items = [];
        foreach ($topics as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }
            $item = AuditNoteDnaNormalizer::noteItemFromGeneratedCandidate($candidate);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        $this->newTopicSelectedItems = $items;
        $this->newTopicCandidates = $topics;
    }

    protected function syncCandidatesFromSelected(): void
    {
        $this->newTopicCandidates = $this->candidatesFromSelectedItems($this->newTopicSelectedItems);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array{candidate_key: string, name: string, target_dna_count: int, dna: list<string>}>
     */
    protected function candidatesFromSelectedItems(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $key = (string) ($item['candidate_key'] ?? AuditNoteDnaNormalizer::generatedCandidateKey((string) ($item['cluster_ref'] ?? '')));
            if ($key === '') {
                continue;
            }
            $dna = [];
            foreach (is_array($item['dna'] ?? null) ? $item['dna'] : [] as $row) {
                if (is_string($row)) {
                    $phrase = AuditNoteDnaNormalizer::displayPhrase($row);
                } else {
                    $phrase = AuditNoteDnaNormalizer::displayPhrase((string) ($row['phrase'] ?? ''));
                }
                if ($phrase !== '') {
                    $dna[] = $phrase;
                }
            }
            $out[] = [
                'candidate_key' => $key,
                'name' => (string) ($item['cluster_name_snapshot'] ?? ''),
                'target_dna_count' => (int) ($item['target_dna_count'] ?? AuditNoteDnaNormalizer::DEFAULT_TARGET_DNA_COUNT),
                'dna' => $dna,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    protected function normalizeStorageItem(array $row): ?array
    {
        $candidateKey = trim((string) ($row['candidate_key'] ?? ''));
        if ($candidateKey === '' && isset($row['cluster_ref'])) {
            $candidateKey = AuditNoteDnaNormalizer::generatedCandidateKey((string) $row['cluster_ref']);
        }
        if ($candidateKey === '') {
            return null;
        }

        $dnaPhrases = [];
        foreach (is_array($row['dna'] ?? null) ? $row['dna'] : [] as $dnaRow) {
            if (is_string($dnaRow)) {
                $phrase = AuditNoteDnaNormalizer::displayPhrase($dnaRow);
            } else {
                $phrase = AuditNoteDnaNormalizer::displayPhrase((string) (($dnaRow['phrase'] ?? '') ?: ''));
            }
            if ($phrase !== '') {
                $dnaPhrases[] = $phrase;
            }
        }

        return AuditNoteDnaNormalizer::noteItemFromGeneratedCandidate([
            'candidate_key' => $candidateKey,
            'name' => (string) ($row['name'] ?? $row['cluster_name_snapshot'] ?? ''),
            'target_dna_count' => (int) ($row['target_dna_count'] ?? AuditNoteDnaNormalizer::DEFAULT_TARGET_DNA_COUNT),
            'dna' => $dnaPhrases,
        ]);
    }

    protected function resolveDiscoverProjectId(): int
    {
        if (method_exists($this, 'resolveNewContentProject')) {
            $project = $this->resolveNewContentProject();
            if ($project instanceof \Omnichannel\Addons\ContentProjects\Models\SeoProject) {
                return (int) $project->getKey();
            }
        }
        if (property_exists($this, 'projectId') && is_numeric($this->projectId)) {
            return (int) $this->projectId;
        }

        return 0;
    }

    protected function dispatchNewTopicsPersist(): void
    {
        $siteId = method_exists($this, 'resolveAuditNotesSiteId')
            ? (int) $this->resolveAuditNotesSiteId()
            : 0;
        $items = [];
        foreach ($this->newTopicSelectedItems as $item) {
            $key = (string) ($item['candidate_key'] ?? AuditNoteDnaNormalizer::generatedCandidateKey((string) ($item['cluster_ref'] ?? '')));
            if ($key === '') {
                continue;
            }
            $dna = [];
            foreach (is_array($item['dna'] ?? null) ? $item['dna'] : [] as $row) {
                $phrase = is_string($row)
                    ? AuditNoteDnaNormalizer::displayPhrase($row)
                    : AuditNoteDnaNormalizer::displayPhrase((string) ($row['phrase'] ?? ''));
                if ($phrase !== '') {
                    $dna[] = $phrase;
                }
            }
            $items[] = [
                'candidate_key' => $key,
                'source_type' => 'generated',
                'name' => (string) ($item['cluster_name_snapshot'] ?? ''),
                'target_dna_count' => (int) ($item['target_dna_count'] ?? AuditNoteDnaNormalizer::DEFAULT_TARGET_DNA_COUNT),
                'dna' => $dna,
            ];
        }

        $payload = [
            'schema_version' => self::NEW_TOPICS_STORAGE_SCHEMA,
            'generation_state' => $this->newTopicsGenerationState,
            'updated_at' => now()->toIso8601String(),
            'site_id' => $siteId,
            'project_id' => $this->resolveDiscoverProjectId(),
            'items' => $items,
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($json)) {
            return;
        }
        $this->js('window.cpNewTopicsStorage && window.cpNewTopicsStorage.write('.$json.')');
    }
}
