<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Filament\Resources\ArticleResource\Pages;

use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Models\Site;
use Filament\Actions;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

class ArticleDomainMismatch extends Page
{
    protected static string $resource = ArticleResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.article-resource.pages.article-domain-mismatch';

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static bool $shouldRegisterNavigation = false;

    public int|string $record;

    public ?SeoArticle $article = null;

    public ?Site $currentSite = null;

    public function mount(int|string $record): void
    {
        static::authorizeResourceAccess();

        $this->record = (int) $record;
        $this->article = ArticleResource::getRecordRouteBindingEloquentQuery()
            ->findOrFail($this->record);

        if (! ArticleResource::canContentManagerAccessArticle($this->article)) {
            $this->redirect(
                ArticleResource::getUrl('access-denied', ['record' => $this->record]),
                navigate: true,
            );

            return;
        }

        $articleSiteId = (int) ($this->article->site_id ?? 0);
        $globalSiteId = SeoAccessControl::globalSiteId();

        if ($globalSiteId === null || $globalSiteId === $articleSiteId) {
            $this->redirect(
                ArticleResource::getUrl('edit', ['record' => $this->article]),
                navigate: true,
            );

            return;
        }

        $this->article->loadMissing('site');
        $this->currentSite = $this->accessibleSitesQuery()->find($globalSiteId);
    }

    public function getTitle(): string|Htmlable
    {
        return __('seo-content-ai::filament.article_list.domain_mismatch_title');
    }

    public function switchDomainAndContinue(): void
    {
        $articleSiteId = (int) ($this->article?->site_id ?? 0);
        if ($articleSiteId <= 0) {
            abort(404);
        }

        SeoAccessControl::setGlobalSiteId($articleSiteId);

        $this->redirect(
            ArticleResource::getUrl('edit', ['record' => $this->article]),
            navigate: true,
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('back_to_articles')
                ->label(__('seo-content-ai::filament.article_list.back_to_articles'))
                ->color('gray')
                ->url(ArticleResource::getUrl('index')),
        ];
    }

    private function accessibleSitesQuery(): Builder
    {
        $query = Site::query();

        if (SeoAccessControl::shouldScopeToAccountOwner()) {
            $query->where('user_id', SeoAccessControl::accountSiteOwnerId());
        }

        return $query;
    }
}
