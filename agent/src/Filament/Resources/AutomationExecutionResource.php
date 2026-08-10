<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Filament\Resources;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationExecution;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRule;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\BusinessEvent;
use Omnichannel\Addons\Seo\Filament\Concerns\BelongsToAdminAutomationPanel;
use Omnichannel\Addons\Agent\Filament\Resources\AutomationExecutionResource\Pages;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AutomationExecutionResource extends Resource
{
    use BelongsToAdminAutomationPanel;

    protected static ?string $model = AutomationExecution::class;

    protected static ?string $slug = 'automation-executions';

    protected static ?string $navigationLabel = null;

    protected static ?string $navigationIcon = 'heroicon-o-play-circle';

    protected static ?int $navigationSort = 2;

    /**
     * @param  array<string, mixed>  $parameters
     */
    public static function getUrl(
        string $name = 'index',
        array $parameters = [],
        bool $isAbsolute = true,
        ?string $panel = null,
        ?Model $tenant = null,
    ): string {
        return parent::getUrl(
            $name,
            $parameters,
            $isAbsolute,
            $panel ?? self::adminPanelId(),
            $tenant,
        );
    }

    public static function getNavigationLabel(): string
    {
        return __('seo-content-ai::filament.automation.nav_executions');
    }

    public static function canViewAny(): bool
    {
        return SeoAccessControl::canViewAutomation();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getModelLabel(): string
    {
        return __('seo-content-ai::filament.automation.execution');
    }

    public static function getPluralModelLabel(): string
    {
        return __('seo-content-ai::filament.automation.executions');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make(__('seo-content-ai::filament.automation.execution'))
                    ->schema([
                        Infolists\Components\TextEntry::make('execution_uuid')->copyable(),
                        Infolists\Components\TextEntry::make('status')
                            ->label(__('seo-content-ai::filament.automation.status'))
                            ->badge(),
                        Infolists\Components\TextEntry::make('trigger_type')
                            ->label(__('seo-content-ai::filament.automation.trigger_type'))
                            ->badge(),
                        Infolists\Components\TextEntry::make('action_code')
                            ->label(__('seo-content-ai::filament.automation.action_code'))
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('initiated_by_user_id')
                            ->label(__('seo-content-ai::filament.automation.initiated_by'))
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('initiated_from')
                            ->label(__('seo-content-ai::filament.automation.initiated_from'))
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('attempt')
                            ->label(__('seo-content-ai::filament.automation.attempt')),
                        Infolists\Components\TextEntry::make('rule_version')
                            ->label(__('seo-content-ai::filament.automation.version')),
                        Infolists\Components\TextEntry::make('rule.name')
                            ->label(__('seo-content-ai::filament.automation.rule'))
                            ->url(fn (AutomationExecution $record): ?string => $record->rule instanceof AutomationRule
                                ? AutomationRuleResource::getUrl('view', ['record' => $record->rule])
                                : null),
                        Infolists\Components\TextEntry::make('rule.code')
                            ->label(__('seo-content-ai::filament.automation.rule').' code')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('businessEvent.event_name')
                            ->label(__('seo-content-ai::filament.automation.event')),
                        Infolists\Components\TextEntry::make('subject_display')
                            ->label(__('seo-content-ai::filament.automation.subject'))
                            ->state(fn (AutomationExecution $record): string => self::formatSubject($record)),
                        Infolists\Components\TextEntry::make('businessEvent.event_uuid')->label('Event UUID')->copyable(),
                        Infolists\Components\TextEntry::make('error_code')
                            ->label(__('seo-content-ai::filament.automation.error_code'))
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('error_message')
                            ->columnSpanFull()
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('started_at')
                            ->label(__('seo-content-ai::filament.automation.started_at'))
                            ->dateTime('d/m/Y H:i:s'),
                        Infolists\Components\TextEntry::make('finished_at')
                            ->label(__('seo-content-ai::filament.automation.finished_at'))
                            ->dateTime('d/m/Y H:i:s'),
                        Infolists\Components\TextEntry::make('duration_display')
                            ->label(__('seo-content-ai::filament.automation.duration'))
                            ->state(fn (AutomationExecution $record): string => self::formatDuration($record)),
                        Infolists\Components\TextEntry::make('context_json')
                            ->label('Context')
                            ->state(fn (AutomationExecution $record): string => self::jsonPreview($record->context))
                            ->markdown()
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Infolists\Components\Section::make(__('seo-content-ai::filament.automation.actions'))
                    ->schema([
                        Infolists\Components\RepeatableEntry::make('actionExecutions')
                            ->schema([
                                Infolists\Components\TextEntry::make('position'),
                                Infolists\Components\TextEntry::make('action_code'),
                                Infolists\Components\TextEntry::make('status')
                                    ->label(__('seo-content-ai::filament.automation.status'))
                                    ->badge(),
                                Infolists\Components\TextEntry::make('error_code')
                                    ->label(__('seo-content-ai::filament.automation.error_code'))
                                    ->placeholder('—'),
                                Infolists\Components\TextEntry::make('error_message')
                                    ->columnSpanFull()
                                    ->placeholder('—'),
                                Infolists\Components\TextEntry::make('input_snapshot_json')
                                    ->label('Input snapshot')
                                    ->state(fn ($record): string => self::jsonPreview($record->input_snapshot ?? null))
                                    ->markdown()
                                    ->columnSpanFull(),
                                Infolists\Components\TextEntry::make('output_snapshot_json')
                                    ->label('Output snapshot')
                                    ->state(fn ($record): string => self::jsonPreview($record->output_snapshot ?? null))
                                    ->markdown()
                                    ->columnSpanFull(),
                            ])
                            ->columns(3),
                    ]),
                Infolists\Components\Section::make('Node executions')
                    ->schema([
                        Infolists\Components\RepeatableEntry::make('nodeExecutions')
                            ->schema([
                                Infolists\Components\TextEntry::make('node_key'),
                                Infolists\Components\TextEntry::make('node_type'),
                                Infolists\Components\TextEntry::make('status')->badge(),
                                Infolists\Components\TextEntry::make('attempt'),
                                Infolists\Components\TextEntry::make('selected_branch')->placeholder('—'),
                                Infolists\Components\TextEntry::make('error_code')->placeholder('—'),
                                Infolists\Components\TextEntry::make('error_message')->columnSpanFull()->placeholder('—'),
                                Infolists\Components\TextEntry::make('duration_node')
                                    ->label('Duration')
                                    ->state(fn ($record): string => $record->started_at && $record->finished_at
                                        ? $record->started_at->diffInSeconds($record->finished_at).'s'
                                        : '—'),
                            ])
                            ->columns(3),
                    ]),
            ]);
    }

    public static function formatSubject(AutomationExecution $record): string
    {
        $event = $record->businessEvent;
        if (! $event instanceof BusinessEvent) {
            return '—';
        }

        $type = trim((string) ($event->subject_type ?? ''));
        $id = $event->subject_id;

        if ($type === '' && $id === null) {
            return '—';
        }

        if ($type === '') {
            return (string) $id;
        }

        if ($id === null) {
            return $type;
        }

        return $type.'#'.$id;
    }

    public static function formatDuration(AutomationExecution $record): string
    {
        if ($record->started_at === null || $record->finished_at === null) {
            return '—';
        }

        $seconds = $record->started_at->diffInSeconds($record->finished_at);

        return $seconds.'s';
    }

    /**
     * @param  mixed  $value
     */
    public static function jsonPreview(mixed $value): string
    {
        if ($value === null || $value === [] || $value === '') {
            return '—';
        }

        if (is_string($value)) {
            return '```json'."\n".$value."\n```";
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return '```json'."\n".($encoded !== false ? $encoded : '—')."\n```";
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('execution_uuid')
                    ->label('UUID')
                    ->searchable()
                    ->copyable()
                    ->limit(12),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('seo-content-ai::filament.automation.status'))
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'failed' => 'danger',
                        'partial' => 'warning',
                        'processing' => 'info',
                        'completed' => 'success',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('trigger_type')
                    ->label(__('seo-content-ai::filament.automation.trigger_type'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'manual' => __('seo-content-ai::filament.automation.trigger_manual'),
                        'schedule' => __('seo-content-ai::filament.automation.trigger_schedule'),
                        'system' => __('seo-content-ai::filament.automation.trigger_system'),
                        default => __('seo-content-ai::filament.automation.trigger_event'),
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('action_code')
                    ->label(__('seo-content-ai::filament.automation.action_code'))
                    ->placeholder(fn (AutomationExecution $record): string => (string) ($record->rule?->code ?? '—'))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('initiated_by_user_id')
                    ->label(__('seo-content-ai::filament.automation.initiated_by'))
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('initiated_from')
                    ->label(__('seo-content-ai::filament.automation.initiated_from'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('attempt')
                    ->label(__('seo-content-ai::filament.automation.attempt'))
                    ->alignCenter()
                    ->sortable(),
                Tables\Columns\TextColumn::make('rule.code')
                    ->label(__('seo-content-ai::filament.automation.filters_rule'))
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('businessEvent.event_name')
                    ->label(__('seo-content-ai::filament.automation.event'))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('error_code')
                    ->label(__('seo-content-ai::filament.automation.error_code'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('started_at')
                    ->label(__('seo-content-ai::filament.automation.started_at'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('finished_at')
                    ->label(__('seo-content-ai::filament.automation.finished_at'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('trigger_type')
                    ->label(__('seo-content-ai::filament.automation.trigger_type'))
                    ->options([
                        'event' => __('seo-content-ai::filament.automation.trigger_event'),
                        'manual' => __('seo-content-ai::filament.automation.trigger_manual'),
                        'schedule' => __('seo-content-ai::filament.automation.trigger_schedule'),
                        'system' => __('seo-content-ai::filament.automation.trigger_system'),
                    ]),
                Tables\Filters\SelectFilter::make('automation_rule_id')
                    ->label(__('seo-content-ai::filament.automation.filters_rule'))
                    ->relationship('rule', 'code')
                    ->searchable(),
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('seo-content-ai::filament.automation.filters_status'))
                    ->options([
                        'pending' => 'Pending',
                        'processing' => 'Processing',
                        'completed' => 'Completed',
                        'partial' => 'Partial',
                        'failed' => 'Failed',
                        'skipped' => 'Skipped',
                        'cancelled' => 'Cancelled',
                    ]),
                Tables\Filters\SelectFilter::make('action_code')
                    ->label(__('seo-content-ai::filament.automation.action_code'))
                    ->options(fn (): array => AutomationExecution::query()
                        ->whereNotNull('action_code')
                        ->distinct()
                        ->orderBy('action_code')
                        ->pluck('action_code', 'action_code')
                        ->all()),
                Tables\Filters\SelectFilter::make('event_name')
                    ->label(__('seo-content-ai::filament.automation.filters_event'))
                    ->options(fn (): array => BusinessEvent::query()
                        ->distinct()
                        ->orderBy('event_name')
                        ->pluck('event_name', 'event_name')
                        ->all())
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;
                        if ($value === null || $value === '') {
                            return $query;
                        }

                        return $query->whereHas('businessEvent', fn (Builder $q): Builder => $q->where('event_name', $value));
                    }),
                Tables\Filters\Filter::make('created_at')
                    ->label(__('seo-content-ai::filament.automation.filters_date'))
                    ->form([
                        Forms\Components\DatePicker::make('from')
                            ->label(__('seo-content-ai::filament.automation.started_at')),
                        Forms\Components\DatePicker::make('until')
                            ->label(__('seo-content-ai::filament.automation.finished_at')),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                filled($data['from'] ?? null),
                                fn (Builder $q): Builder => $q->whereDate('created_at', '>=', $data['from']),
                            )
                            ->when(
                                filled($data['until'] ?? null),
                                fn (Builder $q): Builder => $q->whereDate('created_at', '<=', $data['until']),
                            );
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['rule', 'businessEvent', 'actionExecutions', 'nodeExecutions']);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAutomationExecutions::route('/'),
            'view' => Pages\ViewAutomationExecution::route('/{record}'),
        ];
    }
}
