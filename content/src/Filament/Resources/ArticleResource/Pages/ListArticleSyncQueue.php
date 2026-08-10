<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Filament\Resources\ArticleResource\Pages;

use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\WordPress\Services\ArticleWpSyncQueueService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

class ListArticleSyncQueue extends ListRecords
{
    protected static string $resource = ArticleResource::class;

    protected static ?string $navigationIcon = 'heroicon-o-queue-list';

    protected static ?string $navigationGroup = null;

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'queue';

    public static function getNavigationLabel(): string
    {
        return __('seo-content-ai::filament.article_list.tab_queue');
    }

    public static function getNavigationParentItem(): ?string
    {
        return __('seo-content-ai::filament.nav.articles');
    }

    public static function canAccess(array $parameters = []): bool
    {
        return ArticleResource::canViewAny();
    }

    public static function shouldRegisterNavigation(array $parameters = []): bool
    {
        return ArticleResource::canViewAny();
    }

    public function getTitle(): string|Htmlable
    {
        return __('seo-content-ai::filament.article_list.queue_page_title');
    }

    public function table(Table $table): Table
    {
        return ArticleResource::queueTable($table)
            ->modifyQueryUsing(function (Builder $query): Builder {
                $query = ArticleResource::applyWpSyncQueueListScope($query);

                return ArticleResource::appendWpSyncQueueMetaSelect($query);
            });
    }

    public function retryArticleSyncQueue(int $articleId): void
    {
        $this->resyncArticleSyncQueue($articleId);
    }

    public function resyncArticleSyncQueue(int $articleId): void
    {
        abort_unless(SeoAccessControl::canSyncArticlesToWordPress(), 403);

        $article = ArticleResource::getEloquentQuery()->whereKey($articleId)->first();
        if (! $article instanceof SeoArticle) {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_list.sync_queue_resync_failed'))
                ->danger()
                ->send();

            return;
        }

        $result = app(\Omnichannel\Addons\WordPress\Services\WordPressManualSyncService::class)
            ->resyncQueued($article, auth()->user(), 'list_sync_queue.resync');

        if (! ($result['success'] ?? false)) {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_list.sync_queue_resync_failed'))
                ->body((string) ($result['message'] ?? ''))
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('seo-content-ai::filament.article_list.sync_queue_resync_queued'))
            ->body((string) ($result['message'] ?? ''))
            ->info()
            ->send();

        $this->resetTable();
    }

    public function cancelArticleSyncQueue(int $articleId): void
    {
        abort_unless(SeoAccessControl::canSyncArticlesToWordPress(), 403);

        $article = ArticleResource::getEloquentQuery()->whereKey($articleId)->first();
        if (! $article instanceof SeoArticle) {
            return;
        }

        if (! app(ArticleWpSyncQueueService::class)->cancel($article)) {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_list.sync_queue_cancel_failed'))
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('seo-content-ai::filament.article_list.sync_queue_cancelled'))
            ->success()
            ->send();

        $this->resetTable();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('backToArticles')
                ->label(__('seo-content-ai::filament.article_list.queue_back_to_articles'))
                ->icon('heroicon-o-arrow-left')
                ->url(ArticleResource::getUrl('index')),
        ];
    }
}
