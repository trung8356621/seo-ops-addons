<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Filament\Resources;

use Omnichannel\Addons\Seo\Filament\Resources\SeoPanelResource;
use Omnichannel\Addons\Content\Filament\Resources\TagResource\Pages;
use Omnichannel\Addons\SearchFoundation\Models\Tag;
use Omnichannel\Addons\SearchFoundation\Services\TagPersistenceService;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class TagResource extends SeoPanelResource
{
    protected static ?string $model = Tag::class;

    protected static ?string $slug = 'keywords/tags';

    protected static ?string $navigationLabel = 'Tags';

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationGroup = null;

    protected static ?string $navigationParentItem = 'Keyword Intelligence';

    protected static ?int $navigationSort = 4;

    public static function canViewAny(): bool
    {
        return SeoAccessControl::canAccessPlannerFeatures();
    }

    public static function canCreate(): bool
    {
        return static::allowsSeoPanelMutation()
            && SeoAccessControl::canAccessPlannerFeatures();
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return static::allowsSeoPanelMutation()
            && $record instanceof Tag
            && SeoAccessControl::canAccessPlannerFeatures()
            && ! $record->keywordsUsingTagExist();
    }

    public static function getNavigationLabel(): string
    {
        return __('seo-content-ai::filament.nav.tags');
    }

    public static function getModelLabel(): string
    {
        return __('seo-content-ai::filament.nav.tag');
    }

    public static function getPluralModelLabel(): string
    {
        return __('seo-content-ai::filament.nav.tags');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label(__('seo-content-ai::filament.keyword.tag_name'))
                    ->required()
                    ->maxLength(255)
                    ->rule(function (): \Closure {
                        return function (string $attribute, mixed $value, \Closure $fail): void {
                            if (app(TagPersistenceService::class)->nameExists((string) $value)) {
                                $fail(__('seo-content-ai::filament.tag.name_unique'));
                            }
                        };
                    }),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('seo-content-ai::filament.keyword.tag_name'))
                    ->searchable()
                    ->sortable()
                    ->url(fn (Tag $record): string => KeywordResource::buildIncludeTagFilterUrl((int) $record->id))
                    ->color('primary')
                    ->tooltip(__('seo-content-ai::filament.tag.filter_keywords')),

                Tables\Columns\TextColumn::make('slug')
                    ->label(__('seo-content-ai::filament.tag.slug'))
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('keywords_usage_count')
                    ->label(__('seo-content-ai::filament.tag.keywords_count'))
                    ->getStateUsing(fn (Tag $record): int => $record->keywordsUsageCount())
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderByRaw(
                            '(select count(*) from keyword_meta km where km.meta_key = ? and JSON_CONTAINS(km.meta_value, CAST(keyword_tags.id AS JSON), "$")) '.$direction,
                            [\Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey::Tags->value],
                        );
                    })
                    ->alignCenter()
                    ->badge()
                    ->color('info')
                    ->url(fn (Tag $record): string => KeywordResource::buildIncludeTagFilterUrl((int) $record->id))
                    ->tooltip(__('seo-content-ai::filament.tag.filter_keywords')),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label(__('seo-content-ai::filament.tag.updated'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->filters([])
            ->actions([
                Tables\Actions\Action::make('filter_keywords')
                    ->label(__('seo-content-ai::filament.tag.filter_keywords'))
                    ->icon('heroicon-o-funnel')
                    ->url(fn (Tag $record): string => KeywordResource::buildIncludeTagFilterUrl((int) $record->id)),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (Tag $record): bool => static::canDelete($record)),
            ])
            ->bulkActions([]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTags::route('/'),
            'create' => Pages\CreateTag::route('/create'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function createTagFromFormData(array $data): Tag
    {
        try {
            return app(TagPersistenceService::class)->create(
                (string) ($data['name'] ?? ''),
            );
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'name' => $exception->getMessage(),
            ]);
        }
    }
}
