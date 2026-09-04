<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns;

use Filament\Notifications\Notification;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\AssignKeywordToTopicClusterService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\HideKeywordFromSeoService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterQuery;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\SkipKeywordFromMcpService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\TopicClusterReclusterState;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordPhrasePresentation;
use RuntimeException;

trait InteractsWithKeywordItemActions
{
    public ?int $moveClusterKeywordId = null;

    public string $moveClusterTargetKey = '';

    /** @var array<string, string> */
    public array $moveClusterOptions = [];

    /** idle|loading|ready|error */
    public string $moveClusterModalPhase = 'idle';

    public string $moveClusterModalError = '';

    public function openKeywordLinkedArticles(int $keywordId): void
    {
        if ($keywordId <= 0) {
            return;
        }

        $this->selectedKeywordId = $keywordId;
        $this->dispatch('keyword-detail-open', keywordId: $keywordId);
    }

    public function openKeywordEdit(int $keywordId): void
    {
        if ($keywordId <= 0) {
            return;
        }

        if (method_exists($this, 'mountTableAction')) {
            $this->mountTableAction('edit', (string) $keywordId);

            return;
        }

        Notification::make()
            ->title(__('seo-content-ai::filament.keyword.edit'))
            ->body(__('seo-content-ai::filament.keyword.keyword_item_edit_unavailable'))
            ->warning()
            ->send();
    }

    public function saveKeywordPhraseInline(int $keywordId, string $phrase): string
    {
        $phrase = trim(preg_replace('/\s+/u', ' ', $phrase) ?? $phrase);
        if ($keywordId <= 0 || $phrase === '') {
            return '';
        }

        $keyword = Keyword::query()->find($keywordId);
        if (! $keyword instanceof Keyword || ! KeywordResource::canEdit($keyword)) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.topic_canonical_edit_denied'))
                ->danger()
                ->send();

            return KeywordPhrasePresentation::present((string) ($keyword->phrase ?? ''));
        }

        KeywordResource::saveKeywordFromFormData($keyword, [
            'phrase' => $phrase,
            'type' => (string) $keyword->type,
        ]);

        Notification::make()
            ->title(__('seo-content-ai::filament.keyword.keyword_item_phrase_saved'))
            ->success()
            ->send();

        $this->afterKeywordItemMutation();

        return KeywordPhrasePresentation::present($phrase);
    }

    public function hideKeywordFromSeo(int $keywordId): void
    {
        $keyword = Keyword::query()->find($keywordId);
        if (! $keyword instanceof Keyword || ! KeywordResource::canMutateKeywordVisibility($keyword)) {
            return;
        }

        $siteId = KeywordResource::resolveKeywordSiteId($keyword);
        $result = app(HideKeywordFromSeoService::class)->hide($keywordId, $siteId);

        Notification::make()
            ->title(__('seo-content-ai::filament.keyword.keyword_item_exclude_seo'))
            ->body(__('seo-content-ai::filament.keyword.hide_success_body', [
                'phrase' => $result['phrase'],
            ]))
            ->success()
            ->send();

        $this->afterKeywordItemMutation();
    }

    public function restoreHiddenKeyword(int $keywordId): void
    {
        $keyword = Keyword::query()->find($keywordId);
        if (! $keyword instanceof Keyword || ! KeywordResource::canMutateKeywordVisibility($keyword)) {
            return;
        }

        $siteId = KeywordResource::resolveKeywordSiteId($keyword);
        $result = app(HideKeywordFromSeoService::class)->restore($keywordId, $siteId);

        Notification::make()
            ->title(__('seo-content-ai::filament.keyword.keyword_item_restore_seo'))
            ->body(__('seo-content-ai::filament.keyword.hide_restore_success_body', [
                'phrase' => $result['phrase'],
            ]))
            ->success()
            ->send();

        $this->afterKeywordItemMutation();
    }

    public function skipKeywordFromMcp(int $keywordId): void
    {
        $keyword = Keyword::query()->find($keywordId);
        if (! $keyword instanceof Keyword || ! KeywordResource::canMutateKeywordVisibility($keyword)) {
            return;
        }

        $siteId = KeywordResource::resolveKeywordSiteId($keyword);
        $result = app(SkipKeywordFromMcpService::class)->skip($keywordId, $siteId);

        Notification::make()
            ->title(__('seo-content-ai::filament.keyword.keyword_item_skip_mcp'))
            ->body(__('seo-content-ai::filament.keyword.keyword_item_skip_mcp_body', [
                'phrase' => $result['phrase'],
            ]))
            ->success()
            ->send();

        $this->afterKeywordItemMutation();
    }

    public function restoreKeywordMcp(int $keywordId): void
    {
        $keyword = Keyword::query()->find($keywordId);
        if (! $keyword instanceof Keyword || ! KeywordResource::canMutateKeywordVisibility($keyword)) {
            return;
        }

        $siteId = KeywordResource::resolveKeywordSiteId($keyword);
        $result = app(SkipKeywordFromMcpService::class)->restore($keywordId, $siteId);

        Notification::make()
            ->title(__('seo-content-ai::filament.keyword.keyword_item_restore_mcp'))
            ->body(__('seo-content-ai::filament.keyword.keyword_item_restore_mcp_body', [
                'phrase' => $result['phrase'],
            ]))
            ->success()
            ->send();

        $this->afterKeywordItemMutation();
    }

    public function openMoveClusterModal(int $keywordId): void
    {
        $this->dispatch('open-modal', id: 'keyword-move-cluster-modal');
        $this->prepareMoveClusterModal($keywordId);
    }

    /**
     * Prepare move-cluster options. Prefer opening the Filament modal shell on the client
     * before awaiting this method so the user sees immediate feedback.
     */
    public function prepareMoveClusterModal(int $keywordId): void
    {
        $this->moveClusterModalPhase = 'loading';
        $this->moveClusterModalError = '';
        $this->moveClusterKeywordId = $keywordId > 0 ? $keywordId : null;
        $this->moveClusterTargetKey = '';
        $this->moveClusterOptions = [];

        try {
            $siteId = (int) ($this->resolveKeywordWorkspaceSiteId() ?? 0);
            if (! TopicClusterReclusterState::assertMutationAllowed($siteId)) {
                $this->resetMoveClusterModal();
                $this->dispatch('close-modal', id: 'keyword-move-cluster-modal');

                return;
            }

            if ($keywordId <= 0 || $siteId <= 0) {
                $this->failMoveClusterModalLoad(__('seo-content-ai::filament.keyword.keyword_item_move_cluster_loading_failed'));

                return;
            }

            $this->moveClusterOptions = $this->buildMoveClusterOptions($siteId);
            $this->moveClusterModalPhase = 'ready';
        } catch (\Throwable $e) {
            $this->failMoveClusterModalLoad(
                $e->getMessage() !== ''
                    ? $e->getMessage()
                    : __('seo-content-ai::filament.keyword.keyword_item_move_cluster_loading_failed')
            );
        }
    }

    public function resetMoveClusterModal(): void
    {
        $this->moveClusterKeywordId = null;
        $this->moveClusterTargetKey = '';
        $this->moveClusterOptions = [];
        $this->moveClusterModalPhase = 'idle';
        $this->moveClusterModalError = '';
    }

    public function retryMoveClusterModal(): void
    {
        $keywordId = (int) ($this->moveClusterKeywordId ?? 0);
        if ($keywordId <= 0) {
            return;
        }

        $this->prepareMoveClusterModal($keywordId);
    }

    public function confirmMoveKeywordCluster(): void
    {
        if ($this->moveClusterModalPhase !== 'ready') {
            return;
        }

        $keywordId = (int) ($this->moveClusterKeywordId ?? 0);
        $clusterKey = trim($this->moveClusterTargetKey);
        $siteId = (int) ($this->resolveKeywordWorkspaceSiteId() ?? 0);

        if (! TopicClusterReclusterState::assertMutationAllowed($siteId)) {
            return;
        }

        if ($keywordId <= 0 || $clusterKey === '' || $siteId <= 0) {
            return;
        }

        try {
            app(AssignKeywordToTopicClusterService::class)->assign($siteId, $keywordId, $clusterKey);
        } catch (RuntimeException $e) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.keyword_item_move_cluster_failed'))
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('seo-content-ai::filament.keyword.keyword_item_move_cluster_success'))
            ->success()
            ->send();

        $this->resetMoveClusterModal();
        $this->dispatch('close-modal', id: 'keyword-move-cluster-modal');
        $this->afterKeywordItemMutation();
    }

    private function failMoveClusterModalLoad(string $message): void
    {
        $this->moveClusterModalPhase = 'error';
        $this->moveClusterModalError = $message;
        $this->moveClusterOptions = [];
    }

    /**
     * @return array<string, string>
     */
    protected function buildMoveClusterOptions(int $siteId): array
    {
        $rows = app(KeywordClusterQuery::class)->clusterAggregates($siteId, limit: 500);
        $labels = app(KeywordClusterQuery::class)->canonicalLabelsForKeys(
            $siteId,
            array_values(array_filter(array_map(
                static fn (object $row): string => trim((string) ($row->cluster_key ?? '')),
                $rows,
            ))),
        );

        $options = [];
        foreach ($rows as $row) {
            $key = trim((string) ($row->cluster_key ?? ''));
            if ($key === '') {
                continue;
            }
            $label = $labels[$key] ?? app(KeywordClusterQuery::class)->displayLabel(
                $key,
                (string) ($row->sample_phrase ?? ''),
                $siteId,
            );
            $options[$key] = KeywordPhrasePresentation::present($label);
        }

        foreach (app(KeywordClusterQuery::class)->paginateClusters($siteId, [], perPage: 500)->items() as $item) {
            if (! is_array($item)) {
                continue;
            }
            $key = trim((string) ($item['cluster_key'] ?? ''));
            if ($key === '' || isset($options[$key])) {
                continue;
            }
            $options[$key] = KeywordPhrasePresentation::present((string) ($item['label'] ?? $key));
        }

        asort($options);

        return $options;
    }

    protected function afterKeywordItemMutation(): void
    {
        if (method_exists($this, 'flushCachedTableRecords')) {
            $this->flushCachedTableRecords();
        }

        if (property_exists($this, 'clusterDataEpoch')) {
            $this->clusterDataEpoch++;
        }
    }
}
