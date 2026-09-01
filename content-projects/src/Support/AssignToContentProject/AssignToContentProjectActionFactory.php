<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\AssignToContentProject;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\PlanningDraftIntakeResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\PlanningDraftIntakeService;
use Filament\Actions\Action as PageAction;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action as TableAction;
use Filament\Tables\Actions\BulkAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * Canonical Filament/Livewire trigger adapters for Add-to-Draft.
 *
 * Single-article with keyword: intake directly (no drawer flash).
 * Missing keyword / multi / other modes: open shared drawer.
 */
final class AssignToContentProjectActionFactory
{
    public static function applyRowPresentation(TableAction $action): TableAction
    {
        return $action
            ->label(AssignToContentProjectContract::label())
            ->icon(AssignToContentProjectContract::ICON)
            ->iconButton()
            ->color(AssignToContentProjectContract::COLOR)
            ->tooltip(AssignToContentProjectContract::label());
    }

    public static function applyBulkPresentation(BulkAction $action): BulkAction
    {
        return $action
            ->label(AssignToContentProjectContract::label())
            ->icon(AssignToContentProjectContract::ICON)
            ->color(AssignToContentProjectContract::COLOR);
    }

    public static function applyPagePresentation(PageAction $action): PageAction
    {
        return $action
            ->label(AssignToContentProjectContract::label())
            ->icon(AssignToContentProjectContract::ICON)
            ->color(AssignToContentProjectContract::COLOR);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function open(Component $livewire, array $payload): void
    {
        $livewire->js(AssignToContentProjectContract::openScript($payload));
    }

    /**
     * Server-side preflight for Filament actions: direct intake when possible.
     *
     * @param  array<string, mixed>  $payload
     * @return bool true when handled without opening the drawer
     */
    public static function tryDirectArticleIntake(Component $livewire, array $payload): bool
    {
        $normalized = AssignToContentProjectContract::normalizePayload($payload);
        if ($normalized['mode'] !== AssignToContentProjectContract::MODE_ARTICLE) {
            return false;
        }

        $articleIds = $normalized['article_ids'];
        if (count($articleIds) !== 1) {
            return false;
        }

        $article = SeoArticle::query()
            ->with(['articleMetas' => static function ($relation): void {
                $relation->where('meta_key', 'seo_focus_keyword');
            }])
            ->find($articleIds[0]);

        if (! $article instanceof SeoArticle) {
            return false;
        }

        $intake = app(PlanningDraftIntakeService::class);
        if ($intake->articleNeedsKeyword($article)) {
            return false;
        }

        $result = $intake->addArticles(collect([$article]));
        self::notifyIntakeResult($result);

        if (! $result->isSuccess()) {
            return true;
        }

        $livewire->js(
            'window.dispatchEvent(new CustomEvent('
            .json_encode(AssignToContentProjectContract::SUCCESS_EVENT)
            .',{detail:'
            .json_encode([
                'mode' => AssignToContentProjectContract::MODE_ARTICLE,
                'source' => $normalized['source'],
                'article_ids' => $articleIds,
                'draft_project_id' => $result->draftProjectId,
                'status' => $result->status,
                'summary' => $result->summary,
                'direct' => true,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            .'}));'
        );

        return true;
    }

    /**
     * @param  callable(Model): array<string, mixed>  $resolvePayload
     * @param  (callable(Model): bool)|null  $visible
     */
    public static function tableRowAction(
        callable $resolvePayload,
        ?callable $visible = null,
        string $name = 'assign_to_content_project',
    ): TableAction {
        $action = self::applyRowPresentation(TableAction::make($name))
            ->action(function (Model $record, Component $livewire) use ($resolvePayload): void {
                $payload = $resolvePayload($record);
                if (self::tryDirectArticleIntake($livewire, $payload)) {
                    return;
                }
                self::open($livewire, $payload);
            });

        if ($visible !== null) {
            $action->visible($visible);
        }

        return $action;
    }

    /**
     * @param  callable(Collection<int, Model>, Component): array<string, mixed>  $resolvePayload
     * @param  (callable(): bool)|null  $visible
     */
    public static function tableBulkAction(
        callable $resolvePayload,
        ?callable $visible = null,
        string $name = 'assign_to_content_project',
    ): BulkAction {
        $action = self::applyBulkPresentation(BulkAction::make($name))
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records, Component $livewire) use ($resolvePayload): void {
                $payload = $resolvePayload($records, $livewire);
                // Bulk: open drawer (may auto-submit after prepare when all have keywords).
                self::open($livewire, $payload);
            });

        if ($visible !== null) {
            $action->visible($visible);
        }

        return $action;
    }

    /**
     * @param  callable(array<string, mixed>, Component): array<string, mixed>  $resolvePayload
     */
    public static function pageAction(
        callable $resolvePayload,
        string $name = 'assignToContentProject',
    ): PageAction {
        return self::applyPagePresentation(PageAction::make($name))
            ->action(function (array $arguments, Component $livewire) use ($resolvePayload): void {
                $payload = $resolvePayload($arguments, $livewire);
                if (self::tryDirectArticleIntake($livewire, $payload)) {
                    return;
                }
                self::open($livewire, $payload);
            });
    }

    private static function notifyIntakeResult(PlanningDraftIntakeResult $result): void
    {
        if ($result->isAlreadyInDraft()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_list.already_in_draft'))
                ->body($result->message)
                ->info()
                ->send();

            return;
        }

        if ($result->isSuccess()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_list.add_to_draft_completed'))
                ->body($result->message)
                ->success()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('seo-content-ai::filament.articles_optimal.assign_failed'))
            ->body($result->message !== '' ? $result->message : __('seo-content-ai::filament.articles_optimal.assign_failed'))
            ->warning()
            ->send();
    }
}
