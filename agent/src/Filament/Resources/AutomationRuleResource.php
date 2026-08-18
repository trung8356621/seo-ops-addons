<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Filament\Resources;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationRunMode;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationTriggerType;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationWorkflowMode;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRule;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Registry\AutomationActionRegistry;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Registry\BusinessEventRegistry;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Services\AutomationRuleService;
use Omnichannel\Addons\Agent\Automation\Exceptions\AutomationException;
use Omnichannel\Addons\Seo\Filament\Concerns\BelongsToAdminAutomationPanel;
use Omnichannel\Addons\Agent\Filament\Resources\AutomationRuleResource\Pages;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class AutomationRuleResource extends Resource
{
    use BelongsToAdminAutomationPanel;

    protected static ?string $model = AutomationRule::class;

    protected static ?string $slug = 'automation-rules';

    protected static ?string $navigationLabel = null;

    protected static ?string $navigationIcon = 'heroicon-o-bolt';

    protected static ?int $navigationSort = 1;

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
        return __('seo-content-ai::filament.automation.nav_rules');
    }

    /**
     * Hide Rules from sidebar — technical UI; Flows is primary read-only surface.
     * Route stays registered on the SEO panel for direct URL access.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canViewAny(): bool
    {
        return SeoAccessControl::canViewAutomationRules();
    }

    public static function canCreate(): bool
    {
        return SeoAccessControl::canManageAutomationRules();
    }

    public static function canEdit(Model $record): bool
    {
        return SeoAccessControl::canManageAutomationRules();
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getModelLabel(): string
    {
        return __('seo-content-ai::filament.automation.rule');
    }

    public static function getPluralModelLabel(): string
    {
        return __('seo-content-ai::filament.automation.rules');
    }

    public static function form(Form $form): Form
    {
        $eventOptions = static function (): array {
            $registry = app(BusinessEventRegistry::class);
            $options = [];
            foreach ($registry->all() as $definition) {
                $options[$definition->name] = $definition->name.' ('.$definition->module.')';
            }
            ksort($options);

            return $options;
        };

        $actionOptions = static function (): array {
            $registry = app(AutomationActionRegistry::class);
            $options = [];
            foreach ($registry->all() as $definition) {
                $options[$definition->actionCode] = $definition->actionCode;
            }
            ksort($options);

            return $options;
        };

        return $form
            ->schema([
                Forms\Components\Section::make('Summary')
                    ->description('Readonly overview. Changes apply after Save & Publish.')
                    ->schema([
                        Forms\Components\Placeholder::make('summary_name')
                            ->label('Name')
                            ->content(fn (?AutomationRule $record): string => (string) ($record?->name ?? '—')),
                        Forms\Components\Placeholder::make('summary_code')
                            ->label('Code')
                            ->content(fn (?AutomationRule $record): string => (string) ($record?->code ?? '—')),
                        Forms\Components\Placeholder::make('summary_event')
                            ->label('Event')
                            ->content(fn (?AutomationRule $record): string => (string) ($record?->event_name ?? '—')),
                        Forms\Components\Placeholder::make('summary_mode')
                            ->label('Mode')
                            ->content(fn (?AutomationRule $record): string => (string) ($record?->workflow_mode ?? 'linear')),
                        Forms\Components\Placeholder::make('summary_status')
                            ->label('Status')
                            ->content(fn (?AutomationRule $record): string => $record?->is_enabled ? 'Enabled' : 'Disabled'),
                        Forms\Components\Placeholder::make('summary_published')
                            ->label('Published version')
                            ->content(fn (?AutomationRule $record): string => $record?->published_version_id
                                ? 'v'.(string) ($record->version ?? '—').' Published'
                                : 'Unpublished'),
                    ])
                    ->columns(3)
                    ->visible(fn (?AutomationRule $record): bool => $record !== null),
                Forms\Components\Section::make('Rule settings')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('code')
                            ->required()
                            ->maxLength(128)
                            ->unique(ignoreRecord: true)
                            ->disabled(fn (?AutomationRule $record): bool => $record !== null),
                        Forms\Components\Textarea::make('description')
                            ->rows(2)
                            ->columnSpanFull(),
                        Forms\Components\Select::make('event_name')
                            ->label(__('seo-content-ai::filament.automation.event'))
                            ->options($eventOptions)
                            ->searchable()
                            ->required()
                            ->native(false)
                            ->allowHtml(false),
                        Forms\Components\TextInput::make('priority')
                            ->label(__('seo-content-ai::filament.automation.priority'))
                            ->numeric()
                            ->default(100)
                            ->required(),
                        Forms\Components\Select::make('run_mode')
                            ->label(__('seo-content-ai::filament.automation.run_mode'))
                            ->options([
                                AutomationRunMode::Queued->value => 'Queued',
                                AutomationRunMode::Sync->value => 'Sync',
                            ])
                            ->default(AutomationRunMode::Queued->value)
                            ->required()
                            ->native(false),
                        Forms\Components\Select::make('workflow_mode')
                            ->label('Workflow mode')
                            ->options([
                                AutomationWorkflowMode::Linear->value => 'Linear',
                                AutomationWorkflowMode::Graph->value => 'Graph',
                            ])
                            ->default(AutomationWorkflowMode::Linear->value)
                            ->required()
                            ->native(false)
                            ->helperText('Linear = fixed pipeline (no Visual Builder). Graph = Open Workflow Builder.'),
                        Forms\Components\Select::make('trigger_type')
                            ->label('Trigger type')
                            ->options([
                                AutomationTriggerType::Event->value => 'Event',
                                AutomationTriggerType::Schedule->value => 'Schedule',
                                AutomationTriggerType::Manual->value => 'Manual',
                            ])
                            ->default(AutomationTriggerType::Event->value)
                            ->required()
                            ->native(false),
                        Forms\Components\Toggle::make('stop_on_failure')
                            ->label(__('seo-content-ai::filament.automation.stop_on_failure'))
                            ->default(true),
                        Forms\Components\Toggle::make('is_enabled')
                            ->label(__('seo-content-ai::filament.automation.is_enabled'))
                            ->default(false)
                            ->helperText('Runtime enable. Does not publish draft.'),
                    ])
                    ->columns(2),
                Forms\Components\Section::make('Advanced execution settings')
                    ->collapsed()
                    ->schema([
                        Forms\Components\TextInput::make('schedule_expression')
                            ->label('Cron expression')
                            ->placeholder('0 * * * *')
                            ->visible(fn (Forms\Get $get): bool => $get('trigger_type') === AutomationTriggerType::Schedule->value),
                        Forms\Components\Select::make('schedule_timezone')
                            ->label('Timezone')
                            ->options([
                                'UTC' => 'UTC',
                                'Asia/Ho_Chi_Minh' => 'Asia/Ho_Chi_Minh',
                            ])
                            ->default(config('app.timezone', 'UTC'))
                            ->visible(fn (Forms\Get $get): bool => $get('trigger_type') === AutomationTriggerType::Schedule->value)
                            ->native(false),
                        Forms\Components\Placeholder::make('next_run_at')
                            ->label('Next run')
                            ->content(fn (?AutomationRule $record): string => $record?->next_run_at?->toDateTimeString() ?? '—'),
                        Forms\Components\Placeholder::make('last_scheduled_at')
                            ->label('Last run')
                            ->content(fn (?AutomationRule $record): string => $record?->last_scheduled_at?->toDateTimeString() ?? '—'),
                        Forms\Components\TextInput::make('version')
                            ->label('Draft / published version counter')
                            ->disabled()
                            ->dehydrated(false),
                        Forms\Components\Textarea::make('settings_json')
                            ->label(__('seo-content-ai::filament.automation.settings').' (JSON)')
                            ->rows(3)
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('conditions_json')
                            ->label('Raw conditions JSON')
                            ->rows(4)
                            ->columnSpanFull()
                            ->helperText('Advanced. Prefer Conditions builder below when possible.'),
                    ])
                    ->columns(2),
                Forms\Components\Section::make('Conditions')
                    ->description('All conditions must match.')
                    ->schema([
                        Forms\Components\Repeater::make('conditions_builder')
                            ->label('')
                            ->schema([
                                Forms\Components\TextInput::make('field')
                                    ->placeholder('article.post_type')
                                    ->required(),
                                Forms\Components\Select::make('operator')
                                    ->options([
                                        'equals' => 'equals',
                                        'not_equals' => 'not_equals',
                                        'exists' => 'exists',
                                        'in' => 'in',
                                        'contains' => 'contains',
                                    ])
                                    ->default('equals')
                                    ->required()
                                    ->native(false),
                                Forms\Components\TextInput::make('value')
                                    ->placeholder('product'),
                            ])
                            ->columns(3)
                            ->defaultItems(0)
                            ->addActionLabel('Add condition')
                            ->columnSpanFull()
                            ->dehydrated(false)
                            ->helperText('Saved via raw JSON on submit when builder empty; builder UI for clarity — sync to conditions_json before save if you edit rows.'),
                    ]),
                Forms\Components\Section::make('Graph nodes')
                    ->visible(fn (Forms\Get $get): bool => $get('workflow_mode') === AutomationWorkflowMode::Graph->value)
                    ->schema([
                        Forms\Components\Repeater::make('graph_nodes')
                            ->schema([
                                Forms\Components\TextInput::make('node_key')->required(),
                                Forms\Components\Select::make('node_type')
                                    ->options(collect(\Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationNodeType::cases())
                                        ->mapWithKeys(static fn ($c) => [$c->value => $c->value])->all())
                                    ->required()
                                    ->native(false),
                                Forms\Components\Select::make('action_code')->options($actionOptions)->searchable()->native(false),
                                Forms\Components\Textarea::make('config_json')->label('Config (JSON)')->rows(2)->columnSpanFull(),
                                Forms\Components\Textarea::make('input_mapping_json')->label('Input mapping (JSON)')->rows(2)->columnSpanFull(),
                                Forms\Components\Textarea::make('settings_json')->label('Settings (JSON)')->rows(2)->columnSpanFull(),
                                Forms\Components\Toggle::make('is_enabled')->default(true),
                            ])
                            ->columns(2)
                            ->columnSpanFull(),
                    ]),
                Forms\Components\Section::make('Graph edges')
                    ->visible(fn (Forms\Get $get): bool => $get('workflow_mode') === AutomationWorkflowMode::Graph->value)
                    ->schema([
                        Forms\Components\Repeater::make('graph_edges')
                            ->schema([
                                Forms\Components\TextInput::make('from_node_key')->required(),
                                Forms\Components\TextInput::make('to_node_key')->required(),
                                Forms\Components\Select::make('branch')
                                    ->options(collect(\Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationEdgeBranch::cases())
                                        ->mapWithKeys(static fn ($b) => [$b->value => $b->value])->all())
                                    ->native(false),
                                Forms\Components\TextInput::make('priority')->numeric()->default(100),
                            ])
                            ->columns(2)
                            ->columnSpanFull(),
                    ]),
                Forms\Components\Section::make('Workflow (Linear)')
                    ->description('Actions run top-to-bottom. Fixed pipeline — do not reorder/add/remove required steps. Product reviews sync inside wordpress.article.sync.')
                    ->visible(fn (Forms\Get $get): bool => $get('workflow_mode') !== AutomationWorkflowMode::Graph->value)
                    ->schema([
                        Forms\Components\Repeater::make('actions_data')
                            ->label('')
                            ->schema([
                                Forms\Components\Select::make('action_code')
                                    ->label('Action')
                                    ->options($actionOptions)
                                    ->searchable()
                                    ->required()
                                    ->native(false)
                                    ->disabled()
                                    ->dehydrated(),
                                Forms\Components\TextInput::make('position')
                                    ->numeric()
                                    ->default(0)
                                    ->required()
                                    ->disabled()
                                    ->dehydrated(),
                                Forms\Components\Toggle::make('is_enabled')
                                    ->label(__('seo-content-ai::filament.automation.is_enabled'))
                                    ->default(true),
                                Forms\Components\Toggle::make('continue_on_failure')
                                    ->default(false),
                                Forms\Components\Textarea::make('input_mapping_json')
                                    ->label('Input mapping (JSON)')
                                    ->rows(2)
                                    ->columnSpanFull(),
                                Forms\Components\Textarea::make('settings_json')
                                    ->label('Settings (JSON)')
                                    ->rows(2)
                                    ->helperText('Product sync: content + media + pending product reviews.')
                                    ->columnSpanFull(),
                            ])
                            ->columns(2)
                            ->defaultItems(0)
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->description(fn (AutomationRule $record): string => (string) $record->code),
                Tables\Columns\TextColumn::make('code')
                    ->label('Code')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('classification')
                    ->badge()
                    ->colors([
                        'success' => fn ($state): bool => in_array((string) $state, ['business', 'production'], true),
                        'info' => fn ($state): bool => in_array((string) $state, ['system', 'infrastructure'], true),
                        'gray' => 'sample',
                        'warning' => 'experimental',
                        'danger' => 'deprecated',
                    ])
                    ->sortable(),
                Tables\Columns\TextColumn::make('workflow_mode')
                    ->label('Mode')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('event_name')
                    ->label(__('seo-content-ai::filament.automation.event'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_enabled')
                    ->label(__('seo-content-ai::filament.automation.is_enabled'))
                    ->boolean()
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_published')
                    ->label('Published')
                    ->boolean()
                    ->getStateUsing(fn (AutomationRule $record): bool => $record->published_version_id !== null),
                Tables\Columns\TextColumn::make('priority')
                    ->label(__('seo-content-ai::filament.automation.priority'))
                    ->sortable()
                    ->alignCenter(),
                Tables\Columns\TextColumn::make('version')
                    ->label(__('seo-content-ai::filament.automation.version'))
                    ->sortable()
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('latestExecution.status')
                    ->label(__('seo-content-ai::filament.automation.latest_run'))
                    ->badge()
                    ->placeholder('—'),
            ])
            ->defaultSort('priority')
            ->groups([
                Tables\Grouping\Group::make('event_name')
                    ->label('Source event')
                    ->collapsible(),
                Tables\Grouping\Group::make('classification')
                    ->label('Classification')
                    ->collapsible(),
            ])
            ->defaultGroup('event_name')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_enabled')
                    ->label(__('seo-content-ai::filament.automation.is_enabled')),
                Tables\Filters\SelectFilter::make('classification')
                    ->options([
                        'business' => 'business',
                        'system' => 'system',
                        'infrastructure' => 'infrastructure',
                        'sample' => 'sample',
                        'deprecated' => 'deprecated',
                        'production' => 'production (legacy)',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make()
                    ->visible(fn (): bool => static::canEdit(new AutomationRule)),
                Tables\Actions\Action::make('enable')
                    ->label(__('seo-content-ai::filament.automation.enable'))
                    ->icon('heroicon-o-play')
                    ->color('success')
                    ->visible(fn (AutomationRule $record): bool => static::canEdit($record) && ! $record->is_enabled)
                    ->action(function (AutomationRule $record): void {
                        static::toggleEnabled($record, true);
                    }),
                Tables\Actions\Action::make('disable')
                    ->label(__('seo-content-ai::filament.automation.disable'))
                    ->icon('heroicon-o-pause')
                    ->color('warning')
                    ->visible(fn (AutomationRule $record): bool => static::canEdit($record) && $record->is_enabled)
                    ->action(function (AutomationRule $record): void {
                        static::toggleEnabled($record, false);
                    }),
                Tables\Actions\Action::make('openWorkflow')
                    ->label('Open workflow')
                    ->icon('heroicon-o-squares-2x2')
                    ->visible(fn (AutomationRule $record): bool => (string) ($record->workflow_mode ?? 'linear') === AutomationWorkflowMode::Graph->value)
                    ->url(fn (AutomationRule $record): string => \Omnichannel\Addons\Agent\Filament\Pages\AutomationWorkflowBuilder::getUrl([
                        'rule' => $record->getKey(),
                    ])),
                Tables\Actions\Action::make('duplicate')
                    ->label(__('seo-content-ai::filament.automation.duplicate'))
                    ->icon('heroicon-o-document-duplicate')
                    ->visible(fn (): bool => static::canCreate())
                    ->action(function (AutomationRule $record): void {
                        static::duplicateRule($record);
                    }),
                Tables\Actions\Action::make('executions')
                    ->label(__('seo-content-ai::filament.automation.executions'))
                    ->icon('heroicon-o-queue-list')
                    ->url(fn (AutomationRule $record): string => AutomationExecutionResource::getUrl('index', [
                        'tableFilters' => [
                            'automation_rule_id' => ['value' => (string) $record->id],
                        ],
                    ])),
            ])
            ->bulkActions([]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['latestExecution', 'actions']);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAutomationRules::route('/'),
            'create' => Pages\CreateAutomationRule::route('/create'),
            'edit' => Pages\EditAutomationRule::route('/{record}/edit'),
            'view' => Pages\ViewAutomationRule::route('/{record}'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function mutateFormDataBeforeFill(array $data): array
    {
        $data['conditions_json'] = static::encodeJsonField($data['conditions'] ?? null);
        $data['settings_json'] = static::encodeJsonField($data['settings'] ?? null);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function prepareRulePayload(array $data): array
    {
        $conditions = static::decodeJsonField($data['conditions_json'] ?? null, 'conditions_json');
        $settings = static::decodeJsonField($data['settings_json'] ?? null, 'settings_json');

        return [
            'code' => (string) ($data['code'] ?? ''),
            'name' => (string) ($data['name'] ?? ''),
            'description' => $data['description'] ?? null,
            'event_name' => (string) ($data['event_name'] ?? ''),
            'is_enabled' => (bool) ($data['is_enabled'] ?? false),
            'priority' => (int) ($data['priority'] ?? 100),
            'stop_on_failure' => (bool) ($data['stop_on_failure'] ?? true),
            'run_mode' => (string) ($data['run_mode'] ?? AutomationRunMode::Queued->value),
            'workflow_mode' => (string) ($data['workflow_mode'] ?? AutomationWorkflowMode::Linear->value),
            'trigger_type' => (string) ($data['trigger_type'] ?? AutomationTriggerType::Event->value),
            'schedule_expression' => $data['schedule_expression'] ?? null,
            'schedule_timezone' => $data['schedule_timezone'] ?? null,
            'conditions' => $conditions,
            'settings' => $settings,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    public static function prepareGraphPayload(array $data): array
    {
        $nodes = [];
        foreach ($data['graph_nodes'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $nodes[] = [
                'node_key' => (string) ($row['node_key'] ?? ''),
                'node_type' => (string) ($row['node_type'] ?? ''),
                'name' => $row['name'] ?? null,
                'action_code' => $row['action_code'] ?? null,
                'position' => isset($row['position']) ? (int) $row['position'] : null,
                'config' => static::decodeJsonField($row['config_json'] ?? null, 'config_json'),
                'input_mapping' => static::decodeJsonField($row['input_mapping_json'] ?? null, 'input_mapping_json'),
                'settings' => static::decodeJsonField($row['settings_json'] ?? null, 'settings_json'),
                'is_enabled' => (bool) ($row['is_enabled'] ?? true),
            ];
        }

        $edges = [];
        foreach ($data['graph_edges'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $edges[] = [
                'from_node_key' => (string) ($row['from_node_key'] ?? ''),
                'to_node_key' => (string) ($row['to_node_key'] ?? ''),
                'branch' => $row['branch'] ?? null,
                'priority' => (int) ($row['priority'] ?? 100),
            ];
        }

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    public static function prepareActionsPayload(array $data): array
    {
        $actions = [];
        foreach ($data['actions_data'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $actions[] = [
                'action_code' => (string) ($row['action_code'] ?? ''),
                'position' => (int) ($row['position'] ?? 0),
                'is_enabled' => (bool) ($row['is_enabled'] ?? true),
                'continue_on_failure' => (bool) ($row['continue_on_failure'] ?? false),
                'delay_seconds' => (int) ($row['delay_seconds'] ?? 0),
                'input_mapping' => static::decodeJsonField($row['input_mapping_json'] ?? null, 'input_mapping_json'),
                'settings' => static::mergeMaxDelayTimeIntoSettings(
                    static::decodeJsonField($row['settings_json'] ?? null, 'action settings_json'),
                    $row['max_delay_time'] ?? $row['delay_max_after_minutes'] ?? null,
                ),
            ];
        }

        return $actions;
    }

    /**
     * @param  array<string, mixed>|null  $settings
     * @return array<string, mixed>|null
     */
    public static function mergeMaxDelayTimeIntoSettings(?array $settings, mixed $maxDelayTime): ?array
    {
        $out = is_array($settings) ? $settings : [];
        if ($maxDelayTime === null || $maxDelayTime === '') {
            if (array_key_exists('delay_max_after_minutes', $out) && ! array_key_exists('max_delay_time', $out)) {
                $out = \Omnichannel\Addons\Commerce\Services\ProductReview\ProductReviewDelaySettings::normalizeSettings($out);
            }

            return $out === [] ? null : $out;
        }

        $out = \Omnichannel\Addons\Commerce\Services\ProductReview\ProductReviewDelaySettings::normalizeSettings(
            $out,
            $maxDelayTime,
        );

        return $out === [] ? null : $out;
    }

    /** @deprecated use mergeMaxDelayTimeIntoSettings */
    public static function mergeDelayMaxAfterIntoSettings(?array $settings, mixed $delayMaxAfterMinutes): ?array
    {
        return static::mergeMaxDelayTimeIntoSettings($settings, $delayMaxAfterMinutes);
    }

    public static function createRuleFromFormData(array $data): AutomationRule
    {
        try {
            $rule = app(AutomationRuleService::class)->createRule(
                static::prepareRulePayload($data),
                ($data['workflow_mode'] ?? 'linear') === AutomationWorkflowMode::Graph->value ? [] : static::prepareActionsPayload($data),
                auth()->id() !== null ? (int) auth()->id() : null,
            );

            if ($rule->isGraphMode()) {
                $graph = static::prepareGraphPayload($data);
                app(\Omnichannel\Addons\Agent\Automation\BusinessHook\Services\AutomationGraphRuleService::class)
                    ->syncGraph($rule, $graph['nodes'], $graph['edges']);
            }

            return $rule->fresh(['actions', 'nodes', 'edges']) ?? $rule;
        } catch (AutomationException $exception) {
            throw ValidationException::withMessages([
                'event_name' => $exception->getMessage(),
            ]);
        }
    }

    public static function updateRuleFromFormData(AutomationRule $rule, array $data): AutomationRule
    {
        try {
            $rule = app(AutomationRuleService::class)->updateRule(
                $rule,
                static::prepareRulePayload($data),
                ($data['workflow_mode'] ?? $rule->workflow_mode) === AutomationWorkflowMode::Graph->value
                    ? null
                    : static::prepareActionsPayload($data),
                auth()->id() !== null ? (int) auth()->id() : null,
            );

            if ($rule->isGraphMode()) {
                $graph = static::prepareGraphPayload($data);
                app(\Omnichannel\Addons\Agent\Automation\BusinessHook\Services\AutomationGraphRuleService::class)
                    ->syncGraph($rule, $graph['nodes'], $graph['edges']);
            }

            return $rule->fresh(['actions', 'nodes', 'edges']) ?? $rule;
        } catch (AutomationException $exception) {
            throw ValidationException::withMessages([
                'event_name' => $exception->getMessage(),
            ]);
        }
    }

    public static function toggleEnabled(AutomationRule $rule, bool $enabled): void
    {
        $service = app(AutomationRuleService::class);
        $actorId = auth()->id() !== null ? (int) auth()->id() : null;

        if ($enabled) {
            $service->enable($rule, $actorId);
        } else {
            $service->disable($rule, $actorId);
        }

        Notification::make()
            ->title($enabled ? 'Rule enabled' : 'Rule disabled')
            ->success()
            ->send();
    }

    public static function duplicateRule(AutomationRule $rule): void
    {
        $copy = app(AutomationRuleService::class)->duplicate(
            $rule,
            auth()->id() !== null ? (int) auth()->id() : null,
        );

        Notification::make()
            ->title('Rule duplicated')
            ->body("New code: {$copy->code}")
            ->success()
            ->send();
    }

    /**
     * @param  array<string, mixed>  $record
     */
    public static function fillActionsRepeaterFromRecord(array $record): array
    {
        $rule = AutomationRule::query()->with('actions')->find($record['id'] ?? 0);
        if (! $rule instanceof AutomationRule) {
            return [];
        }

        return $rule->actions->map(static function ($action): array {
            $settings = is_array($action->settings) ? $action->settings : [];

            return [
                'action_code' => $action->action_code,
                'position' => $action->position,
                'is_enabled' => $action->is_enabled,
                'continue_on_failure' => $action->continue_on_failure,
                'delay_seconds' => $action->delay_seconds,
                'max_delay_time' => (int) ($settings['max_delay_time'] ?? $settings['delay_max_after_minutes'] ?? 0) ?: null,
                'input_mapping_json' => static::encodeJsonField($action->input_mapping),
                'settings_json' => static::encodeJsonField($action->settings),
            ];
        })->all();
    }

    /**
     * @param  array<string, mixed>  $record
     * @return list<array<string, mixed>>
     */
    public static function fillGraphNodesRepeater(array $record): array
    {
        $rule = AutomationRule::query()->with('nodes')->find($record['id'] ?? 0);
        if (! $rule instanceof AutomationRule) {
            return [];
        }

        return $rule->nodes->map(static fn ($node): array => [
            'node_key' => $node->node_key,
            'node_type' => $node->node_type,
            'action_code' => $node->action_code,
            'config_json' => static::encodeJsonField($node->config),
            'input_mapping_json' => static::encodeJsonField($node->input_mapping),
            'settings_json' => static::encodeJsonField($node->settings),
            'is_enabled' => $node->is_enabled,
        ])->all();
    }

    /**
     * @param  array<string, mixed>  $record
     * @return list<array<string, mixed>>
     */
    public static function fillGraphEdgesRepeater(array $record): array
    {
        $rule = AutomationRule::query()->with('edges')->find($record['id'] ?? 0);
        if (! $rule instanceof AutomationRule) {
            return [];
        }

        return $rule->edges->map(static fn ($edge): array => [
            'from_node_key' => $edge->from_node_key,
            'to_node_key' => $edge->to_node_key,
            'branch' => $edge->branch,
            'priority' => $edge->priority,
        ])->all();
    }

    private static function encodeJsonField(mixed $value): string
    {
        if ($value === null || $value === []) {
            return '';
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '';
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decodeJsonField(mixed $raw, string $field): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (! is_string($raw)) {
            throw ValidationException::withMessages([
                $field => 'Must be valid JSON.',
            ]);
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw ValidationException::withMessages([
                $field => 'Invalid JSON: '.$e->getMessage(),
            ]);
        }

        if ($decoded !== null && ! is_array($decoded)) {
            throw ValidationException::withMessages([
                $field => 'JSON must be an object.',
            ]);
        }

        return is_array($decoded) ? $decoded : null;
    }
}
