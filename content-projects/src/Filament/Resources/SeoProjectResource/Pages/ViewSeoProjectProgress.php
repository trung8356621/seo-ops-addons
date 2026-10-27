<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\StopProjectExecutionCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResultNotifier;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBus;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectProgressReadModel;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly\GlobalGenerationLock;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Support\RuntimeLogger;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Throwable;

/**
 * Content Project Progress — music-player style run queue (latest run read-model).
 * Owns Start Generate + AI Mode controls (Details page no longer hosts them).
 */
final class ViewSeoProjectProgress extends Page
{
    protected static string $resource = SeoProjectResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.seo-project-resource.pages.view-seo-project-progress';

    protected static bool $shouldRegisterNavigation = false;

    public int|string $record = 0;

    public ?SeoProject $project = null;

    /** @var array<string, mixed>|null */
    public ?array $progressSnapshot = null;

    /**
     * Article generation mode for Start Generate — normal|free_only.
     * Persisted via ArticleGenerationModePreference (same as former Details header).
     */
    public string $articleGenerationMode = 'normal';

    public bool $generatePostImages = false;

    public function mount(int|string $record): void
    {
        $this->record = $record;
        $this->project = $this->resolveProject($record);
        abort_unless(SeoProjectResource::canView($this->project), 403);

        if (SeoAccessControl::isContentManager() || ! SeoAccessControl::canAccessContentProjectRun($this->project)) {
            $this->redirect(SeoProjectResource::getDetailsUrl($this->project));

            return;
        }

        if ($this->project->isArchive() || $this->project->isProjectArchived()) {
            $this->redirect(SeoProjectResource::getUrl('index'));

            return;
        }

        $this->syncArticleGenerationModePreference();
        $this->reloadProgress();
    }

    public function getTitle(): string|Htmlable
    {
        return (string) ($this->project?->name ?? __('seo-content-ai::filament.projects.navigation'));
    }

    public function getHeading(): string|Htmlable
    {
        return $this->getTitle();
    }

    public function getSubheading(): string|Htmlable|null
    {
        $project = $this->project;
        if (! $project instanceof SeoProject) {
            return null;
        }

        $parts = [
            (string) ($project->user?->name ?? '—'),
        ];
        if (! $project->isDraftPlanning()) {
            $parts[] = (string) ($project->month?->format('m/Y') ?? '—');
        }

        return implode(' · ', $parts);
    }

    /**
     * @return array<string, mixed>
     */
    public function getProgressPayloadProperty(): array
    {
        if (! is_array($this->progressSnapshot)) {
            $this->reloadProgress();
        }

        return is_array($this->progressSnapshot) ? $this->progressSnapshot : [];
    }

    public function refreshProgress(): void
    {
        $this->reloadProgress();
    }

    public function shouldPollProgress(): bool
    {
        $run = is_array($this->progressSnapshot['run'] ?? null)
            ? $this->progressSnapshot['run']
            : null;

        return (bool) ($run['should_poll'] ?? false);
    }

    public function setArticleGenerationMode(string $mode): void
    {
        if ($this->generateControlsLocked()) {
            return;
        }

        $policy = \Omnichannel\Addons\AiPrompt\Support\AiCostPolicy::tryFromMixed($mode);
        $this->articleGenerationMode = $policy->generationModeValue();

        $userId = (int) (auth()->id() ?? 0);
        if ($userId <= 0) {
            return;
        }

        \Omnichannel\Addons\AiPrompt\Support\ArticleGenerationModePreference::persistForUserId($userId, $policy);
    }

    public function canEmergencyStop(): bool
    {
        $project = $this->project;
        if (! $project instanceof SeoProject) {
            return false;
        }

        return SeoAccessControl::canManageContentProjectWorkflow()
            && ! $project->isDraftPlanning()
            && ! $project->isProjectArchived()
            && ! $project->isArchive()
            && $this->hasRunningExecution();
    }

    public function emergencyStop(): void
    {
        $project = $this->requireProject();

        try {
            abort_unless($this->canEmergencyStop(), 403);

            $result = app(ContentProjectCommandBus::class)->dispatch(
                new StopProjectExecutionCommand(
                    (int) $project->getKey(),
                    null,
                    'Emergency stop requested from Content Project Progress.',
                ),
                ActorContext::user(
                    auth()->id() !== null ? (int) auth()->id() : null,
                    (int) ($project->site_id ?? 0) ?: null,
                ),
            );

            app(ContentProjectActionResultNotifier::class)->send($result);
            $this->reloadProgress();
        } catch (Throwable $exception) {
            RuntimeLogger::report($exception, [
                'endpoint' => 'content_project.progress_emergency_stop',
                'project_id' => (int) $project->getKey(),
            ]);

            Notification::make()
                ->title(__('Cannot stop execution'))
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Called after Create with AI starts so Progress remorphs without full redirect.
     */
    public function notifyProjectRuntimeChanged(): void
    {
        $this->reloadProgress();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function globalLockHolder(): ?array
    {
        return app(GlobalGenerationLock::class)->currentHolder();
    }

    /**
     * @return array{state: string, holder: ?array<string, mixed>, message: ?string, reason: ?string}
     */
    public function globalLockInspect(): array
    {
        return app(GlobalGenerationLock::class)->inspect();
    }

    public function generateControlsLocked(): bool
    {
        return $this->hasRunningExecution()
            || $this->hasStoppingExecution()
            || ! app(GlobalGenerationLock::class)->canAttemptAcquire();
    }

    public function displayedAiModeLabel(): string
    {
        $run = is_array($this->progressSnapshot['run'] ?? null)
            ? $this->progressSnapshot['run']
            : null;
        $snapshotLabel = is_array($run) ? trim((string) ($run['ai_mode_label'] ?? '')) : '';
        if ($snapshotLabel !== '' && (
            $this->hasRunningExecution()
            || $this->hasStoppingExecution()
            || (is_array($run) && (bool) ($run['is_terminal'] ?? false))
        )) {
            return $snapshotLabel;
        }

        return $this->articleGenerationModeLabel();
    }

    protected function getHeaderActions(): array
    {
        $project = $this->requireProject();
        $controlsLocked = fn (): bool => $this->generateControlsLocked();

        return [
            Actions\Action::make('running_items_indicator')
                ->label(fn (): string => __('seo-content-ai::filament.projects.ops_running_items_indicator', [
                    'count' => $this->headerRunningItemsCount(),
                ]))
                ->icon('heroicon-o-arrow-path')
                ->color('info')
                ->disabled()
                ->extraAttributes([
                    'class' => 'pointer-events-none animate-pulse',
                ])
                ->visible(fn (): bool => $this->hasRunningExecution()),
            Actions\Action::make('emergency_stop_generation')
                ->label(__('Emergency stop'))
                ->icon('heroicon-o-stop-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading(__('Generate emergency stop'))
                ->modalDescription(__('Stop the running execution, cancel the active step and keep items that have not been run in pending status.'))
                ->modalSubmitActionLabel(__('Stop now'))
                ->visible(fn (): bool => $this->canEmergencyStop())
                ->action(function (): void {
                    $this->emergencyStop();
                }),
            SeoProjectResource::makeCreateWithAiAction(
                $project,
                fn (): array => $this->createWithAiLaunchSettings(),
                fn (): string => $this->articleGenerationModeLabel(),
            )->disabled(function () use ($project, $controlsLocked): bool {
                return $controlsLocked() || ! SeoProjectResource::canGeneratePendingItems($project);
            }),
            SeoProjectResource::makeCreateWithAiModeGroup(
                $project,
                function (string $mode): void {
                    $this->setArticleGenerationMode($mode);
                },
                fn (): string => $this->articleGenerationMode,
                $controlsLocked,
            ),
        ];
    }

    private function headerRunningItemsCount(): int
    {
        $overview = is_array($this->progressSnapshot['overview'] ?? null)
            ? $this->progressSnapshot['overview']
            : [];

        return max(1, (int) ($overview['processing'] ?? 0));
    }

    private function syncArticleGenerationModePreference(): void
    {
        $userId = (int) (auth()->id() ?? 0);
        $policy = \Omnichannel\Addons\AiPrompt\Support\ArticleGenerationModePreference::forUserId(
            $userId > 0 ? $userId : null,
        );
        $this->articleGenerationMode = $policy->generationModeValue();
    }

    private function articleGenerationModeLabel(): string
    {
        return $this->articleGenerationMode === 'free_only'
            ? (string) __('seo-content-ai::filament.projects.mode_free')
            : (string) __('seo-content-ai::filament.projects.mode_fast');
    }

    /**
     * @return array<string, mixed>
     */
    private function createWithAiLaunchSettings(): array
    {
        $policy = \Omnichannel\Addons\AiPrompt\Support\AiCostPolicy::tryFromMixed($this->articleGenerationMode);

        return [
            'generate_post_images' => $this->generatePostImages,
            \Omnichannel\Addons\AiPrompt\Support\AiCostPolicy::SETTING_KEY => $policy->value,
            'ai_generation_mode' => $this->articleGenerationMode,
        ];
    }

    private function reloadProgress(): void
    {
        $this->progressSnapshot = app(ContentProjectProgressReadModel::class)
            ->forProject($this->requireProject());
    }

    private function hasRunningExecution(): bool
    {
        $project = $this->project;
        if (! $project instanceof SeoProject) {
            return false;
        }

        return SeoProjectRun::query()
            ->where('project_id', (int) $project->getKey())
            ->where('status', SeoProjectRun::STATUS_RUNNING)
            ->exists();
    }

    private function hasStoppingExecution(): bool
    {
        $project = $this->project;
        if (! $project instanceof SeoProject) {
            return false;
        }

        return SeoProjectRun::query()
            ->where('project_id', (int) $project->getKey())
            ->where('status', SeoProjectRun::STATUS_STOPPING)
            ->exists();
    }

    private function resolveProject(int|string $record): SeoProject
    {
        $project = SeoProjectResource::getRecordRouteBindingEloquentQuery()->find($record);
        abort_unless($project instanceof SeoProject, 404);

        return $project;
    }

    private function requireProject(): SeoProject
    {
        if (! $this->project instanceof SeoProject) {
            $this->project = $this->resolveProject($this->record);
        }

        return $this->project;
    }
}
