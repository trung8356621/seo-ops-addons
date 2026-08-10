<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Filament\Concerns;

use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\EditRecord;

trait InteractsWithSeoFilamentFormSaveActions
{
    public bool $formSaveExplicitlyLocked = false;

    public function lockFormSave(): void
    {
        $this->formSaveExplicitlyLocked = true;
    }

    public function unlockFormSave(): void
    {
        $this->formSaveExplicitlyLocked = false;
    }

    public function isSeoFormSaveDisabled(): bool
    {
        if ($this->formSaveExplicitlyLocked) {
            return true;
        }

        if ($this->isSeoFormReadOnly()) {
            return true;
        }

        return $this->shouldDisableSeoFormSave();
    }

    protected function shouldDisableSeoFormSave(): bool
    {
        return false;
    }

    protected function isSeoFormReadOnly(): bool
    {
        if (! method_exists(static::class, 'getResource')) {
            return false;
        }

        $resource = static::getResource();

        if ($this instanceof EditRecord) {
            return ! $resource::canEdit($this->getRecord());
        }

        if ($this instanceof CreateRecord) {
            return ! $resource::canCreate();
        }

        return false;
    }

    protected function configureSeoFormSaveAction(Action $action): Action
    {
        return $action
            ->disabled(fn (): bool => $this->isSeoFormSaveDisabled())
            ->extraAttributes([
                'wire:loading.attr' => 'disabled',
            ], merge: true);
    }

    protected function getSaveFormAction(): Action
    {
        return $this->configureSeoFormSaveAction(parent::getSaveFormAction());
    }

    protected function getCreateFormAction(): Action
    {
        return $this->configureSeoFormSaveAction(parent::getCreateFormAction());
    }

    protected function getCreateAnotherFormAction(): Action
    {
        return $this->configureSeoFormSaveAction(parent::getCreateAnotherFormAction());
    }
}
