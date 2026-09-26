<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Filament\Resources\ArticleResource\Pages;

use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ListArticlesTrash extends ListRecords
{
    protected static string $resource = ArticleResource::class;

    public static function getNavigationLabel(): string
    {
        return __('Trash');
    }


    public function getTitle(): string
    {
        return __('Article trash');
    }


    protected static bool $shouldRegisterNavigation = false;

    protected function getTableQuery(): Builder
    {
        return parent::getTableQuery()->onlyTrashed();
    }

    public function table(Table $table): Table
    {
        return ArticleResource::table($table)
            ->recordAction(null)
            ->actions(SeoAccessControl::canMutateInSeoPanel() ? [
                Tables\Actions\RestoreAction::make()
                    ->iconButton(),
                Tables\Actions\ForceDeleteAction::make()
                    ->iconButton(),
            ] : [])
            ->bulkActions(SeoAccessControl::canMutateInSeoPanel() ? [
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\RestoreBulkAction::make(),
                    Tables\Actions\ForceDeleteBulkAction::make(),
                ]),
            ] : []);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('emptyTrash')
                ->label(__('Empty'))
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->visible(fn (): bool => SeoAccessControl::canMutateInSeoPanel())
                ->requiresConfirmation()
                ->modalHeading(__('Empty trash'))
                ->modalDescription(__('Permanently delete all articles in trash. This cannot be undone.'))
                ->modalSubmitActionLabel(__('Delete all'))
                ->disabled(fn (): bool => ! $this->getTableQuery()->exists())
                ->action(function (): void {
                    $query = $this->getTableQuery();
                    $count = (clone $query)->count();

                    $query->cursor()->each(static function ($article): void {
                        $article->forceDelete();
                    });

                    Notification::make()
                        ->title($count > 0
                            ? "Permanently deleted {$count} articles"
                            : 'Trash is already empty')
                        ->success()
                        ->send();
                }),
            Actions\Action::make('backToList')
                ->label(__('Article list'))
                ->icon('heroicon-o-arrow-left')
                ->url(ArticleResource::getUrl('index')),
        ];
    }
}
