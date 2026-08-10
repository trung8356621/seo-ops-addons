<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Filament\Pages;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationExecutionStatus;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\BusinessHookErrorCode;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationExecution;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Services\AutomationExecutionService;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Services\AutomationGraphExecutionService;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Services\AutomationStaleRecoveryService;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Services\ExecutionCleanupService;
use Omnichannel\Addons\Seo\Filament\Concerns\BelongsToAdminAutomationPanel;
use Omnichannel\Addons\Seo\Filament\Concerns\RedirectsSeoAutomationToAdmin;
use Omnichannel\Addons\Agent\Filament\Resources\AutomationExecutionResource;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

class AutomationOperationsDashboard extends Page implements HasTable
{
    use BelongsToAdminAutomationPanel;
    use InteractsWithTable;
    use RedirectsSeoAutomationToAdmin;

    private const STALE_SECONDS = 900;

    protected static ?string $slug = 'automation/operations';

    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = null;

    protected static ?string $title = 'Automation Operations';

    protected static string $view = 'seo-content-ai::filament.pages.automation-operations-dashboard';

    #[Url]
    public string $filter = 'all';

    /** @var array<string, int> */
    public array $counters = [];

    /**
     * @param  array<string, mixed>  $parameters
     */
    public static function getUrl(
        array $parameters = [],
        bool $isAbsolute = true,
        ?string $panel = null,
        ?\Illuminate\Database\Eloquent\Model $tenant = null,
    ): string {
        return parent::getUrl(
            $parameters,
            $isAbsolute,
            $panel ?? self::adminPanelId(),
            $tenant,
        );
    }

    public static function getNavigationLabel(): string
    {
        return __('seo-content-ai::filament.automation.nav_operations');
    }

    public function mount(): void
    {
        if ($this->redirectSeoAutomationToAdmin(static::getUrl())) {
            return;
        }

        abort_unless(SeoAccessControl::canViewAutomation(), 403);
        $this->refreshCounters();
    }

    public static function canAccess(): bool
    {
        return SeoAccessControl::canViewAutomation();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\ActionGroup::make([
                $this->clearLogsHeaderAction('clearCompleted', __('seo-content-ai::filament.automation.clear_completed'), 'completed'),
                $this->clearLogsHeaderAction('clearFailed', __('seo-content-ai::filament.automation.clear_failed'), 'failed'),
                $this->clearLogsHeaderAction('clearPartial', __('seo-content-ai::filament.automation.clear_partial'), 'partial'),
                $this->clearLogsHeaderAction('clearAll', __('seo-content-ai::filament.automation.clear_all'), 'all')
                    ->color('danger'),
            ])
                ->label(__('seo-content-ai::filament.automation.clear_logs'))
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->button()
                ->visible(fn (): bool => SeoAccessControl::canClearAutomationLogs()),
        ];
    }

    private function clearLogsHeaderAction(string $name, string $label, string $scope): Actions\Action
    {
        return Actions\Action::make($name)
            ->label($label)
            ->requiresConfirmation()
            ->modalHeading(__('seo-content-ai::filament.automation.clear_execution_logs_title'))
            ->modalDescription(__('seo-content-ai::filament.automation.clear_execution_logs_body'))
            ->modalSubmitActionLabel(__('seo-content-ai::filament.automation.clear_delete'))
            ->action(fn () => $this->clearLogs($scope, app(ExecutionCleanupService::class)));
    }

    public function refreshCounters(): void
    {
        $base = AutomationExecution::query();

        $this->counters = [
            'all' => (clone $base)->count(),
            'completed' => (clone $base)->where('status', AutomationExecutionStatus::Completed->value)->count(),
            'failed' => (clone $base)->where('status', AutomationExecutionStatus::Failed->value)->count(),
            'partial' => (clone $base)->where('status', AutomationExecutionStatus::Partial->value)->count(),
            'processing' => (clone $base)->where('status', AutomationExecutionStatus::Processing->value)->count(),
            'stale' => (clone $base)
                ->where('status', AutomationExecutionStatus::Processing->value)
                ->where(function (Builder $query): void {
                    $query->whereNull('heartbeat_at')
                        ->orWhere('heartbeat_at', '<', now()->subSeconds(self::STALE_SECONDS));
                })
                ->where('started_at', '<', now()->subSeconds(self::STALE_SECONDS))
                ->count(),
            'dead_letter' => (clone $base)
                ->where('status', AutomationExecutionStatus::Failed->value)
                ->where(function (Builder $query): void {
                    $query->where('attempt', '>=', 3)
                        ->orWhereIn('error_code', [
                            BusinessHookErrorCode::ExecutionStale->value,
                            BusinessHookErrorCode::NodeStale->value,
                            BusinessHookErrorCode::NodeRecoveryUnsafe->value,
                        ]);
                })
                ->count(),
            'cancelled' => (clone $base)->where('status', AutomationExecutionStatus::Cancelled->value)->count(),
        ];
    }

    public function setFilter(string $filter): void
    {
        $this->filter = $filter;
        $this->resetTable();
    }

    public function clearLogs(string $scope, ExecutionCleanupService $cleanup): void
    {
        SeoAccessControl::guardAutomationClearLogs();

        match ($scope) {
            'completed' => $cleanup->clearCompleted(),
            'failed' => $cleanup->clearFailed(),
            'partial' => $cleanup->clearPartial(),
            'all' => $cleanup->clearAll(),
            default => abort(400, 'Invalid clear scope.'),
        };

        $this->refreshCounters();
        $this->resetTable();

        Notification::make()
            ->title(__('seo-content-ai::filament.automation.clear_logs_success'))
            ->success()
            ->send();
    }

    public function recoverStale(AutomationStaleRecoveryService $recovery): void
    {
        SeoAccessControl::guardAutomationRetry();

        $stats = $recovery->recover();
        $this->refreshCounters();

        Notification::make()
            ->title('Stale recovery finished')
            ->body(sprintf(
                'Executions: %d · Nodes: %d · Scheduled: %d · Missed: %d',
                $stats['executions'],
                $stats['nodes'],
                $stats['scheduled'],
                $stats['missed'],
            ))
            ->success()
            ->send();
    }

    public function retryExecution(int $executionId, AutomationExecutionService $linearRetry, AutomationGraphExecutionService $graphRetry): void
    {
        SeoAccessControl::guardAutomationRetry();

        try {
            $execution = AutomationExecution::query()->with('rule')->findOrFail($executionId);
            if ($execution->rule?->isGraphMode()) {
                $graphRetry->retryExecution($executionId);
            } else {
                $linearRetry->retry($executionId);
            }

            $this->refreshCounters();

            Notification::make()
                ->title('Retry queued')
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Retry failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function cancelExecution(int $executionId, AutomationGraphExecutionService $graphService): void
    {
        SeoAccessControl::guardAutomationCancel();

        try {
            $graphService->cancelExecution($executionId);
            $this->refreshCounters();

            Notification::make()
                ->title('Cancellation requested')
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Cancel failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->filteredQuery())
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('execution_uuid')->limit(12)->tooltip(fn ($record) => $record->execution_uuid),
                Tables\Columns\TextColumn::make('rule.code')->label('Rule')->searchable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        AutomationExecutionStatus::Failed->value => 'danger',
                        AutomationExecutionStatus::Partial->value => 'warning',
                        AutomationExecutionStatus::Processing->value => 'info',
                        AutomationExecutionStatus::Pending->value => 'gray',
                        AutomationExecutionStatus::Cancelled->value,
                        AutomationExecutionStatus::Skipped->value => 'gray',
                        AutomationExecutionStatus::Completed->value => 'success',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('attempt')->sortable(),
                Tables\Columns\TextColumn::make('error_code')->limit(20)->toggleable(),
                Tables\Columns\TextColumn::make('started_at')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('finished_at')->dateTime()->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('heartbeat_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                Tables\Actions\Action::make('view')
                    ->icon('heroicon-o-eye')
                    ->url(fn (AutomationExecution $record): string => AutomationExecutionResource::getUrl('view', ['record' => $record])),
                Tables\Actions\Action::make('retry')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (AutomationExecution $record): bool => SeoAccessControl::canRetryAutomationExecution()
                        && in_array($record->status, [
                            AutomationExecutionStatus::Failed->value,
                            AutomationExecutionStatus::Partial->value,
                        ], true))
                    ->requiresConfirmation()
                    ->action(fn (AutomationExecution $record) => $this->retryExecution((int) $record->id, app(AutomationExecutionService::class), app(AutomationGraphExecutionService::class))),
                Tables\Actions\Action::make('cancel')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (AutomationExecution $record): bool => SeoAccessControl::canCancelAutomationExecution()
                        && in_array($record->status, [
                            AutomationExecutionStatus::Pending->value,
                            AutomationExecutionStatus::Processing->value,
                        ], true))
                    ->requiresConfirmation()
                    ->action(fn (AutomationExecution $record) => $this->cancelExecution((int) $record->id, app(AutomationGraphExecutionService::class))),
            ])
            ->poll('10s');
    }

    private function filteredQuery(): Builder
    {
        $query = AutomationExecution::query()->with(['rule']);

        return match ($this->filter) {
            'completed' => $query->where('status', AutomationExecutionStatus::Completed->value),
            'failed' => $query->where('status', AutomationExecutionStatus::Failed->value),
            'partial' => $query->where('status', AutomationExecutionStatus::Partial->value),
            'processing' => $query->where('status', AutomationExecutionStatus::Processing->value),
            'stale' => $query
                ->where('status', AutomationExecutionStatus::Processing->value)
                ->where(function (Builder $q): void {
                    $q->whereNull('heartbeat_at')
                        ->orWhere('heartbeat_at', '<', now()->subSeconds(self::STALE_SECONDS));
                })
                ->where('started_at', '<', now()->subSeconds(self::STALE_SECONDS)),
            'dead_letter' => $query
                ->where('status', AutomationExecutionStatus::Failed->value)
                ->where(function (Builder $q): void {
                    $q->where('attempt', '>=', 3)
                        ->orWhereIn('error_code', [
                            BusinessHookErrorCode::ExecutionStale->value,
                            BusinessHookErrorCode::NodeStale->value,
                            BusinessHookErrorCode::NodeRecoveryUnsafe->value,
                        ]);
                }),
            'cancelled' => $query->where('status', AutomationExecutionStatus::Cancelled->value),
            default => $query,
        };
    }
}
