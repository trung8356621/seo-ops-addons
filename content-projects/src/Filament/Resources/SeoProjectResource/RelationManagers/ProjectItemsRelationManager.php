<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\RelationManagers;

use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectRerunFromStep;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ApproveProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\GenerateProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\RerunProjectItemStepCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\StartReviewCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBus;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectItemGenerationClassifier;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectLifecycle;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class ProjectItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'tasks';

    protected static ?string $title = 'Project Items';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof SeoProject;
    }

    public function table(Table $table): Table
    {
        /** @var SeoProject $project */
        $project = $this->getOwnerRecord();
        $lifecycle = app(ContentProjectLifecycle::class);

        return $table
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query
                ->active()
                ->where('status', '!=', SeoProjectTask::STATUS_CANCELLED)
                ->with(['article'])
                ->orderBy('id'))
            ->columns([
                Tables\Columns\TextColumn::make('keyword')
                    ->label(__('seo-content-ai::filament.projects.item_keyword'))
                    ->formatStateUsing(function (SeoProjectTask $record): string {
                        $kw = trim((string) ($record->keyword ?? ''));
                        if ($kw !== '') {
                            return $kw;
                        }
                        $title = trim((string) ($record->title ?? ''));
                        if ($title !== '') {
                            return $title;
                        }
                        $source = trim((string) ($record->source_content ?? ''));
                        if ($source !== '') {
                            return $source;
                        }

                        return (string) ($record->article?->title ?? '—');
                    })
                    ->searchable(['keyword', 'title', 'source_content'])
                    ->wrap()
                    ->limit(48),
                Tables\Columns\TextColumn::make('type')
                    ->label(__('seo-content-ai::filament.projects.item_type'))
                    ->badge()
                    ->formatStateUsing(static fn (?string $state): string => match (SeoProjectTask::normalizeType($state)) {
                        SeoProjectTask::TYPE_REWRITE => 'rewrite',
                        SeoProjectTask::TYPE_IMPROVE => 'improve',
                        default => 'new',
                    }),
                Tables\Columns\TextColumn::make('article_id')
                    ->label(__('seo-content-ai::filament.projects.item_article'))
                    ->formatStateUsing(function (SeoProjectTask $record): string {
                        $id = (int) ($record->article_id ?? 0);
                        if ($id <= 0) {
                            return '—';
                        }
                        $title = trim((string) ($record->article?->title ?? ''));

                        return $title !== '' ? '#'.$id.' — '.$title : '#'.$id;
                    })
                    ->url(fn (SeoProjectTask $record): ?string => (int) ($record->article_id ?? 0) > 0
                        ? ArticleResource::getUrl('edit', ['record' => (int) $record->article_id])
                        : null)
                    ->limit(40),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('seo-content-ai::filament.projects.item_generation_status'))
                    ->badge(),
                Tables\Columns\TextColumn::make('review_phase')
                    ->label(__('seo-content-ai::filament.projects.item_review_status'))
                    ->state(fn (SeoProjectTask $record): string => $lifecycle->resolvePhase($record)->value)
                    ->badge(),
                Tables\Columns\TextColumn::make('publish_queue_status')
                    ->label(__('seo-content-ai::filament.projects.item_publish_status'))
                    ->placeholder('none')
                    ->badge(),
                Tables\Columns\TextColumn::make('last_execution_at')
                    ->label(__('seo-content-ai::filament.projects.item_last_run'))
                    ->state(function (SeoProjectTask $record): ?string {
                        $item = SeoProjectRunItem::query()
                            ->where('task_id', (int) $record->id)
                            ->orderByDesc('id')
                            ->first(['finished_at', 'started_at', 'status']);
                        if (! $item instanceof SeoProjectRunItem) {
                            return null;
                        }
                        $at = $item->finished_at ?? $item->started_at;

                        return ($at?->format('d/m/Y H:i') ?? '—').' ('.(string) $item->status.')';
                    })
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('article.last_manual_saved_at')
                    ->label(__('seo-content-ai::filament.projects.item_last_save'))
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('last_error')
                    ->label(__('seo-content-ai::filament.projects.item_last_error'))
                    ->state(function (SeoProjectTask $record): ?string {
                        if ($record->last_publish_error !== null && (string) $record->last_publish_error !== '') {
                            return (string) $record->last_publish_error;
                        }
                        $item = SeoProjectRunItem::query()
                            ->where('task_id', (int) $record->id)
                            ->whereIn('status', ['failed', 'error'])
                            ->orderByDesc('id')
                            ->first(['error_message']);

                        return $item instanceof SeoProjectRunItem
                            ? (string) ($item->error_message ?? 'failed')
                            : null;
                    })
                    ->limit(40)
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('bulk_generate_pending')
                        ->label(__('seo-content-ai::filament.projects.item_action_generate'))
                        ->icon('heroicon-o-play')
                        ->requiresConfirmation()
                        ->action(function ($records) use ($project): void {
                            $ids = $records->map(static fn (SeoProjectTask $t): int => (int) $t->id)->all();
                            $this->dispatchGenerate($project, $ids);
                        }),
                    Tables\Actions\BulkAction::make('bulk_regen_outline')
                        ->label(__('seo-content-ai::filament.projects.item_action_regen_outline'))
                        ->requiresConfirmation()
                        ->action(function ($records) use ($project): void {
                            $ids = $records->map(static fn (SeoProjectTask $t): int => (int) $t->id)->all();
                            $this->dispatchBulkStep($project, $ids, ContentProjectRerunFromStep::Outline->value);
                        }),
                    Tables\Actions\BulkAction::make('bulk_regen_article')
                        ->label(__('seo-content-ai::filament.projects.item_action_regen_article'))
                        ->requiresConfirmation()
                        ->action(function ($records) use ($project): void {
                            $ids = $records->map(static fn (SeoProjectTask $t): int => (int) $t->id)->all();
                            $this->dispatchBulkStep($project, $ids, ContentProjectRerunFromStep::Article->value);
                        }),
                    Tables\Actions\BulkAction::make('bulk_start_review')
                        ->label(__('seo-content-ai::filament.projects.item_action_start_review'))
                        ->action(function ($records) use ($project): void {
                            $ids = $records->map(static fn (SeoProjectTask $t): int => (int) $t->id)->all();
                            $this->dispatchCommand(new StartReviewCommand((int) $project->id, $ids), $project);
                        }),
                    Tables\Actions\BulkAction::make('bulk_approve')
                        ->label(__('seo-content-ai::filament.projects.item_action_approve'))
                        ->action(function ($records) use ($project): void {
                            $ids = $records->map(static fn (SeoProjectTask $t): int => (int) $t->id)->all();
                            $this->dispatchCommand(new ApproveProjectItemsCommand((int) $project->id, $ids), $project);
                        }),
                ]),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('generate_item')
                        ->label(__('seo-content-ai::filament.projects.item_action_generate'))
                        ->icon('heroicon-o-play')
                        ->visible(fn (SeoProjectTask $record): bool => $this->itemCanGenerate($record))
                        ->requiresConfirmation()
                        ->action(fn (SeoProjectTask $record) => $this->dispatchGenerate($project, [(int) $record->id])),
                    Tables\Actions\Action::make('regen_outline')
                        ->label(__('seo-content-ai::filament.projects.item_action_regen_outline'))
                        ->visible(fn (SeoProjectTask $record): bool => $this->itemCanRegen($record))
                        ->requiresConfirmation()
                        ->action(fn (SeoProjectTask $record) => $this->dispatchBulkStep(
                            $project,
                            [(int) $record->id],
                            ContentProjectRerunFromStep::Outline->value,
                        )),
                    Tables\Actions\Action::make('regen_article')
                        ->label(__('seo-content-ai::filament.projects.item_action_regen_article'))
                        ->visible(fn (SeoProjectTask $record): bool => $this->itemCanRegen($record))
                        ->requiresConfirmation()
                        ->action(fn (SeoProjectTask $record) => $this->dispatchBulkStep(
                            $project,
                            [(int) $record->id],
                            ContentProjectRerunFromStep::Article->value,
                        )),
                    Tables\Actions\Action::make('regen_image')
                        ->label(__('seo-content-ai::filament.projects.item_action_regen_image'))
                        ->visible(fn (SeoProjectTask $record): bool => $this->itemCanRegen($record))
                        ->url(fn (SeoProjectTask $record): ?string => (int) ($record->article_id ?? 0) > 0
                            ? ArticleResource::getUrl('edit', ['record' => (int) $record->article_id])
                            : null)
                        ->openUrlInNewTab()
                        ->tooltip(__('seo-content-ai::filament.projects.item_regen_image_via_editor')),
                    Tables\Actions\Action::make('start_review')
                        ->label(__('seo-content-ai::filament.projects.item_action_start_review'))
                        ->action(fn (SeoProjectTask $record) => $this->dispatchCommand(
                            new StartReviewCommand((int) $project->id, [(int) $record->id]),
                            $project,
                        )),
                    Tables\Actions\Action::make('approve')
                        ->label(__('seo-content-ai::filament.projects.item_action_approve'))
                        ->action(fn (SeoProjectTask $record) => $this->dispatchCommand(
                            new ApproveProjectItemsCommand((int) $project->id, [(int) $record->id]),
                            $project,
                        )),
                    Tables\Actions\Action::make('execution_details')
                        ->label(__('seo-content-ai::filament.projects.item_action_execution_details'))
                        ->icon('heroicon-o-queue-list')
                        ->modalHeading(__('seo-content-ai::filament.projects.item_execution_details_heading'))
                        ->modalContent(function (SeoProjectTask $record) {
                            $items = SeoProjectRunItem::query()
                                ->where('task_id', (int) $record->id)
                                ->orderByDesc('id')
                                ->limit(10)
                                ->get(['id', 'run_id', 'status', 'action', 'error_message', 'started_at', 'finished_at']);

                            return view(
                                'seo-content-ai::filament.resources.seo-project-resource.partials.item-execution-details',
                                [
                                    'items' => $items,
                                    'task' => $record,
                                ],
                            );
                        })
                        ->modalSubmitAction(false),
                    Tables\Actions\Action::make('open_article')
                        ->label(__('seo-content-ai::filament.projects.item_action_open_article'))
                        ->url(fn (SeoProjectTask $record): ?string => (int) ($record->article_id ?? 0) > 0
                            ? ArticleResource::getUrl('edit', ['record' => (int) $record->article_id])
                            : null)
                        ->visible(fn (SeoProjectTask $record): bool => (int) ($record->article_id ?? 0) > 0),
                ]),
            ])
            ->emptyStateHeading(__('seo-content-ai::filament.projects.item_empty'))
            ->paginated([25, 50, 100]);
    }

    private function itemCanGenerate(SeoProjectTask $record): bool
    {
        if (SeoProjectTask::normalizeType($record->type) === SeoProjectTask::TYPE_IMPROVE) {
            return false;
        }

        return app(ContentProjectItemGenerationClassifier::class)->classifyTask($record)->shouldRun();
    }

    private function itemCanRegen(SeoProjectTask $record): bool
    {
        return SeoProjectTask::normalizeType($record->type) !== SeoProjectTask::TYPE_IMPROVE
            && (int) ($record->article_id ?? 0) > 0;
    }

    /**
     * @param  list<int>  $taskIds
     */
    private function dispatchGenerate(SeoProject $project, array $taskIds): void
    {
        if (! SeoAccessControl::canAccessContentProjectRun($project)) {
            Notification::make()->title('Forbidden')->danger()->send();

            return;
        }

        $this->dispatchCommand(
            new GenerateProjectItemsCommand((int) $project->id, $taskIds),
            $project,
        );
    }

    /**
     * @param  list<int>  $taskIds
     */
    private function dispatchBulkStep(SeoProject $project, array $taskIds, string $action): void
    {
        $filtered = [];
        foreach ($taskIds as $id) {
            $task = SeoProjectTask::query()->find($id);
            if (! $task instanceof SeoProjectTask) {
                continue;
            }
            if (SeoProjectTask::normalizeType($task->type) === SeoProjectTask::TYPE_IMPROVE) {
                continue;
            }
            $filtered[] = (int) $task->id;
        }

        if ($filtered === []) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.item_improve_blocked'))
                ->warning()
                ->send();

            return;
        }

        $fromStep = ContentProjectRerunFromStep::tryFromMixed($action);
        if (! $fromStep instanceof ContentProjectRerunFromStep) {
            Notification::make()
                ->title('Failed')
                ->body('Unsupported step action.')
                ->danger()
                ->send();

            return;
        }

        $this->dispatchCommand(
            new RerunProjectItemStepCommand(
                (int) $project->id,
                $filtered,
                $fromStep,
                includeDownstream: false,
            ),
            $project,
        );
    }

    private function dispatchCommand(object $command, SeoProject $project): void
    {
        $result = app(ContentProjectCommandBus::class)->dispatch(
            $command,
            ActorContext::user(
                auth()->id() !== null ? (int) auth()->id() : null,
                (int) ($project->site_id ?? 0) ?: null,
            ),
        );

        Notification::make()
            ->title($result->success ? 'OK' : 'Failed')
            ->body($result->message)
            ->{$result->success ? 'success' : 'danger'}()
            ->send();
    }
}
