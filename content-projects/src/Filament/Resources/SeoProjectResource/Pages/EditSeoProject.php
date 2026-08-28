<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages;

use Omnichannel\Addons\Seo\Filament\Resources\Pages\SeoEditRecord;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\SyncContentProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\UpdateContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBus;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectErrorCode;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectArticleOwnerSyncService;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectTaskMoveService;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectTaskSyncService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Support\RuntimeLogger;
use Carbon\Carbon;
use Filament\Actions;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class EditSeoProject extends SeoEditRecord
{
    protected static string $resource = SeoProjectResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        /** @var SeoProject $project */
        $project = $this->getRecord();
        if ($project->isArchive() || $project->isProjectArchived()) {
            $this->redirect(SeoProjectResource::getUrl('index'));
        }
    }

    public function getTitle(): string
    {
        /** @var SeoProject $record */
        $record = $this->getRecord();

        return __('seo-content-ai::filament.projects.edit_project', [
            'name' => (string) $record->name,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var SeoProject $record */
        $record = $this->getRecord();

        $data['tasks_data'] = app(SeoProjectTaskSyncService::class)->tasksDataFromProject($record);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        abort_if(SeoAccessControl::isContentManager(), 403);

        /** @var SeoProject $record */
        $record = $this->getRecord();

        $data = SeoProjectResource::normalizeProjectSiteId($data, $record);

        $tasksData = $data['tasks_data'] ?? [];
        unset($data['unassigned_staff_ids'], $data['assign_from_unassigned']);

        if (! empty($data['month'])) {
            $data['month'] = Carbon::parse($data['month'])->startOfMonth()->format('Y-m-d');
            $data['name'] = SeoProject::defaultNameFromMonth($data['month']);
        }

        $projectSiteId = isset($data['site_id']) ? (int) $data['site_id'] : null;
        $sanitized = app(SeoProjectTaskSyncService::class)->sanitizeTasksData($tasksData, $projectSiteId);

        app(SeoProjectTaskSyncService::class)->assertNoDuplicateTasksData($record, is_array($tasksData) ? $tasksData : []);

        app(SeoProjectTaskSyncService::class)->assertWithinMonthlyLimit($data['month'], $sanitized);

        $data['total_tasks'] = count($sanitized);
        $data['status'] = $record->status === SeoProject::STATUS_APPROVED
            ? SeoProject::STATUS_APPROVED
            : SeoProject::STATUS_MANUAL;

        unset($data['tasks_data']);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_if(SeoAccessControl::isContentManager(), 403);

        /** @var SeoProject $project */
        $project = $record;
        $projectId = (int) $project->getKey();
        $siteId = (int) ($data['site_id'] ?? $project->site_id ?? 0);
        $authUserId = auth()->id() !== null ? (int) auth()->id() : null;
        $bus = app(ContentProjectCommandBus::class);
        $actor = ActorContext::user($authUserId, $siteId > 0 ? $siteId : null);
        $syncService = app(SeoProjectTaskSyncService::class);

        $originalMonth = $project->month?->format('Y-m-d');
        $newMonth = (string) ($data['month'] ?? $originalMonth ?? '');
        $monthChanged = $originalMonth !== null && $newMonth !== '' && $originalMonth !== $newMonth;

        $updateResult = $bus->dispatch(
            new UpdateContentProjectCommand($projectId, $data),
            $actor,
        );

        if (! $updateResult->success) {
            if ($updateResult->code === ContentProjectActionCodes::VALIDATION_FAILED) {
                throw ValidationException::withMessages([
                    'data' => $updateResult->message,
                ]);
            }

            throw new RuntimeException($updateResult->message);
        }

        $tasksData = $this->form->getState()['tasks_data'] ?? [];
        $projectSiteId = isset($data['site_id']) ? (int) $data['site_id'] : ($project->site_id !== null ? (int) $project->site_id : null);
        $projectForCompare = $project->fresh(['tasks']) ?? $project;

        $incomingSignature = $syncService->tasksSignature($tasksData, $projectSiteId);
        $existingSignature = $syncService->tasksSignature(
            $syncService->tasksDataFromProject($projectForCompare),
            $projectSiteId,
        );

        if ($monthChanged || $incomingSignature !== $existingSignature) {
            $syncResult = $bus->dispatch(
                new SyncContentProjectItemsCommand($projectId, $tasksData),
                $actor,
            );

            if (! $syncResult->success) {
                $this->throwSyncFailureAsValidation($syncResult);
            }
        } else {
            app(SeoProjectArticleOwnerSyncService::class)->syncProjectArticles($projectForCompare);
        }

        /** @var SeoProject $fresh */
        $fresh = SeoProject::query()->findOrFail($projectId);

        return $fresh;
    }

    private function throwSyncFailureAsValidation(ContentProjectActionResult $result): never
    {
        if ($result->code === ContentProjectActionCodes::VALIDATION_FAILED || $result->errors !== []) {
            $mapped = [];
            foreach ($result->errors as $key => $messages) {
                $formKey = str_starts_with((string) $key, 'data.') ? (string) $key : 'data.'.$key;
                $mapped[$formKey] = array_values(array_map(
                    fn (mixed $message): string => $this->localizeSyncErrorMessage((string) $message),
                    is_array($messages) ? $messages : [(string) $messages],
                ));
            }

            if ($mapped === []) {
                $mapped['data.tasks_data'] = [$this->localizeSyncErrorMessage($result->message)];
            }

            throw ValidationException::withMessages($mapped);
        }

        throw new RuntimeException($this->localizeSyncErrorMessage($result->message));
    }

    private function localizeSyncErrorMessage(string $message): string
    {
        $trimmed = trim($message);
        if ($trimmed === ContentProjectErrorCode::SyncDuplicateInput->value
            || str_starts_with($trimmed, ContentProjectErrorCode::SyncDuplicateInput->value)
        ) {
            return (string) __('seo-content-ai::filament.projects.sync_duplicate_input');
        }

        foreach (ContentProjectErrorCode::cases() as $code) {
            if ($trimmed === $code->value || str_starts_with($trimmed, $code->value.' ')) {
                $key = 'seo-content-ai::filament.projects.error_'.$code->name;
                $translated = (string) __($key);
                if ($translated !== $key) {
                    return $translated;
                }
            }
        }

        return $trimmed;
    }

    protected function getHeaderActions(): array
    {
        /** @var SeoProject $record */
        $record = $this->getRecord();

        return [
            Actions\ActionGroup::make([
                SeoProjectResource::makeArchiveProjectPageAction($record),
                Actions\Action::make('open_scheduled_filter')
                    ->label(__('seo-content-ai::filament.projects.open_scheduled_filter'))
                    ->icon('heroicon-o-calendar-days')
                    ->color('gray')
                    ->url(fn (): string => SeoProjectResource::getPublishingQueueUrl($this->getRecord())),
                SeoProjectResource::makeGeneratePendingItemsAction($record),
                SeoProjectResource::makeDevTestGeneratePendingItemsAction($record),
                Actions\DeleteAction::make()
                    ->visible(fn (): bool => SeoProjectResource::canDelete($this->getRecord()))
                    ->requiresConfirmation()
                    ->modalHeading(__('seo-content-ai::filament.projects.delete_heading'))
                    ->modalDescription(__('seo-content-ai::filament.projects.delete_description'))
                    ->modalSubmitActionLabel(__('seo-content-ai::filament.projects.delete_submit'))
                    ->successNotification(null)
                    ->using(function (SeoProject $record): bool {
                        try {
                            $result = app(SeoProjectTaskMoveService::class)->deleteProject($record);
                            $restored = (int) ($result['restored'] ?? 0);

                            Notification::make()
                                ->title(__('seo-content-ai::filament.projects.delete_completed'))
                                ->body($restored > 0
                                    ? __('seo-content-ai::filament.projects.delete_restored_to_draft_body', [
                                        'count' => $restored,
                                    ])
                                    : __('seo-content-ai::filament.projects.delete_completed_body'))
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
                ->label(__('seo-content-ai::filament.projects.more_actions'))
                ->button()
                ->color('gray')
                ->visible(fn (): bool => ! $this->getRecord()->isDraftPlanning()
                    && ! $this->getRecord()->isProjectArchived()
                    && ! $this->getRecord()->isArchive()),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return SeoProjectResource::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
