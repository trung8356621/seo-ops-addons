<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns;

use Filament\Notifications\Notification;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\HideKeywordFromSeoService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\SkipKeywordFromMcpService;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordPhrasePresentation;

trait InteractsWithKeywordItemActions
{
    public function openKeywordLinkedArticles(int $keywordId): void
    {
        $this->openKeywordDetail($keywordId);
    }

    /**
     * Open Keyword Detail drawer (Focus Article section is the primary identity context).
     * Distinct from linked-list navigation naming — same drawer shell.
     */
    public function openKeywordDetail(int $keywordId): void
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

    protected function afterKeywordItemMutation(): void
    {
        if (method_exists($this, 'flushCachedTableRecords')) {
            $this->flushCachedTableRecords();
        }
    }
}
