<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Filament\Resources;



use Omnichannel\Addons\Seo\Filament\Resources\SeoPanelResource;
use Omnichannel\Addons\Seo\Filament\Pages\SeoSettingsOverview;
use Omnichannel\Addons\ContentProjects\Filament\Resources\TaskResource\Pages;
use Omnichannel\Addons\AiPrompt\Models\SeoTask;
use Omnichannel\Addons\AiPrompt\Services\AiModelsReadinessService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TaskResource extends SeoPanelResource
{
    protected static ?string $model = SeoTask::class;

    protected static ?string $slug = 'tasks';

    protected static ?string $navigationIcon = 'heroicon-o-queue-list';

    protected static ?string $navigationGroup = null;

    protected static ?string $navigationLabel = 'Task workflows';

    protected static ?string $modelLabel = 'Workflow';

    protected static ?string $pluralModelLabel = 'Task workflows';

    protected static ?int $navigationSort = 15;

    public static function canViewAny(): bool
    {
        return SeoAccessControl::canAccessPlannerFeatures();
    }

    public static function canCreate(): bool
    {
        return static::allowsSeoPanelMutation()
            && SeoAccessControl::canAccessPlannerFeatures();
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return static::allowsSeoPanelMutation()
            && SeoAccessControl::canAccessPlannerFeatures();
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return static::allowsSeoPanelMutation()
            && SeoAccessControl::canAccessPlannerFeatures();
    }

    public static function getNavigationLabel(): string
    {
        return __('seo-content-ai::filament.nav.task_workflows');
    }

    public static function getModelLabel(): string
    {
        return __('seo-content-ai::filament.nav.workflow');
    }

    public static function getPluralModelLabel(): string
    {
        return __('seo-content-ai::filament.nav.task_workflows');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label(__('seo-content-ai::filament.task.name'))
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),
                Forms\Components\Textarea::make('description')
                    ->label(__('seo-content-ai::filament.task.description'))
                    ->rows(3)
                    ->columnSpanFull(),
                Forms\Components\Toggle::make('is_active')
                    ->label(__('seo-content-ai::filament.task.active'))
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('seo-content-ai::filament.task.name'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label(__('seo-content-ai::filament.task.active'))
                    ->boolean(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label(__('seo-content-ai::filament.task.updated'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->actions([
                Tables\Actions\Action::make('test')
                    ->label(fn (): string => app(AiModelsReadinessService::class)->userHasReadyAiConnection()
                        ? 'Test'
                        : __('seo-content-ai::filament.prompt.sync_model'))
                    ->icon(fn (): string => app(AiModelsReadinessService::class)->userHasReadyAiConnection()
                        ? 'heroicon-o-play'
                        : 'heroicon-o-cpu-chip')
                    ->color(fn (): string => app(AiModelsReadinessService::class)->userHasReadyAiConnection()
                        ? 'success'
                        : 'warning')
                    ->url(fn (SeoTask $record): string => app(AiModelsReadinessService::class)->userHasReadyAiConnection()
                        ? static::getUrl('test', ['record' => $record])
                        : SeoSettingsOverview::getUrl()),
                Tables\Actions\Action::make('open_builder')
                    ->label(__('seo-content-ai::filament.task.open_builder'))
                    ->icon('heroicon-o-squares-2x2')
                    ->color('info')
                    ->url(fn (SeoTask $record): string => static::getUrl('builder', ['record' => $record])),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions(static::seoPanelBulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]));
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (SeoAccessControl::shouldScopeToAccountOwner()) {
            $query->where('user_id', SeoAccessControl::accountSiteOwnerId());
        }

        return $query;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTasks::route('/'),
            'create' => Pages\TaskWorkflowBuilder::route('/create'),
            'edit' => Pages\EditTask::route('/{record}/edit'),
            'builder' => Pages\EditTaskWorkflow::route('/{record}/builder'),
            'test' => Pages\TestTask::route('/{record}/test'),
        ];
    }
}
