<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Concerns;

use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNoteClusterSuggestionQuery;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNoteDnaNormalizer;
use Livewire\WithPagination;

/**
 * SEO Audit Notes — Cluster suggestions (MCP share ASC) + per-item editable DNA snapshots.
 * Suggestion list is cached; DNA edits do not re-query Cluster suggestions.
 *
 * @mixin WithPagination
 */
trait InteractsWithAuditNotes
{
    /** @var list<array{cluster_ref: string, cluster_name_snapshot: string, mcp_share_snapshot: float, dna: list<array{phrase: string, weight: int, source: string}>}> */
    public array $auditNoteItems = [];

    public string $auditNoteSearch = '';

    public string $auditNoteSearchInput = '';

    /** all|mcp_low|has_focus|no_focus */
    public string $auditNoteFilter = 'all';

    public string $auditNoteDnaPhrase = '';

    public string $auditNoteDnaWeight = '';

    /** Manual selected-note topic name (replaces free-text planner notes). */
    public string $auditNoteManualTopic = '';

    /** @var list<array<string, mixed>> */
    public array $auditNoteSuggestionRows = [];

    public int $auditNoteSuggestionTotal = 0;

    public bool $auditNoteSuggestionsReady = false;

    public bool $auditNoteSuggestionsLoading = false;

    public function mountInteractsWithAuditNotes(): void
    {
        $this->auditNoteItems = [];
        $this->auditNoteSearch = '';
        $this->auditNoteSearchInput = '';
        $this->auditNoteFilter = 'all';
        $this->auditNoteDnaPhrase = '';
        $this->auditNoteDnaWeight = '';
        $this->auditNoteManualTopic = '';
        $this->auditNoteSuggestionRows = [];
        $this->auditNoteSuggestionTotal = 0;
        $this->auditNoteSuggestionsReady = false;
        $this->auditNoteSuggestionsLoading = false;
        $this->resetPage('auditNotesPage');
    }

    public function loadAuditNoteSuggestions(): void
    {
        $this->refreshAuditNoteSuggestions();
    }

    public function applyAuditNoteSearch(): void
    {
        $this->auditNoteSearch = trim($this->auditNoteSearchInput);
        $this->auditNoteSearchInput = $this->auditNoteSearch;
        $this->resetPage('auditNotesPage');
        $this->refreshAuditNoteSuggestions();
    }

    public function clearAuditNoteSearch(): void
    {
        $this->auditNoteSearch = '';
        $this->auditNoteSearchInput = '';
        $this->resetPage('auditNotesPage');
        $this->refreshAuditNoteSuggestions();
    }

    public function updatedAuditNoteFilter(): void
    {
        $this->resetPage('auditNotesPage');
        $this->refreshAuditNoteSuggestions();
    }

    public function updatedAuditNotesPage(): void
    {
        $this->refreshAuditNoteSuggestions();
    }

    protected function refreshAuditNoteSuggestions(): void
    {
        $siteId = $this->resolveAuditNotesSiteId();
        $this->auditNoteSuggestionsLoading = true;

        if ($siteId <= 0) {
            $this->auditNoteSuggestionRows = [];
            $this->auditNoteSuggestionTotal = 0;
            $this->auditNoteSuggestionsReady = true;
            $this->auditNoteSuggestionsLoading = false;

            return;
        }

        $page = method_exists($this, 'getPage')
            ? max(1, (int) $this->getPage('auditNotesPage'))
            : 1;

        $result = app(AuditNoteClusterSuggestionQuery::class)->paginate($siteId, [
            'search' => $this->auditNoteSearch,
            'filter' => $this->auditNoteFilter,
            'page' => $page,
        ]);

        $this->auditNoteSuggestionRows = $result['rows'];
        $this->auditNoteSuggestionTotal = (int) $result['total'];
        $this->auditNoteSuggestionsReady = true;
        $this->auditNoteSuggestionsLoading = false;
    }

    protected function resolveAuditNotesSiteId(): int
    {
        $project = $this->resolveAuditNotesProject();
        if ($project instanceof SeoProject) {
            return (int) ($project->site_id ?? 0);
        }
        if (property_exists($this, 'filterSiteId')) {
            return (int) ($this->filterSiteId ?? 0);
        }

        return 0;
    }

    protected function resolveAuditNotesProject(): ?SeoProject
    {
        if (method_exists($this, 'resolveNewContentProject')) {
            /** @var callable $resolver */
            $resolver = [$this, 'resolveNewContentProject'];
            $project = $resolver();

            return $project instanceof SeoProject ? $project : null;
        }

        return property_exists($this, 'project') && $this->project instanceof SeoProject
            ? $this->project
            : null;
    }

    /**
     * Lightweight UI payload — no Cluster query (uses cached suggestion rows).
     *
     * @return array{
     *   can_write: bool,
     *   total: int,
     *   filter: string,
     *   search: string,
     *   selected_refs: list<string>,
     *   selected_items: list<array<string, mixed>>,
     *   rows: list<array<string, mixed>>,
     *   ready: bool,
     *   loading: bool,
     *   has_pages: bool,
     *   current_page: int,
     *   last_page: int
     * }
     */
    public function getAuditNotesPayloadProperty(): array
    {
        $siteId = $this->resolveAuditNotesSiteId();
        $selectedRefs = array_values(array_map(
            static fn (array $item): string => (string) ($item['cluster_ref'] ?? ''),
            $this->auditNoteItems,
        ));
        $perPage = AuditNoteClusterSuggestionQuery::PER_PAGE;
        $currentPage = method_exists($this, 'getPage')
            ? max(1, (int) $this->getPage('auditNotesPage'))
            : 1;
        $lastPage = max(1, (int) ceil($this->auditNoteSuggestionTotal / max(1, $perPage)));

        return [
            'can_write' => $siteId > 0,
            'total' => $this->auditNoteSuggestionTotal,
            'filter' => $this->auditNoteFilter,
            'search' => $this->auditNoteSearch,
            'selected_refs' => $selectedRefs,
            'selected_items' => $this->auditNoteItems,
            'rows' => $this->auditNoteSuggestionRows,
            'ready' => $this->auditNoteSuggestionsReady,
            'loading' => $this->auditNoteSuggestionsLoading,
            'has_pages' => $this->auditNoteSuggestionTotal > $perPage,
            'current_page' => $currentPage,
            'last_page' => $lastPage,
        ];
    }

    public function toggleAuditNoteCluster(string $clusterRef): void
    {
        $clusterRef = trim($clusterRef);
        if ($clusterRef === '') {
            return;
        }

        foreach ($this->auditNoteItems as $index => $item) {
            if (($item['cluster_ref'] ?? '') === $clusterRef) {
                unset($this->auditNoteItems[$index]);
                $this->auditNoteItems = array_values($this->auditNoteItems);

                return;
            }
        }

        $siteId = $this->resolveAuditNotesSiteId();
        $suggestion = app(AuditNoteClusterSuggestionQuery::class)->findSuggestion($siteId, $clusterRef);
        if ($suggestion === null) {
            return;
        }

        $item = AuditNoteDnaNormalizer::normalizeNoteItem([
            'cluster_ref' => $suggestion['cluster_ref'],
            'cluster_name_snapshot' => $suggestion['cluster_name'],
            'mcp_share_snapshot' => $suggestion['mcp_share'],
            'dna' => AuditNoteDnaNormalizer::snapshotFromClusterDna(
                is_array($suggestion['cluster_dna'] ?? null) ? $suggestion['cluster_dna'] : [],
            ),
        ]);
        if ($item === null) {
            return;
        }

        $this->auditNoteItems[] = $item;
    }

    public function addManualAuditNoteTopic(): void
    {
        $name = AuditNoteDnaNormalizer::displayPhrase($this->auditNoteManualTopic);
        if ($name === '') {
            return;
        }

        $ref = AuditNoteDnaNormalizer::manualRef($name);
        foreach ($this->auditNoteItems as $item) {
            if (($item['cluster_ref'] ?? '') === $ref) {
                $this->auditNoteManualTopic = '';

                return;
            }
        }

        $item = AuditNoteDnaNormalizer::normalizeNoteItem([
            'cluster_ref' => $ref,
            'cluster_name_snapshot' => $name,
            'mcp_share_snapshot' => 0.0,
            'dna' => [
                [
                    'phrase' => $name,
                    'weight' => AuditNoteDnaNormalizer::DEFAULT_WEIGHT,
                    'source' => AuditNoteDnaNormalizer::SOURCE_MANUAL,
                ],
            ],
        ]);
        if ($item === null) {
            return;
        }

        $this->auditNoteItems[] = $item;
        $this->auditNoteManualTopic = '';
    }

    public function removeAuditNoteItem(string $clusterRef): void
    {
        $clusterRef = trim($clusterRef);
        $this->auditNoteItems = array_values(array_filter(
            $this->auditNoteItems,
            static fn (array $item): bool => ($item['cluster_ref'] ?? '') !== $clusterRef,
        ));
    }

    public function addAuditNoteDna(string $clusterRef): void
    {
        $clusterRef = trim($clusterRef);
        $phrase = trim($this->auditNoteDnaPhrase);
        if ($clusterRef === '' || $phrase === '') {
            return;
        }

        $weightRaw = trim($this->auditNoteDnaWeight);
        $weight = $weightRaw === '' ? AuditNoteDnaNormalizer::DEFAULT_WEIGHT : (int) $weightRaw;
        if ($weight < 1) {
            $weight = AuditNoteDnaNormalizer::DEFAULT_WEIGHT;
        }

        foreach ($this->auditNoteItems as $index => $item) {
            if (($item['cluster_ref'] ?? '') !== $clusterRef) {
                continue;
            }
            $dna = is_array($item['dna'] ?? null) ? $item['dna'] : [];
            $this->auditNoteItems[$index]['dna'] = AuditNoteDnaNormalizer::addDna(
                $dna,
                $phrase,
                $weight,
                AuditNoteDnaNormalizer::SOURCE_MANUAL,
            );
            $this->auditNoteDnaPhrase = '';
            $this->auditNoteDnaWeight = '';

            return;
        }
    }

    public function removeAuditNoteDna(string $clusterRef, string $phrase): void
    {
        $clusterRef = trim($clusterRef);
        foreach ($this->auditNoteItems as $index => $item) {
            if (($item['cluster_ref'] ?? '') !== $clusterRef) {
                continue;
            }
            $dna = is_array($item['dna'] ?? null) ? $item['dna'] : [];
            $this->auditNoteItems[$index]['dna'] = AuditNoteDnaNormalizer::removeDna($dna, $phrase);

            return;
        }
    }

    public function gotoAuditNotesPage(int $page): void
    {
        if (method_exists($this, 'setPage')) {
            $this->setPage(max(1, $page), 'auditNotesPage');
        }
        $this->refreshAuditNoteSuggestions();
    }

    /**
     * @return list<array{cluster_ref: string, cluster_name_snapshot: string, mcp_share_snapshot: float, dna: list<array{phrase: string, weight: int, source: string}>}>
     */
    protected function auditNoteItemsForOptions(): array
    {
        return AuditNoteDnaNormalizer::normalizeNoteItems($this->auditNoteItems);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    protected function applyAuditNoteItems(array $items): void
    {
        $this->auditNoteItems = AuditNoteDnaNormalizer::normalizeNoteItems($items);
    }
}
