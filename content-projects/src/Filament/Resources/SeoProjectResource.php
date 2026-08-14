<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Filament\Resources;



use Omnichannel\Addons\Seo\Filament\Resources\SeoPanelResource;
use Omnichannel\Addons\Publishing\Filament\Pages\PublishingQueueHub;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectArchive;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ArchiveContentProjectService;
use Omnichannel\Addons\Content\Services\ArticleCompletedArchiveQueryService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ArchiveContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\GenerateProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResultNotifier;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBus;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectPublicRef;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectArchiveService;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectKeywordAiGeneratorService;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectKeywordListParser;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectRunPreflightService;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectTaskMoveService;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectTaskSyncService;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectRunConsolidationService;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectWorkflowRunService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemIdentity;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\Seo\Support\SeoConnectionContext;
use App\Models\Site;
use App\Models\User;
use App\Support\RuntimeLogger;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

class SeoProjectResource extends SeoPanelResource
{
    protected static ?string $model = SeoProject::class;

    public const PROJECT_WORKSPACE_TABS_ID = 'project_workspace';

    protected static ?string $slug = 'content-projects';

    protected static ?string $navigationIcon = 'heroicon-o-folder-open';

    protected static ?string $navigationGroup = null;

    protected static ?string $navigationLabel = 'Content projects';

    protected static ?string $modelLabel = 'Content project';

    protected static ?string $pluralModelLabel = 'Content projects';

    protected static ?int $navigationSort = 4;

    public static function canViewAny(): bool
    {
        return SeoAccessControl::canAccessContentFeatures();
    }

    public static function canCreate(): bool
    {
        return static::allowsSeoPanelMutation()
            && SeoAccessControl::canAccessPlannerFeatures();
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        if ($record instanceof SeoProject && $record->isProjectArchived()) {
            return false;
        }

        return SeoAccessControl::canMutateContentProjects();
    }

    public static function canView(\Illuminate\Database\Eloquent\Model $record): bool
    {
        if (! $record instanceof SeoProject) {
            return false;
        }

        if (SeoAccessControl::isContentManager()) {
            return (int) $record->user_id === (int) auth()->id();
        }

        if (! SeoAccessControl::canAccessPlannerFeatures()) {
            return false;
        }

        $siteId = (int) ($record->site_id ?? 0);

        // Authorization theo quyền site — không dùng global domain làm auth.
        return $siteId > 0 && SeoAccessControl::canAccessSite($siteId);
    }

    public static function projectRecordUrl(SeoProject $record): string
    {
        if ($record->isProjectArchived()) {
            $archiveId = static::currentArchiveIdFor($record);
            if ($archiveId > 0 && SeoAccessControl::canViewProjectArchives()) {
                return static::getUrl('archive-preview', ['archive' => $archiveId]);
            }

            if (SeoAccessControl::canViewProjectArchives()) {
                return static::getUrl('archive');
            }

            return static::getUrl('view', ['record' => $record]);
        }

        if (static::canEdit($record)) {
            return static::getUrl('edit', ['record' => $record]);
        }

        return static::getUrl('view', ['record' => $record]);
    }

    public static function currentArchiveIdFor(SeoProject $record): int
    {
        if ($record->relationLoaded('currentArchive')) {
            $loaded = $record->getRelation('currentArchive');

            return $loaded instanceof SeoProjectArchive
                ? (int) $loaded->getKey()
                : 0;
        }

        $archive = app(ArchiveContentProjectService::class)->getCurrentArchive($record);

        return $archive !== null ? (int) $archive->getKey() : 0;
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        if ($record instanceof SeoProject && ($record->isArchive() || $record->isProjectArchived())) {
            return false;
        }

        return static::allowsSeoPanelMutation()
            && SeoAccessControl::canAccessPlannerFeatures();
    }

    public static function getNavigationLabel(): string
    {
        return __('seo-content-ai::filament.nav.content_projects');
    }

    /**
     * Parent stays active on project list/detail and nested Publishing Queue.
     * Publishing Queue child is registered here (hub page does not self-register)
     * so parentItem label always matches.
     *
     * @return array<int, \Filament\Navigation\NavigationItem>
     */
    public static function getNavigationItems(): array
    {
        if (! static::shouldRegisterNavigation()) {
            return [];
        }

        $parentLabel = static::getNavigationLabel();

        $items = [
            \Filament\Navigation\NavigationItem::make($parentLabel)
                ->icon(static::getNavigationIcon())
                ->group(static::getNavigationGroup())
                ->sort(static::getNavigationSort())
                ->url(static::getUrl())
                ->isActiveWhen(fn (): bool => request()->routeIs([
                    'filament.seo.resources.content-projects.*',
                    'filament.seo.pages.publishing-queue',
                ])),
        ];

        if (PublishingQueueHub::canAccess()) {
            $items[] = \Filament\Navigation\NavigationItem::make(PublishingQueueHub::getNavigationLabel())
                ->icon('heroicon-o-queue-list')
                ->group(null)
                ->parentItem($parentLabel)
                ->isActiveWhen(fn (): bool => request()->routeIs('filament.seo.pages.publishing-queue'))
                ->sort(5)
                ->url(PublishingQueueHub::getUrl());
        }

        return $items;
    }

    public static function getModelLabel(): string
    {
        return __('seo-content-ai::filament.nav.content_project');
    }

    public static function getPluralModelLabel(): string
    {
        return __('seo-content-ai::filament.nav.content_projects');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema(static::currentArticlesFormSchema());
    }

    /**
     * @return list<\Filament\Forms\Components\Component>
     */
    public static function currentArticlesFormSchema(): array
    {
        return [
            Forms\Components\Section::make(__('seo-content-ai::filament.projects.project_info'))
                ->schema([
                    Forms\Components\Placeholder::make('archive_kind_banner')
                        ->label(__('seo-content-ai::filament.projects.archive_project_badge'))
                        ->content(__('seo-content-ai::filament.projects.archive_project_banner'))
                        ->visible(fn (?SeoProject $record): bool => $record instanceof SeoProject && $record->isArchive())
                        ->columnSpanFull(),

                    Forms\Components\Placeholder::make('project_name_preview')
                        ->label(__('seo-content-ai::filament.projects.project_name'))
                        ->content(
                            function (Get $get, ?SeoProject $record): string {
                                if ($record instanceof SeoProject && $record->isArchive()) {
                                    return (string) $record->name;
                                }

                                return $get('month')
                                    ? SeoProject::defaultNameFromMonth($get('month'))
                                    : __('seo-content-ai::filament.projects.project_name_placeholder');
                            },
                        )
                        ->columnSpanFull(),

                    Forms\Components\Select::make('user_id')
                        ->label(__('seo-content-ai::filament.projects.assign_writer'))
                        ->options(fn (Get $get): array => static::groupedWriterSelectOptions(
                            is_string($get('month')) || $get('month') instanceof \DateTimeInterface
                                ? (string) $get('month')
                                : null,
                        ))
                        ->searchable()
                        ->preload()
                        ->required()
                        ->disabled(fn (): bool => SeoAccessControl::isContentManager())
                        ->dehydrated()
                        ->native(false)
                        ->live()
                        ->helperText(__('seo-content-ai::filament.projects.assign_writer_help')),

                    Forms\Components\CheckboxList::make('unassigned_staff_ids')
                        ->label(__('seo-content-ai::filament.projects.unassigned_staff_heading'))
                        ->options(function (Get $get): array {
                            $month = is_string($get('month')) || $get('month') instanceof \DateTimeInterface
                                ? (string) $get('month')
                                : null;

                            return app(\Omnichannel\Addons\ContentProjects\Services\ContentProjectStaffAvailabilityService::class)
                                ->groupedSelectOptions($month)['unassigned'];
                        })
                        ->searchable()
                        ->bulkToggleable(false)
                        ->columns(1)
                        ->visible(fn (?SeoProject $record): bool => $record === null
                            && app(\Omnichannel\Addons\ContentProjects\Services\ContentProjectStaffAvailabilityService::class)->canViewUnassignedStaff())
                        ->dehydrated(false)
                        ->live()
                        ->afterStateUpdated(function ($state, callable $set): void {
                            $ids = is_array($state) ? array_values(array_filter(array_map('intval', $state))) : [];
                            if ($ids === []) {
                                return;
                            }
                            // Schema: 1 writer / project (user_id). Giữ lựa chọn mới nhất.
                            $set('user_id', (int) end($ids));
                            $set('unassigned_staff_ids', [(int) end($ids)]);
                            $set('assign_from_unassigned', true);
                        })
                        ->helperText(__('seo-content-ai::filament.projects.unassigned_staff_help')),

                    Forms\Components\Hidden::make('assign_from_unassigned')
                        ->default(false)
                        ->dehydrated(fn (?SeoProject $record): bool => $record === null),

                    Forms\Components\Select::make('site_id')
                        ->label(__('seo-content-ai::filament.projects.domain'))
                        ->options(fn (): array => static::siteSelectOptions())
                        ->default(fn (): ?int => SeoAccessControl::globalSiteId())
                        ->searchable()
                        ->preload()
                        ->required()
                        ->native(false)
                        ->live()
                        ->disabled(fn (?SeoProject $record): bool => $record instanceof SeoProject && $record->isArchive())
                        ->dehydrated()
                        ->dehydrateStateUsing(fn (mixed $state): ?int => $state !== null && $state !== ''
                            ? (int) $state
                            : null),

                    Forms\Components\DatePicker::make('month')
                        ->label(__('seo-content-ai::filament.projects.execution_month'))
                        ->native(false)
                        ->displayFormat('m/Y')
                        ->format('Y-m-d')
                        ->default(function (): string {
                            $fromQuery = \Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext::parseOrNull(
                                is_string(request()->query('month')) ? (string) request()->query('month') : null,
                            );

                            return $fromQuery !== null
                                ? \Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext::toDateString($fromQuery)
                                : now()->startOfMonth()->format('Y-m-d');
                        })
                        ->required()
                        ->live()
                        ->afterStateUpdated(function ($state, callable $set, Get $get): void {
                            if ($state === null || $state === '') {
                                return;
                            }

                            $userId = (int) ($get('user_id') ?? 0);
                            if ($userId <= 0) {
                                return;
                            }

                            $service = app(\Omnichannel\Addons\ContentProjects\Services\ContentProjectStaffAvailabilityService::class);
                            if ($service->isUnassigned($userId, (string) $state)) {
                                $set('assign_from_unassigned', true);
                                $set('unassigned_staff_ids', [$userId]);

                                return;
                            }

                            // Staff đã có project tháng mới — bỏ chọn + cảnh báo.
                            $set('user_id', null);
                            $set('unassigned_staff_ids', []);
                            $set('assign_from_unassigned', false);

                            $user = \App\Models\User::query()->find($userId);
                            $name = $user instanceof \App\Models\User
                                ? $service->formatLabel($user)
                                : '#'.$userId;

                            \Filament\Notifications\Notification::make()
                                ->warning()
                                ->title(__('seo-content-ai::filament.projects.unassigned_staff_already_assigned', [
                                    'name' => $name,
                                    'month' => \Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext::display((string) $state),
                                ]))
                                ->send();
                        })
                        ->visible(fn (?SeoProject $record): bool => ! ($record instanceof SeoProject && $record->isArchive()))
                        ->dehydrated(fn (?SeoProject $record): bool => ! ($record instanceof SeoProject && $record->isArchive())),

                    Forms\Components\Hidden::make('status')
                        ->default(SeoProject::STATUS_MANUAL)
                        ->dehydrated(),

                    Forms\Components\Hidden::make('kind')
                        ->default(SeoProject::KIND_MONTHLY)
                        ->dehydrated(),

                    Forms\Components\Placeholder::make('status_display')
                        ->label(__('seo-content-ai::filament.projects.status'))
                        ->content(fn (?SeoProject $record): string => $record instanceof SeoProject
                            ? (SeoProject::statusOptions()[(string) $record->status] ?? (string) $record->status)
                            : __('seo-content-ai::filament.projects.status_manual_fixed')),

                    Forms\Components\Textarea::make('description')
                        ->label(__('seo-content-ai::filament.projects.description'))
                        ->rows(3)
                        ->columnSpanFull(),
                ])
                ->columns(2),

            Forms\Components\Section::make(__('seo-content-ai::filament.projects.article_keyword_list'))
                ->description(fn (?SeoProject $record): string => $record instanceof SeoProject && $record->isArchive()
                    ? __('seo-content-ai::filament.projects.archive_article_list_description')
                    : __('seo-content-ai::filament.projects.article_keyword_list_description'))
                ->schema([
                    Forms\Components\Placeholder::make('month_limit_hint')
                        ->label(fn (?SeoProject $record): string => $record instanceof SeoProject && $record->isArchive()
                            ? __('seo-content-ai::filament.projects.archive_capacity_label')
                            : __('seo-content-ai::filament.projects.month_limit'))
                        ->content(function (Get $get, ?SeoProject $record): string {
                            $count = app(SeoProjectTaskSyncService::class)
                                ->countEffectiveTasks(is_array($get('tasks_data')) ? $get('tasks_data') : []);

                            if ($record instanceof SeoProject && $record->isArchive()) {
                                return __('seo-content-ai::filament.projects.archive_capacity_hint', [
                                    'count' => $count,
                                ]);
                            }

                            $month = $get('month');
                            if (! $month) {
                                return __('seo-content-ai::filament.projects.choose_month_to_view_limit');
                            }

                            $carbon = Carbon::parse($month)->startOfMonth();
                            $max = $carbon->daysInMonth;
                            return __('seo-content-ai::filament.projects.month_limit_hint', [
                                'month' => $carbon->format('m/Y'),
                                'max' => $max,
                                'count' => $count,
                            ]);
                        })
                        ->columnSpanFull(),

                    Forms\Components\Actions::make([
                        Action::make('import_keywords')
                            ->label(__('seo-content-ai::filament.projects.keyword_list'))
                            ->icon('heroicon-o-queue-list')
                            ->iconButton()
                            ->tooltip(__('seo-content-ai::filament.projects.keyword_list_tooltip'))
                            ->color('gray')
                            ->modalHeading(__('seo-content-ai::filament.projects.import_keyword_list'))
                            ->modalDescription(__('seo-content-ai::filament.projects.import_keyword_list_description'))
                            ->modalSubmitActionLabel(__('seo-content-ai::filament.projects.add_to_project'))
                            ->form([
                                Forms\Components\Textarea::make('keywords_text')
                                    ->label(__('seo-content-ai::filament.projects.keywords'))
                                    ->placeholder("non-woven bags\nhow to sew fabric bags\n- canvas bags")
                                    ->rows(12)
                                    ->required(),
                            ])
                            ->action(function (array $data, Get $get, Set $set): void {
                                static::appendKeywordsToFormState($get, $set, $data['keywords_text'] ?? '');
                            }),

                        Action::make('ai_generate_keywords')
                            ->label(__('seo-content-ai::filament.projects.ai_generator'))
                            ->icon('heroicon-o-sparkles')
                            ->iconButton()
                            ->tooltip(__('seo-content-ai::filament.projects.ai_generator_tooltip'))
                            ->color('primary')
                            ->modalHeading(__('seo-content-ai::filament.projects.ai_generator_heading'))
                            ->modalDescription(__('seo-content-ai::filament.projects.ai_generator_description'))
                            ->modalSubmitActionLabel(__('seo-content-ai::filament.projects.generate_keywords'))
                            ->form([
                                Forms\Components\TextInput::make('count')
                                    ->label(__('seo-content-ai::filament.projects.number_of_keywords'))
                                    ->numeric()
                                    ->minValue(1)
                                    ->maxValue(31)
                                    ->default(10)
                                    ->required(),
                                Forms\Components\Textarea::make('brief')
                                    ->label(__('seo-content-ai::filament.projects.additional_ai_brief'))
                                    ->placeholder(__('seo-content-ai::filament.projects.additional_ai_brief_placeholder'))
                                    ->rows(4),
                            ])
                            ->action(function (array $data, Get $get, Set $set): void {
                                static::generateKeywordsWithAi($get, $set, $data);
                            }),
                    ])
                        ->columnSpanFull(),

                    Forms\Components\Repeater::make('tasks_data')
                        ->label(__('seo-content-ai::filament.projects.article_items'))
                        ->schema([
                            Forms\Components\Hidden::make('id'),

                            Forms\Components\Placeholder::make('connected_at_display')
                                ->label(__('seo-content-ai::filament.projects.connected_at'))
                                ->content(fn (Get $get): string => static::formatTaskTimestamp($get('connected_at')))
                                ->visible(fn (?SeoProject $record): bool => $record instanceof SeoProject && $record->isArchive()),

                            Forms\Components\Placeholder::make('completed_at_display')
                                ->label(__('seo-content-ai::filament.projects.completed_at'))
                                ->content(fn (Get $get): string => static::formatTaskTimestamp($get('completed_at')))
                                ->visible(fn (?SeoProject $record): bool => $record instanceof SeoProject && $record->isArchive()),

                            Forms\Components\Select::make('type')
                                ->label(__('seo-content-ai::filament.projects.article_type'))
                                ->options(SeoProjectTask::typeOptions())
                                ->default(SeoProjectTask::TYPE_CREATE)
                                ->required()
                                ->native(false)
                                ->live(),

                            Forms\Components\Select::make('source_content')
                                ->label(fn (Get $get): string => SeoProjectTask::normalizeType($get('type')) === SeoProjectTask::TYPE_IMPROVE
                                    ? __('seo-content-ai::filament.projects.title_of_article_to_improve')
                                    : __('seo-content-ai::filament.projects.title_of_article_to_rewrite'))
                                ->placeholder(__('seo-content-ai::filament.projects.title_to_rewrite_placeholder'))
                                ->searchable()
                                ->searchPrompt(__('seo-content-ai::filament.projects.rewrite_article_search_prompt'))
                                ->searchDebounce(300)
                                ->native(false)
                                ->required()
                                ->visible(fn (Get $get): bool => in_array(
                                    SeoProjectTask::normalizeType($get('type')),
                                    SeoProjectTask::articlePickerTypes(),
                                    true,
                                ))
                                ->getSearchResultsUsing(
                                    fn (string $search, Get $get): array => static::searchArticlesForRewriteTitle(
                                        $search,
                                        static::resolveRepeaterSiteId($get),
                                    ),
                                )
                                ->getOptionLabelUsing(
                                    fn ($value): ?string => is_string($value) && trim($value) !== ''
                                        ? trim($value)
                                        : null,
                                )
                                ->helperText(
                                    fn (Get $get): ?HtmlString => static::rewriteArticleWpLinkHelper(
                                        $get('source_content'),
                                        static::resolveRepeaterSiteId($get),
                                    ),
                                )
                                ->live()
                                ->afterStateUpdated(function (Forms\Components\Select $component, ?string $state, Get $get): void {
                                    if ($state === null || trim($state) === '') {
                                        $component->suffixAction(null);

                                        return;
                                    }

                                    $permalink = static::resolveArticlePermalinkByTitle(trim($state), static::resolveRepeaterSiteId($get));
                                    if ($permalink !== null) {
                                        $component->suffixAction(
                                            Forms\Components\Actions\Action::make('view_wp_link')
                                                ->icon('heroicon-o-link')
                                                ->color('info')
                                                ->url($permalink, shouldOpenInNewTab: true),
                                        );
                                    }
                                }),

                            Forms\Components\TextInput::make('keyword')
                                ->label(__('seo-content-ai::filament.projects.keyword'))
                                ->placeholder(__('seo-content-ai::filament.projects.keyword_placeholder'))
                                ->maxLength(500)
                                ->rules([
                                    fn (Get $get): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                                        if (! in_array(
                                            SeoProjectTask::normalizeType($get('type')),
                                            [SeoProjectTask::TYPE_CREATE, SeoProjectTask::TYPE_REWRITE],
                                            true,
                                        )) {
                                            return;
                                        }

                                        if (! ContentProjectItemIdentity::isValid(
                                            is_string($value) ? $value : (string) ($value ?? ''),
                                            (string) ($get('title') ?? ''),
                                        )) {
                                            $fail(ContentProjectItemIdentity::failureMessage());
                                        }
                                    },
                                ])
                                ->visible(fn (Get $get): bool => in_array(
                                    SeoProjectTask::normalizeType($get('type')),
                                    [SeoProjectTask::TYPE_CREATE, SeoProjectTask::TYPE_REWRITE],
                                    true,
                                )),

                            Forms\Components\TextInput::make('title')
                                ->label(__('seo-content-ai::filament.projects.title_field'))
                                ->placeholder(__('seo-content-ai::filament.projects.title_field_placeholder'))
                                ->maxLength(500)
                                ->rules([
                                    fn (Get $get): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                                        if (! in_array(
                                            SeoProjectTask::normalizeType($get('type')),
                                            [SeoProjectTask::TYPE_CREATE, SeoProjectTask::TYPE_REWRITE],
                                            true,
                                        )) {
                                            return;
                                        }

                                        if (! ContentProjectItemIdentity::isValid(
                                            (string) ($get('keyword') ?? ''),
                                            is_string($value) ? $value : (string) ($value ?? ''),
                                        )) {
                                            $fail(ContentProjectItemIdentity::failureMessage());
                                        }
                                    },
                                ])
                                ->visible(fn (Get $get): bool => in_array(
                                    SeoProjectTask::normalizeType($get('type')),
                                    [SeoProjectTask::TYPE_CREATE, SeoProjectTask::TYPE_REWRITE],
                                    true,
                                )),

                            Forms\Components\Textarea::make('secondary_description')
                                ->label(__('seo-content-ai::filament.projects.secondary_description'))
                                ->placeholder(__('seo-content-ai::filament.projects.secondary_description_placeholder'))
                                ->rows(3)
                                ->visible(fn (Get $get): bool => in_array(
                                    SeoProjectTask::normalizeType($get('type')),
                                    [SeoProjectTask::TYPE_CREATE, SeoProjectTask::TYPE_REWRITE],
                                    true,
                                ))
                                ->columnSpanFull(),

                            Forms\Components\Textarea::make('rewrite_notes')
                                ->label(__('seo-content-ai::filament.projects.improve_instruction'))
                                ->placeholder(__('seo-content-ai::filament.projects.improve_instruction_placeholder'))
                                ->rows(3)
                                ->required()
                                ->visible(fn (Get $get): bool => SeoProjectTask::normalizeType($get('type')) === SeoProjectTask::TYPE_IMPROVE)
                                ->columnSpanFull(),

                            Forms\Components\Select::make('post_type')
                                ->label(__('seo-content-ai::filament.article_list.post_type'))
                                ->options(static::postTypeSelectOptions())
                                ->default(SeoProjectTask::POST_TYPE_ARTICLE)
                                ->required()
                                ->native(false)
                                ->live()
                                ->visible(fn (Get $get): bool => SeoProjectTask::isNewArticleType($get('type'))),

                            Forms\Components\TextInput::make('loai_san_pham')
                                ->label(__('seo-content-ai::filament.projects.loai_san_pham'))
                                ->placeholder(__('seo-content-ai::filament.projects.loai_san_pham_placeholder'))
                                ->maxLength(500)
                                ->visible(fn (Get $get): bool => SeoProjectTask::isNewArticleType($get('type'))
                                    && $get('post_type') === SeoProjectTask::POST_TYPE_PRODUCT)
                                ->columnSpanFull(),

                            Forms\Components\Textarea::make('description')
                                ->label(__('seo-content-ai::filament.projects.gallery_description'))
                                ->placeholder(__('seo-content-ai::filament.projects.gallery_description_placeholder'))
                                ->rows(3)
                                ->visible(fn (Get $get): bool => SeoProjectTask::isNewArticleType($get('type'))
                                    && $get('post_type') === SeoProjectTask::POST_TYPE_PRODUCT)
                                ->columnSpanFull(),
                        ])
                        ->columns(2)
                        ->defaultItems(1)
                        ->addActionLabel(__('seo-content-ai::filament.projects.add_article'))
                        ->addAction(fn (Action $action): Action => $action
                            ->disabled(fn (Get $get, ?SeoProject $record): bool => ! static::canAddTaskRowToForm($get, $record))
                            ->tooltip(fn (Get $get, ?SeoProject $record): ?string => static::addTaskRowTooltip($get, $record)))
                        ->reorderable()
                        ->collapsible()
                        ->collapsed()
                        ->extraItemActions([
                            Action::make('move_task')
                                ->label(__('seo-content-ai::filament.projects.move_task'))
                                ->icon('heroicon-m-arrows-right-left')
                                ->color('gray')
                                ->visible(fn (?SeoProject $record): bool => $record instanceof SeoProject
                                    && SeoAccessControl::canMutateContentProjects())
                                ->modalHeading(__('seo-content-ai::filament.projects.move_task_heading'))
                                ->modalDescription(__('seo-content-ai::filament.projects.move_task_description'))
                                ->modalSubmitActionLabel(__('seo-content-ai::filament.projects.move_task_submit'))
                                ->form(function (?SeoProject $record): array {
                                    if (! $record instanceof SeoProject) {
                                        return [];
                                    }

                                    return [
                                        Forms\Components\Select::make('target_project_id')
                                            ->label(__('seo-content-ai::filament.projects.move_target'))
                                            ->options(app(SeoProjectTaskMoveService::class)->moveTargetOptions($record))
                                            ->searchable()
                                            ->required()
                                            ->native(false),
                                    ];
                                })
                                ->action(function (array $arguments, array $data, Forms\Components\Repeater $component, ?SeoProject $record): void {
                                    if (! $record instanceof SeoProject) {
                                        return;
                                    }

                                    $itemKey = (string) ($arguments['item'] ?? '');
                                    $items = $component->getState();
                                    $itemData = is_array($items[$itemKey] ?? null) ? $items[$itemKey] : [];
                                    $taskId = (int) ($itemData['id'] ?? 0);

                                    if ($taskId <= 0) {
                                        Notification::make()
                                            ->title(__('seo-content-ai::filament.projects.move_task_save_first'))
                                            ->warning()
                                            ->send();

                                        return;
                                    }

                                    $targetId = (int) ($data['target_project_id'] ?? 0);
                                    $target = SeoProject::query()->find($targetId);
                                    if (! $target instanceof SeoProject) {
                                        Notification::make()
                                            ->title(__('seo-content-ai::filament.projects.move_failed'))
                                            ->danger()
                                            ->send();

                                        return;
                                    }

                                    try {
                                        $result = app(SeoProjectTaskMoveService::class)
                                            ->moveTasksToProject($record, $target, [$taskId]);

                                        unset($items[$itemKey]);
                                        $component->state($items);

                                        Notification::make()
                                            ->title(__('seo-content-ai::filament.projects.move_completed'))
                                            ->body(__('seo-content-ai::filament.projects.move_completed_body', $result))
                                            ->success()
                                            ->send();
                                    } catch (ValidationException $exception) {
                                        Notification::make()
                                            ->title(__('seo-content-ai::filament.projects.move_failed'))
                                            ->body($exception->validator->errors()->first() ?: $exception->getMessage())
                                            ->danger()
                                            ->send();
                                    } catch (\Throwable $exception) {
                                        report($exception);

                                        Notification::make()
                                            ->title(__('seo-content-ai::filament.projects.move_failed'))
                                            ->body($exception->getMessage())
                                            ->danger()
                                            ->send();
                                    }
                                }),
                        ])
                        ->itemLabel(function (array $state, ?SeoProject $record): ?string {
                            $type = SeoProjectTask::normalizeType($state['type'] ?? SeoProjectTask::TYPE_CREATE);
                            $keyword = trim((string) ($state['keyword'] ?? ''));
                            $title = trim((string) ($state['title'] ?? ''));
                            $content = trim((string) ($state['source_content'] ?? ''));
                            if ($content === '') {
                                $content = $keyword !== '' ? $keyword : $title;
                            }

                            if ($type === SeoProjectTask::TYPE_IMPROVE) {
                                $prefix = '[Improve]';
                            } elseif ($type === SeoProjectTask::TYPE_REWRITE) {
                                $prefix = '[Rewrite]';
                            } else {
                                $postTypeLabels = [
                                    SeoProjectTask::POST_TYPE_ARTICLE => 'Article',
                                    SeoProjectTask::POST_TYPE_PRODUCT => 'Product',
                                    SeoProjectTask::POST_TYPE_CATEGORY => 'Category',
                                    SeoProjectTask::POST_TYPE_PRODUCT_CATEGORY => 'Product Category',
                                ];
                                $postType = SeoProjectTask::normalizePostType($state['post_type'] ?? null);
                                $prefix = '['.($postTypeLabels[$postType] ?? 'Create').']';
                            }

                            $label = $content !== '' ? "{$prefix} {$content}" : __('seo-content-ai::filament.projects.article_items');

                            if ($record instanceof SeoProject && $record->isArchive()) {
                                $connected = static::formatTaskTimestamp($state['connected_at'] ?? null);
                                $completed = static::formatTaskTimestamp($state['completed_at'] ?? null);
                                $label .= ' · '.__('seo-content-ai::filament.projects.archive_item_timestamps', [
                                    'connected' => $connected,
                                    'completed' => $completed,
                                ]);
                            }

                            return $label;
                        })
                        ->live()
                        ->columnSpanFull()
                        ->rules([
                            fn (Get $get, ?SeoProject $record): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($get, $record): void {
                                if ($record instanceof SeoProject && $record->isArchive()) {
                                    return;
                                }

                                $month = $get('month');
                                if (! $month) {
                                    return;
                                }

                                try {
                                    app(SeoProjectTaskSyncService::class)
                                        ->assertWithinMonthlyLimit($month, is_array($value) ? $value : []);
                                } catch (\Illuminate\Validation\ValidationException $e) {
                                    $fail($e->validator->errors()->first('tasks_data') ?? __('seo-content-ai::filament.projects.exceeded_monthly_limit'));
                                }
                            },
                        ]),
                ]),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordUrl(
                fn (SeoProject $record): string => static::projectRecordUrl($record),
            )
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('seo-content-ai::filament.projects.project_name'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->url(
                        fn (SeoProject $record): string => static::projectRecordUrl($record),
                    ),

                Tables\Columns\TextColumn::make('user.name')
                    ->label(__('seo-content-ai::filament.projects.owner'))
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('site.domain')
                    ->label(__('seo-content-ai::filament.projects.domain'))
                    ->sortable()
                    ->searchable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('month')
                    ->label(__('seo-content-ai::filament.projects.month'))
                    ->date('m/Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('active_tasks_count')
                    ->label(__('seo-content-ai::filament.projects.total_items'))
                    ->numeric()
                    ->alignCenter()
                    ->sortable(),

                Tables\Columns\TextColumn::make('active_generated_count')
                    ->label(__('seo-content-ai::filament.projects.generated'))
                    ->numeric()
                    ->alignCenter()
                    ->sortable()
                    ->tooltip(__('seo-content-ai::filament.projects.generated_tooltip')),

                Tables\Columns\TextColumn::make('active_pending_count')
                    ->label(__('seo-content-ai::filament.projects.pending_never_generated'))
                    ->numeric()
                    ->alignCenter()
                    ->sortable(),

                Tables\Columns\TextColumn::make('active_failed_count')
                    ->label(__('seo-content-ai::filament.projects.failed'))
                    ->numeric()
                    ->alignCenter()
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('seo-content-ai::filament.projects.status'))
                    ->badge()
                    ->formatStateUsing(function (string $state, SeoProject $record): string {
                        if ($record->isProjectArchived()) {
                            return __('seo-content-ai::filament.projects.status_archived');
                        }

                        return SeoProject::statusOptions()[$state] ?? $state;
                    })
                    ->color(fn (string $state, SeoProject $record): string => $record->isProjectArchived()
                        ? 'gray'
                        : match ($state) {
                            SeoProject::STATUS_PENDING => 'gray',
                            SeoProject::STATUS_MANUAL => 'info',
                            SeoProject::STATUS_RUNNING => 'warning',
                            SeoProject::STATUS_COMPLETED => 'success',
                            SeoProject::STATUS_PAUSED => 'danger',
                            SeoProject::STATUS_APPROVED => 'success',
                            default => 'gray',
                        }),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label(__('seo-content-ai::filament.projects.updated'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('month', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('seo-content-ai::filament.projects.status'))
                    ->options(SeoProject::statusOptions()),

                Tables\Filters\SelectFilter::make('user_id')
                    ->label(__('seo-content-ai::filament.projects.writer'))
                    ->options(fn (): array => static::userSelectOptions())
                    ->searchable()
                    ->preload()
                    ->native(false),

                Tables\Filters\SelectFilter::make('site_id')
                    ->label(__('seo-content-ai::filament.projects.domain'))
                    ->options(fn (): array => static::siteSelectOptions())
                    ->searchable()
                    ->preload()
                    ->native(false),

                Tables\Filters\Filter::make('month')
                    ->form([
                        Forms\Components\DatePicker::make('month')
                            ->label(__('seo-content-ai::filament.projects.month'))
                            ->native(false)
                            ->displayFormat('m/Y')
                            ->format('Y-m-d'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        if (empty($data['month'])) {
                            return $query;
                        }

                        $start = Carbon::parse($data['month'])->startOfMonth();

                        return $query->whereDate('month', $start->format('Y-m-d'));
                    }),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('open_project_items')
                        ->label(__('seo-content-ai::filament.projects.open_project_items'))
                        ->icon('heroicon-o-queue-list')
                        ->color('gray')
                        ->url(fn (SeoProject $record): string => static::getProjectWorkspaceUrl($record)),
                    Tables\Actions\Action::make('archive_project')
                        ->label(__('seo-content-ai::filament.projects.archive_project'))
                        ->icon('heroicon-o-archive-box')
                        ->color('warning')
                        ->visible(fn (SeoProject $record): bool => SeoAccessControl::canArchiveContentProjects()
                            && ! $record->isProjectArchived()
                            && ! $record->isArchive())
                        ->disabled(function (SeoProject $record): bool {
                            $gate = app(ArchiveContentProjectService::class)->archiveGate($record);

                            return ! $gate['can_archive'];
                        })
                        ->tooltip(function (SeoProject $record): ?string {
                            $gate = app(ArchiveContentProjectService::class)->archiveGate($record);

                            return $gate['can_archive'] ? null : (string) ($gate['blocked_reason'] ?? '');
                        })
                        ->modalHeading(fn (SeoProject $record): string => __('seo-content-ai::filament.projects.archive_project_heading_named', [
                            'name' => (string) $record->name,
                        ]))
                        ->modalDescription(function (SeoProject $record): HtmlString {
                            $summary = app(ArchiveContentProjectService::class)->buildSummary($record);
                            $gate = app(ArchiveContentProjectService::class)->archiveGate($record);

                            return new HtmlString(view('seo-content-ai::filament.resources.seo-project-resource.partials.archive-project-modal-summary', [
                                'summary' => $summary,
                                'gate' => $gate,
                            ])->render());
                        })
                        ->modalSubmitActionLabel(__('seo-content-ai::filament.projects.archive_project_submit'))
                        ->form(function (SeoProject $record): array {
                            $gate = app(ArchiveContentProjectService::class)->archiveGate($record);
                            $fields = [
                                Forms\Components\Textarea::make('note')
                                    ->label(__('seo-content-ai::filament.projects.archive_note'))
                                    ->placeholder(__('seo-content-ai::filament.projects.archive_note_placeholder'))
                                    ->rows(2)
                                    ->maxLength(500),
                            ];

                            if ($gate['requires_waiting_publish_confirm']) {
                                $fields[] = Forms\Components\Checkbox::make('confirm_waiting_publish')
                                    ->label(__('seo-content-ai::filament.projects.archive_waiting_publish_confirm', [
                                        'count' => $gate['waiting_publish'],
                                    ]))
                                    ->rule('accepted')
                                    ->required();
                            }

                            return $fields;
                        })
                        ->action(function (SeoProject $record, array $data): void {
                            try {
                                abort_unless(SeoAccessControl::canArchiveContentProjects(), 403);
                                abort_unless(SeoAccessControl::canAccessSite((int) ($record->site_id ?? 0)), 403);

                                $result = app(ContentProjectCommandBus::class)->dispatch(
                                    new ArchiveContentProjectCommand(
                                        (int) $record->getKey(),
                                        isset($data['note']) ? (string) $data['note'] : null,
                                        (bool) ($data['confirm_waiting_publish'] ?? false),
                                    ),
                                    ActorContext::user(
                                        auth()->id() !== null ? (int) auth()->id() : null,
                                        (int) ($record->site_id ?? 0) ?: null,
                                    ),
                                );

                                app(ContentProjectActionResultNotifier::class)->send($result);
                            } catch (\Throwable $exception) {
                                RuntimeLogger::report($exception, [
                                    'endpoint' => 'content_project.archive',
                                    'project_id' => (int) $record->getKey(),
                                ]);

                                Notification::make()
                                    ->title(__('seo-content-ai::filament.projects.archive_failed'))
                                    ->body($exception->getMessage())
                                    ->danger()
                                    ->send();
                            }
                        }),
                    // Deprecated entry point — ẩn; giữ để diagnose/tests cũ không gãy tên action.
                    Tables\Actions\Action::make('archive_project_articles')
                        ->label(__('seo-content-ai::filament.projects.archive_project'))
                        ->icon('heroicon-o-archive-box')
                        ->visible(false)
                        ->action(fn (): null => null),
                    Tables\Actions\DeleteAction::make()
                        ->visible(fn (SeoProject $record): bool => static::canDelete($record) && ! $record->isProjectArchived())
                        ->requiresConfirmation()
                        ->modalHeading(__('seo-content-ai::filament.projects.delete_heading'))
                        ->modalDescription(__('seo-content-ai::filament.projects.delete_description'))
                        ->modalSubmitActionLabel(__('seo-content-ai::filament.projects.delete_submit'))
                        ->successNotification(null)
                        ->using(function (SeoProject $record): bool {
                            try {
                                app(SeoProjectTaskMoveService::class)->deleteProject($record);

                                Notification::make()
                                    ->title(__('seo-content-ai::filament.projects.delete_completed'))
                                    ->body(__('seo-content-ai::filament.projects.delete_completed_body'))
                                    ->success()
                                    ->send();

                                return true;
                            } catch (ValidationException $exception) {
                                Notification::make()
                                    ->title(__('seo-content-ai::filament.projects.delete_blocked', [
                                        'name' => (string) $record->name,
                                    ]))
                                    ->body($exception->validator->errors()->first() ?: $exception->getMessage())
                                    ->danger()
                                    ->send();

                                throw $exception;
                            } catch (\Throwable $exception) {
                                RuntimeLogger::report($exception, ['project_id' => (int) $record->getKey()]);

                                Notification::make()
                                    ->title(__('seo-content-ai::filament.projects.delete_failed'))
                                    ->body($exception->getMessage())
                                    ->danger()
                                    ->send();

                                throw $exception;
                            }
                        }),
                ])
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->tooltip(__('seo-content-ai::filament.projects.more_actions'))
                    ->button()
                    ->color('gray'),
                Tables\Actions\ViewAction::make()
                    ->visible(fn (SeoProject $record): bool => static::canView($record) && ! static::canEdit($record))
                    ->url(fn (SeoProject $record): string => static::projectRecordUrl($record)),
                Tables\Actions\EditAction::make()
                    ->visible(fn (SeoProject $record): bool => static::canEdit($record))
                    ->url(fn (SeoProject $record): string => static::projectRecordUrl($record)),
            ])
            ->bulkActions(static::seoPanelBulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->visible(fn (): bool => SeoAccessControl::canMutateContentProjects())
                        ->requiresConfirmation()
                        ->modalHeading(__('seo-content-ai::filament.projects.delete_heading'))
                        ->modalDescription(__('seo-content-ai::filament.projects.delete_description'))
                        ->modalSubmitActionLabel(__('seo-content-ai::filament.projects.delete_submit'))
                        ->action(function (\Illuminate\Support\Collection $records): void {
                            $deletedTotal = 0;
                            $failed = 0;

                            foreach ($records as $record) {
                                if (! $record instanceof SeoProject) {
                                    continue;
                                }

                                try {
                                    app(SeoProjectTaskMoveService::class)->deleteProject($record);
                                    $deletedTotal++;
                                } catch (ValidationException $exception) {
                                    $failed++;
                                    Notification::make()
                                        ->title(__('seo-content-ai::filament.projects.delete_blocked', [
                                            'name' => (string) $record->name,
                                        ]))
                                        ->body($exception->validator->errors()->first() ?: $exception->getMessage())
                                        ->danger()
                                        ->send();
                                } catch (\Throwable $exception) {
                                    $failed++;
                                    RuntimeLogger::report($exception, ['project_id' => (int) $record->getKey()]);
                                    Notification::make()
                                        ->title(__('seo-content-ai::filament.projects.delete_failed'))
                                        ->body($exception->getMessage())
                                        ->danger()
                                        ->send();
                                }
                            }

                            if ($failed === 0 && $deletedTotal > 0) {
                                Notification::make()
                                    ->title(__('seo-content-ai::filament.projects.delete_completed'))
                                    ->body(__('seo-content-ai::filament.projects.delete_completed_body'))
                                    ->success()
                                    ->send();
                            }
                        }),
                ]),
            ]));
    }

    public static function getEloquentQuery(): Builder
    {
        // List hiện cả project đã archive (click → archive preview). Vault riêng vẫn giữ.
        // Staff/assign vẫn lọc active ở ContentProjectStaffAvailabilityService.
        $query = static::applyGlobalSiteScopeToProjectQuery(
            parent::getEloquentQuery()
                ->with(['user', 'site', 'currentArchive'])
                ->withCount([
                    'tasks as active_tasks_count' => static fn (Builder $sub): Builder => $sub->active(),
                    'tasks as active_generated_count' => static fn (Builder $sub): Builder => $sub
                        ->active()
                        ->where(static function (Builder $inner): void {
                            $inner->whereIn('status', [
                                SeoProjectTask::STATUS_COMPLETED,
                                SeoProjectTask::STATUS_REVIEWING,
                            ])->orWhere(static function (Builder $linked): void {
                                $linked->whereNotNull('article_id')->where('article_id', '>', 0);
                            });
                        }),
                    'tasks as active_pending_count' => static fn (Builder $sub): Builder => $sub
                        ->active()
                        ->where('status', SeoProjectTask::STATUS_PENDING)
                        ->where(static function (Builder $inner): void {
                            $inner->whereNull('article_id')->orWhere('article_id', '<=', 0);
                        }),
                    'tasks as active_failed_count' => static fn (Builder $sub): Builder => $sub
                        ->active()
                        ->where('status', SeoProjectTask::STATUS_FAILED),
                    'tasks as active_articles_count' => static fn (Builder $sub): Builder => $sub
                        ->active()
                        ->whereNotNull('article_id')
                        ->where('article_id', '>', 0),
                ]),
        );

        if (SeoAccessControl::isContentManager()) {
            $query->where('user_id', (int) auth()->id());
        }

        return $query;
    }

    /**
     * Detail/edit/preview route binding: không lọc theo global domain.
     * Global domain chỉ là UI context cho list/create default.
     */
    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['user', 'site']);

        if (SeoAccessControl::isContentManager()) {
            $query->where('user_id', (int) auth()->id());
        } elseif (SeoAccessControl::shouldScopeToAccountOwner()) {
            SeoAccessControl::applyAccessibleSiteScope($query);
        }

        return $query;
    }

    /**
     * Filament core resolveRecordRouteBinding() dùng getEloquentQuery() (có global site scope).
     * Wire vào getRecordRouteBindingEloquentQuery() để mở project khác domain đang chọn.
     */
    public static function resolveRecordRouteBinding(int|string $key): ?Model
    {
        return app(static::getModel())
            ->resolveRouteBindingQuery(
                static::getRecordRouteBindingEloquentQuery(),
                $key,
                static::getRecordRouteKeyName(),
            )
            ->first();
    }

    public static function applyGlobalSiteScopeToProjectQuery(Builder $query): Builder
    {
        if (! SeoAccessControl::shouldApplyGlobalSiteScope()) {
            return $query;
        }

        return $query->where('site_id', (int) SeoAccessControl::globalSiteId());
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalizeProjectSiteId(array $data): array
    {
        // Nếu form chưa có site_id (trường hợp ẩn cũ), fallback về global site
        if (empty($data['site_id'])) {
            $globalSiteId = SeoAccessControl::globalSiteId();
            if ($globalSiteId !== null) {
                $data['site_id'] = $globalSiteId;
            }
        }

        return $data;
    }

    public static function monthlyProjectExistsForSiteMonth(
        int $siteId,
        string $month,
        ?int $ignoreProjectId = null,
    ): bool {
        if ($siteId <= 0 || $month === '') {
            return false;
        }

        $monthStart = Carbon::parse($month)->startOfMonth()->format('Y-m-d');

        $query = SeoProject::query()
            ->where('site_id', $siteId)
            ->whereDate('month', $monthStart)
            ->where(function (Builder $builder): void {
                $builder
                    ->where('kind', SeoProject::KIND_MONTHLY)
                    ->orWhereNull('kind');
            });

        if ($ignoreProjectId !== null && $ignoreProjectId > 0) {
            $query->whereKeyNot($ignoreProjectId);
        }

        return $query->exists();
    }

    public static function getRelations(): array
    {
        // Operations table lives on ViewSeoProject (canonical). Edit = settings form only.
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSeoProjects::route('/'),
            'create' => Pages\CreateSeoProject::route('/create'),
            // Tab dự án đã lưu trữ + legacy bài lẻ. Preview: archive/{archive}/preview
            'archive' => Pages\ContentProjectArchive::route('/archive'),
            'archive-preview' => Pages\ContentProjectArchivePreview::route('/archive/{archive}/preview'),
            // Legacy run URLs — pages redirect to project workspace (ADR-004 UI cutover).
            'run-history' => Pages\ListSeoProjectRuns::route('/{record}/runs'),
            'view-run-step' => Pages\ViewSeoProjectRunStep::route('/runs/{run}/items/{article}'),
            'view-run' => Pages\ViewSeoProjectRun::route('/runs/{run}'),
            'publishing-queue' => Pages\ContentProjectPublishingQueue::route('/{record}/publishing-queue'),
            'view' => Pages\ViewSeoProject::route('/{record}'),
            'edit' => Pages\EditSeoProject::route('/{record}/edit'),
        ];
    }

    /**
     * Independent Publishing Queue hub (top-level page) scoped to a project.
     * The nested `content-projects/{id}/publishing-queue` route redirects here (compat).
     */
    public static function getPublishingQueueUrl(SeoProject $project): string
    {
        return PublishingQueueHub::getUrl(['projectId' => (int) $project->getKey()]);
    }

    /**
     * Canonical Content Project workspace (Project → Items). Replaces Run History URLs.
     */
    public static function getProjectWorkspaceUrl(SeoProject $project): string
    {
        return static::getUrl('view', ['record' => $project]);
    }

    /** @deprecated Use getProjectWorkspaceUrl — Run History UI removed. */
    public static function getRunHistoryUrl(SeoProject $project): string
    {
        return static::getProjectWorkspaceUrl($project);
    }

    /**
     * Dev-only Test generate UI — fail-closed outside local/testing + debug.
     */
    public static function allowsDevTestGenerateUi(): bool
    {
        if (app()->environment('production')) {
            return false;
        }

        return app()->hasDebugModeEnabled()
            && app()->environment(['local', 'testing']);
    }

    public static function workspaceTabQueryValue(string $tabKey): string
    {
        return self::PROJECT_WORKSPACE_TABS_ID.'-'.$tabKey.'-tab';
    }

    public static function projectArchivesUrl(SeoProject $project): string
    {
        return static::getUrl('archive');
    }

    public static function archivesCountFor(SeoProject $project): int
    {
        $siteId = (int) ($project->site_id ?? 0);
        if ($siteId <= 0) {
            return 0;
        }

        return app(ArticleCompletedArchiveQueryService::class)
            ->queryForSites([$siteId])
            ->count();
    }

    public static function formatTaskTimestamp(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        try {
            return Carbon::parse((string) $value)->format('d/m/Y H:i');
        } catch (\Throwable) {
            return '—';
        }
    }

    /**
     * @deprecated Project-level archive action removed from UI (archive quyết định ở cấp bài viết
     * qua ArticleReviewService). Giữ hàm + visible(false) để các page header (Edit/View/ListRuns)
     * không cần sửa từng nơi, và SeoProjectArchiveService::archiveProject vẫn còn cho diagnose/tests.
     */
    public static function makeArchiveProjectPageAction(SeoProject $project): \Filament\Actions\Action
    {
        return \Filament\Actions\Action::make('archive_project_articles')
            ->label(__('seo-content-ai::filament.projects.archive_project'))
            ->icon('heroicon-o-archive-box')
            ->color('warning')
            ->visible(false)
            ->modalHeading(__('seo-content-ai::filament.projects.archive_project_heading'))
            ->modalDescription(__('seo-content-ai::filament.projects.archive_project_description'))
            ->modalSubmitActionLabel(__('seo-content-ai::filament.projects.archive_project_submit'))
            ->form([
                Forms\Components\Textarea::make('note')
                    ->label(__('seo-content-ai::filament.projects.archive_note'))
                    ->placeholder(__('seo-content-ai::filament.projects.archive_note_placeholder'))
                    ->rows(2)
                    ->maxLength(500),
            ])
            ->action(function (array $data) use ($project): void {
                try {
                    $result = app(SeoProjectArchiveService::class)
                        ->archiveProject(
                            $project,
                            (int) auth()->id(),
                            isset($data['note']) ? (string) $data['note'] : null,
                        );

                    Notification::make()
                        ->title(__('seo-content-ai::filament.projects.archive_completed'))
                        ->body(__('seo-content-ai::filament.projects.archive_completed_body', $result))
                        ->success()
                        ->send();
                } catch (\Throwable $exception) {
                    report($exception);

                    Notification::make()
                        ->title(__('seo-content-ai::filament.projects.archive_failed'))
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    public static function getLatestRunUrl(SeoProject $project): ?string
    {
        // Do not deep-link into legacy run detail — stay on project items workspace.
        return static::getProjectWorkspaceUrl($project);
    }

    public static function canGeneratePendingItems(SeoProject $project): bool
    {
        if ($project->isProjectArchived() || $project->isArchive()) {
            return false;
        }

        if (! SeoAccessControl::canAccessContentProjectRun($project)) {
            return false;
        }

        $preview = app(\Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectItemGenerationClassifier::class)
            ->preview($project);

        return $preview->runCount() > 0;
    }

    /**
     * Start generate via CommandBus (handler creates run, prepares queue, starts RunEngine once).
     *
     * @param  array<string, mixed>|null  $settings
     */
    public static function startGeneratePendingItems(SeoProject $project, string $mode, ?array $settings = null): SeoProjectRun
    {
        $settings = is_array($settings) ? $settings : [];
        $settings['use_php_engine'] = true;

        return static::createProjectWorkflowRun($project, $mode, $settings);
    }

    /**
     * @param  (\Closure(): array<string, mixed>)|null  $launchSettings
     *         Optional page-level launch settings resolver (e.g. generate_post_images from ViewSeoProject).
     */
    public static function makeGeneratePendingItemsAction(
        SeoProject $project,
        ?\Closure $launchSettings = null,
    ): \Filament\Actions\Action {
        return \Filament\Actions\Action::make('generate_pending_items')
            ->label(__('seo-content-ai::filament.projects.generate_working_items'))
            ->icon('heroicon-o-play')
            ->color('success')
            ->visible(fn (): bool => SeoAccessControl::canAccessContentProjectRun($project))
            ->disabled(fn (): bool => ! static::canGeneratePendingItems($project))
            ->tooltip(fn (): ?string => static::canGeneratePendingItems($project)
                ? null
                : __('seo-content-ai::filament.projects.run_workflow_disabled'))
            ->modalHeading(__('seo-content-ai::filament.projects.generate_pending_preview_heading'))
            ->modalDescription(fn () => static::generatePendingPreviewHtml($project))
            ->form([
                Forms\Components\Checkbox::make('technical_confirm_full_rerun')
                    ->label(__('seo-content-ai::filament.projects.generate_pending_technical_confirm'))
                    ->helperText(__('seo-content-ai::filament.projects.generate_pending_technical_confirm_help'))
                    ->visible(function () use ($project): bool {
                        $preview = app(\Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectItemGenerationClassifier::class)
                            ->preview($project);

                        return $preview->failClosed;
                    })
                    ->default(false),
            ])
            ->action(function (array $data) use ($project, $launchSettings): void {
                try {
                    $preview = app(\Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectItemGenerationClassifier::class)
                        ->preview($project);

                    if ($preview->failClosed && ! (bool) ($data['technical_confirm_full_rerun'] ?? false)) {
                        Notification::make()
                            ->title(__('seo-content-ai::filament.projects.run_failed'))
                            ->body(__('seo-content-ai::filament.projects.generate_pending_fail_closed'))
                            ->danger()
                            ->send();

                        return;
                    }

                    if (! $preview->canDispatch() && ! $preview->failClosed) {
                        Notification::make()
                            ->title(__('seo-content-ai::filament.projects.run_failed'))
                            ->body(__('seo-content-ai::filament.projects.run_items_empty'))
                            ->danger()
                            ->send();

                        return;
                    }

                    $taskIds = $preview->runnableTaskIds();
                    if ($preview->failClosed && (bool) ($data['technical_confirm_full_rerun'] ?? false)) {
                        // Technical override still only runs classifier-runnable IDs (never improve / evidence).
                        $taskIds = $preview->runnableTaskIds();
                    }

                    $extra = is_callable($launchSettings) ? (array) $launchSettings() : [];

                    static::startGeneratePendingItems(
                        $project,
                        SeoProjectRun::MODE_FULL,
                        [
                            'generate_post_images' => (bool) ($extra['generate_post_images'] ?? false),
                            'use_php_engine' => true,
                            'task_ids' => $taskIds,
                            'technical_confirm_full_rerun' => (bool) ($data['technical_confirm_full_rerun'] ?? false),
                        ],
                    );

                    SeoConnectionContext::applyUrlDefaults();

                    Notification::make()
                        ->title(__('seo-content-ai::filament.projects.run_started'))
                        ->body(__('seo-content-ai::filament.projects.generate_pending_started_body'))
                        ->success()
                        ->send();
                } catch (\InvalidArgumentException $exception) {
                    Notification::make()
                        ->title(__('seo-content-ai::filament.projects.run_failed'))
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();
                } catch (\Throwable $exception) {
                    Notification::make()
                        ->title(__('seo-content-ai::filament.projects.run_failed'))
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    public static function generatePendingPreviewHtml(SeoProject $project): HtmlString
    {
        $preview = app(\Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectItemGenerationClassifier::class)
            ->preview($project);

        $lines = [
            __('seo-content-ai::filament.projects.generate_pending_preview_total', ['count' => $preview->totalItems]),
            __('seo-content-ai::filament.projects.generate_pending_preview_run', ['count' => $preview->runCount()]),
            __('seo-content-ai::filament.projects.generate_pending_preview_skip', ['count' => count($preview->skipDecisions())]),
            __('seo-content-ai::filament.projects.generate_pending_preview_anomaly', ['count' => count($preview->anomalyDecisions())]),
        ];

        if ($preview->failClosed) {
            $lines[] = __('seo-content-ai::filament.projects.generate_pending_fail_closed');
        }

        $skipSample = array_slice($preview->skipDecisions(), 0, 8);
        foreach ($skipSample as $decision) {
            $lines[] = '#'.$decision->taskId.' — '.$decision->reason
                .($decision->keyword ? ' ('.$decision->keyword.')' : '');
        }

        $html = '<ul class="list-disc pl-5 space-y-1">';
        foreach ($lines as $line) {
            $html .= '<li>'.e((string) $line).'</li>';
        }
        $html .= '</ul>';

        return new HtmlString($html);
    }

    /**
     * @param  (\Closure(): array<string, mixed>)|null  $launchSettings
     */
    public static function makeDevTestGeneratePendingItemsAction(
        SeoProject $project,
        ?\Closure $launchSettings = null,
    ): \Filament\Actions\Action
    {
        return \Filament\Actions\Action::make('test_generate_pending_items')
            ->label(__('seo-content-ai::filament.projects.test_run_workflow'))
            ->icon('heroicon-o-beaker')
            ->color('warning')
            ->visible(fn (): bool => static::allowsDevTestGenerateUi()
                && SeoAccessControl::canAccessContentProjectRun($project))
            ->disabled(fn (): bool => ! static::canGeneratePendingItems($project))
            ->modalHeading(__('seo-content-ai::filament.projects.test_run_workflow_heading', [
                'limit' => SeoProjectWorkflowRunService::TEST_RUN_LIMIT,
            ]))
            ->modalDescription(fn () => static::runWorkflowModalDescription(
                $project,
                SeoProjectWorkflowRunService::TEST_RUN_LIMIT,
            ))
            ->requiresConfirmation()
            ->action(function () use ($project, $launchSettings): void {
                try {
                    $extra = is_callable($launchSettings) ? (array) $launchSettings() : [];
                    static::startGeneratePendingItems(
                        $project,
                        SeoProjectRun::MODE_TEST,
                        [
                            'generate_post_images' => (bool) ($extra['generate_post_images'] ?? false),
                            'use_php_engine' => true,
                        ],
                    );

                    Notification::make()
                        ->title(__('seo-content-ai::filament.projects.run_started'))
                        ->body(__('seo-content-ai::filament.projects.generate_pending_started_body'))
                        ->success()
                        ->send();
                } catch (\Throwable $exception) {
                    Notification::make()
                        ->title(__('seo-content-ai::filament.projects.run_failed'))
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    public static function runWorkflowModalDescription(SeoProject $project, ?int $pendingLimit = null): HtmlString
    {
        $base = $pendingLimit !== null
            ? __('seo-content-ai::filament.projects.test_run_workflow_description', [
                'limit' => $pendingLimit,
            ])
            : __('seo-content-ai::filament.projects.run_workflow_description');

        $warnings = app(SeoProjectRunPreflightService::class)
            ->formatWarningsForModal($project, $pendingLimit);

        return new HtmlString('<p>'.e($base).'</p>'.$warnings->toHtml());
    }

    public static function createProjectWorkflowRun(SeoProject $project, string $mode, ?array $settings = null): SeoProjectRun
    {
        $itemRefs = [];
        $runSettings = [];
        if (is_array($settings)) {
            if (isset($settings['task_ids']) && is_array($settings['task_ids'])) {
                $itemRefs = array_values($settings['task_ids']);
            }
            $runSettings = $settings;
            unset($runSettings['task_ids'], $runSettings['technical_confirm_full_rerun']);
        }

        $result = app(ContentProjectCommandBus::class)->dispatch(
            new GenerateProjectItemsCommand(
                (int) $project->getKey(),
                $itemRefs,
                $mode,
                (bool) (($settings['technical_confirm_full_rerun'] ?? false)),
                $runSettings,
            ),
            ActorContext::user(
                auth()->id() !== null ? (int) auth()->id() : null,
                (int) ($project->site_id ?? 0) ?: null,
            ),
        );

        if (! $result->success) {
            throw new \RuntimeException($result->message);
        }

        $executionRef = $result->metadata['execution_ref'] ?? null;
        if (! is_string($executionRef) || $executionRef === '') {
            throw new \RuntimeException('Generate command did not return execution_ref.');
        }

        $runId = ContentProjectPublicRef::decodeExecution($executionRef);
        $run = SeoProjectRun::query()->find($runId);
        if (! $run instanceof SeoProjectRun) {
            throw new \RuntimeException('Workflow run not found after generate command.');
        }

        return $run;
    }

    public static function dispatchProjectWorkflowRun(SeoProject $project, string $mode): mixed
    {
        try {
            static::startGeneratePendingItems($project, $mode, ['use_php_engine' => true]);

            Notification::make()
                ->title(__('seo-content-ai::filament.projects.run_started'))
                ->body(__('seo-content-ai::filament.projects.generate_pending_started_body'))
                ->success()
                ->send();

            return redirect(static::getProjectWorkspaceUrl($project));
        } catch (\InvalidArgumentException $exception) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.run_failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return null;
        } catch (\Throwable $exception) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.run_failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return null;
        }
    }

    /**
     * @return array<int, string>
     */
    public static function userSelectOptions(): array
    {
        $grouped = static::groupedWriterSelectOptions();
        $flat = [];

        foreach ($grouped as $options) {
            if (! is_array($options)) {
                continue;
            }
            foreach ($options as $id => $label) {
                $flat[(int) $id] = (string) $label;
            }
        }

        return $flat;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function groupedWriterSelectOptions(?string $month = null): array
    {
        $service = app(\Omnichannel\Addons\ContentProjects\Services\ContentProjectStaffAvailabilityService::class);
        $grouped = $service->groupedSelectOptions($month);
        $result = [];

        if ($grouped['unassigned'] !== []) {
            $result[(string) __('seo-content-ai::filament.projects.unassigned_staff_heading')] = $grouped['unassigned'];
        }

        if ($grouped['assigned'] !== []) {
            $result[(string) __('seo-content-ai::filament.projects.assigned_staff_heading')] = $grouped['assigned'];
        }

        if ($result === []) {
            return ['' => static::legacyUserSelectOptions()];
        }

        return $result;
    }

    /**
     * @return array<int, string>
     */
    public static function legacyUserSelectOptions(): array
    {
        $query = User::query()
            ->where('seo_role', User::SEO_ROLE_CONTENT_MANAGER)
            ->where('status', User::STATUS_NORMAL);

        if (auth()->user()?->role !== User::ROLE_ADMIN) {
            $ownerId = SeoAccessControl::accountOwnerId() ?? (int) auth()->id();
            $query->where(function (Builder $users) use ($ownerId): void {
                $users->whereKey($ownerId)->orWhere('parent_id', $ownerId);
            });
        }

        return $query
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->mapWithKeys(static fn (User $user): array => [
                (int) $user->id => static::formatUserSelectLabel($user),
            ])
            ->all();
    }

    public static function formatUserSelectLabel(User $user): string
    {
        return app(\Omnichannel\Addons\ContentProjects\Services\ContentProjectStaffAvailabilityService::class)
            ->formatLabel($user);
    }

    /**
     * @return array<string, string> title => label
     */
    public static function searchArticlesForRewriteTitle(string $search, ?int $siteId): array
    {
        $search = trim($search);

        $query = ArticleResource::getEloquentQuery()
            ->with(['site', 'articleMetas']);

        if ($siteId !== null && $siteId > 0) {
            $query->where('site_id', $siteId);
        }

        if ($search !== '') {
            $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $search);

            $query->where(function (Builder $inner) use ($search, $escaped): void {
                $inner->where('title', 'like', '%'.$escaped.'%');

                if (ctype_digit($search)) {
                    $inner->orWhere('id', (int) $search);
                }
            });
        }

        $options = $query
            ->orderByDesc('updated_at')
            ->limit($search === '' ? 20 : 50)
            ->get()
            ->mapWithKeys(function (SeoArticle $article): array {
                $title = trim((string) $article->title);

                if ($title === '') {
                    return [];
                }

                return [$title => static::formatRewriteArticleOptionLabel($article)];
            })
            ->all();

        if ($search !== '' && ! array_key_exists($search, $options)) {
            $options = [
                $search => __('seo-content-ai::filament.projects.rewrite_article_use_typed_title', [
                    'title' => $search,
                ]),
            ] + $options;
        }

        return $options;
    }

    public static function formatRewriteArticleOptionLabel(SeoArticle $article): string
    {
        $domain = trim((string) ($article->site?->domain ?? ''));

        $permalink = '';
        if ($article->relationLoaded('articleMetas')) {
            $meta = $article->articleMetas->firstWhere('meta_key', 'wp_permalink');
            $permalink = trim((string) ($meta?->meta_value ?? ''));
        }

        $base = sprintf(
            '#%d · %s (%s)',
            $article->id,
            (string) $article->title,
            $domain !== '' ? $domain : '—',
        );

        if ($permalink !== '') {
            return $base.' — '.$permalink;
        }

        return $base;
    }

    public static function rewriteArticleWpLinkHelper(mixed $title, ?int $siteId = null): ?HtmlString
    {
        if (! is_string($title) || trim($title) === '') {
            return null;
        }

        $permalink = static::resolveArticlePermalinkByTitle(trim($title), $siteId);

        if ($permalink === null) {
            return null;
        }

        $url = e($permalink);

        return new HtmlString(
            '<a href="'.$url.'" target="_blank" rel="noopener noreferrer" class="text-sm font-medium text-primary-600 hover:underline dark:text-primary-400">View WP article</a>'
        );
    }

    public static function resolveArticlePermalinkByTitle(string $title, ?int $siteId = null): ?string
    {
        $query = SeoArticle::query()->with('articleMetas')
            ->where('title', $title);

        if ($siteId !== null && $siteId > 0) {
            $query->where('site_id', $siteId);
        }

        $article = $query->orderByDesc('updated_at')->first();

        if ($article === null) {
            return null;
        }

        $meta = $article->articleMetas->firstWhere('meta_key', 'wp_permalink');

        $permalink = trim((string) ($meta?->meta_value ?? ''));

        return $permalink !== '' ? $permalink : null;
    }

    public static function resolveRepeaterSiteId(Get $get): ?int
    {
        foreach (['../../site_id', '../site_id', 'site_id'] as $path) {
            $siteId = $get($path);

            if ($siteId !== null && $siteId !== '') {
                return (int) $siteId;
            }
        }

        return SeoAccessControl::globalSiteId();
    }

    /**
     * @return array<string, string>
     */
    public static function postTypeSelectOptions(): array
    {
        return [
            SeoProjectTask::POST_TYPE_ARTICLE => __('seo-content-ai::filament.article_list.post_type_article'),
            SeoProjectTask::POST_TYPE_PRODUCT => __('seo-content-ai::filament.article_list.post_type_product'),
            SeoProjectTask::POST_TYPE_CATEGORY => __('seo-content-ai::filament.article_list.post_type_category'),
            SeoProjectTask::POST_TYPE_PRODUCT_CATEGORY => __('seo-content-ai::filament.article_list.post_type_product_category'),
        ];
    }

    public static function siteSelectOptions(): array
    {
        $query = Site::query()->orderBy('domain');

        if (SeoAccessControl::shouldScopeToAccountOwner()) {
            $query->where('user_id', SeoAccessControl::accountSiteOwnerId());
        }

        return $query->pluck('domain', 'id')->all();
    }

    public static function appendKeywordsToFormState(Get $get, Set $set, string $rawText): void
    {
        $month = $get('month');
        if (! $month) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.select_month_first'))
                ->warning()
                ->send();

            return;
        }

        $keywords = app(SeoProjectKeywordListParser::class)->parse($rawText);
        if ($keywords === []) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.no_valid_keywords'))
                ->warning()
                ->send();

            return;
        }

        $merged = app(SeoProjectKeywordListParser::class)->appendKeywordsToTasks(
            is_array($get('tasks_data')) ? $get('tasks_data') : [],
            $keywords,
        );

        try {
            app(SeoProjectTaskSyncService::class)->assertWithinMonthlyLimit($month, $merged);
        } catch (ValidationException $exception) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.monthly_limit_exceeded'))
                ->body($exception->validator->errors()->first('tasks_data') ?? '')
                ->danger()
                ->send();

            return;
        }

        $set('tasks_data', $merged);

        Notification::make()
            ->title(__('seo-content-ai::filament.projects.added_keywords', ['count' => count($keywords)]))
            ->success()
            ->send();
    }

    public static function canAddTaskRowToForm(Get $get, ?SeoProject $record = null): bool
    {
        if ($record instanceof SeoProject && $record->isArchive()) {
            return true;
        }

        $month = $get('month');
        if (! $month) {
            return true;
        }

        try {
            $max = app(SeoProjectTaskSyncService::class)->maxTasksForMonth($month);
        } catch (\Throwable) {
            return true;
        }

        $tasksData = is_array($get('tasks_data')) ? $get('tasks_data') : [];
        $count = app(SeoProjectTaskSyncService::class)->countEffectiveTasks($tasksData);

        return $count < $max;
    }

    public static function addTaskRowTooltip(Get $get, ?SeoProject $record = null): ?string
    {
        if (static::canAddTaskRowToForm($get, $record)) {
            return null;
        }

        $month = $get('month');
        try {
            $max = $month ? app(SeoProjectTaskSyncService::class)->maxTasksForMonth($month) : 0;
        } catch (\Throwable) {
            $max = 0;
        }

        return __('seo-content-ai::filament.projects.maximum_items', ['max' => $max]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function generateKeywordsWithAi(Get $get, Set $set, array $data): void
    {
        $month = $get('month');
        if (! $month) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.select_month_first'))
                ->warning()
                ->send();

            return;
        }

        $existing = is_array($get('tasks_data')) ? $get('tasks_data') : [];
        $syncService = app(SeoProjectTaskSyncService::class);
        $maxMonth = $syncService->maxTasksForMonth($month);
        $remaining = max(0, $maxMonth - $syncService->countEffectiveTasks($existing));

        if ($remaining === 0) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.monthly_capacity_reached'))
                ->body(__('seo-content-ai::filament.projects.maximum_items', ['max' => $maxMonth]))
                ->warning()
                ->send();

            return;
        }

        $requested = min($remaining, max(1, (int) ($data['count'] ?? 10)));

        try {
            $keywords = app(SeoProjectKeywordAiGeneratorService::class)->generate(
                month: $month,
                count: $requested,
                brief: (string) ($data['brief'] ?? ''),
                description: (string) ($get('description') ?? ''),
            );
        } catch (\InvalidArgumentException $exception) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.unable_to_generate_keywords'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        $merged = app(SeoProjectKeywordListParser::class)->appendKeywordsToTasks($existing, $keywords);

        try {
            app(SeoProjectTaskSyncService::class)->assertWithinMonthlyLimit($month, $merged);
        } catch (ValidationException $exception) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.monthly_limit_exceeded'))
                ->body($exception->validator->errors()->first('tasks_data') ?? '')
                ->danger()
                ->send();

            return;
        }

        $set('tasks_data', $merged);

        Notification::make()
            ->title(__('seo-content-ai::filament.projects.ai_added_keywords', ['count' => count($keywords)]))
            ->success()
            ->send();
    }
}
