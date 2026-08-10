<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Filament\Resources\ArticleResource\Pages;

use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Filament\Actions;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

final class ArticleAccessDenied extends Page
{
    protected static string $resource = ArticleResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.article-resource.pages.article-access-denied';

    protected static bool $shouldRegisterNavigation = false;

    public int|string $record;

    public function mount(int|string $record): void
    {
        self::authorizeResourceAccess();

        $this->record = (int) $record;

        if (ArticleResource::canContentManagerAccessArticleId($this->record)) {
            $this->redirect(
                ArticleResource::getUrl('edit', ['record' => $this->record]),
                navigate: true,
            );
        }
    }

    public function getTitle(): string|Htmlable
    {
        return 'Không có quyền truy cập bài viết';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('back_to_articles')
                ->label('Quay lại danh sách bài viết')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(ArticleResource::getUrl('index')),
        ];
    }
}
