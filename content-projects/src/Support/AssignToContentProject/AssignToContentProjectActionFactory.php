<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\AssignToContentProject;

use Filament\Actions\Action as PageAction;
use Filament\Tables\Actions\Action as TableAction;
use Filament\Tables\Actions\BulkAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * Canonical Filament/Livewire trigger adapters for Assign-to-Content-Project.
 *
 * Actions MUST NOT define form()/modal schema. They only resolve context and open the drawer.
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
                self::open($livewire, $resolvePayload($record));
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
                self::open($livewire, $resolvePayload($records, $livewire));
            });

        if ($visible !== null) {
            $action->visible($visible);
        }

        return $action;
    }

    /**
     * Page-level Action opened via mountAction / wire:click adapters.
     *
     * @param  callable(array<string, mixed>, Component): array<string, mixed>  $resolvePayload
     */
    public static function pageAction(
        callable $resolvePayload,
        string $name = 'assignToContentProject',
    ): PageAction {
        return self::applyPagePresentation(PageAction::make($name))
            ->action(function (array $arguments, Component $livewire) use ($resolvePayload): void {
                self::open($livewire, $resolvePayload($arguments, $livewire));
            });
    }
}
