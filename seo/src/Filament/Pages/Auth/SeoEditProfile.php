<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Filament\Pages\Auth;

use Omnichannel\Addons\Seo\Filament\Concerns\InteractsWithSeoConnectionRoutes;
use Filament\Actions\Action;
use Filament\Forms\Components\Component;
use Filament\Forms\Form;
use Filament\Pages\Auth\EditProfile;
use Illuminate\Contracts\Support\Htmlable;

final class SeoEditProfile extends EditProfile
{
    use InteractsWithSeoConnectionRoutes;

    public static function getLabel(): string
    {
        return __('seo-content-ai::filament.profile.label');
    }

    public function getTitle(): string|Htmlable
    {
        return static::getLabel();
    }

    protected function getNameFormComponent(): Component
    {
        return parent::getNameFormComponent()
            ->label(__('seo-content-ai::filament.profile.name'));
    }

    protected function getEmailFormComponent(): Component
    {
        return parent::getEmailFormComponent()
            ->label(__('seo-content-ai::filament.profile.email'));
    }

    /**
     * @return array<int | string, string | Form>
     */
    protected function getForms(): array
    {
        return [
            'form' => $this->form(
                $this->makeForm()
                    ->schema([
                        $this->getNameFormComponent(),
                        $this->getEmailFormComponent(),
                    ])
                    ->operation('edit')
                    ->model($this->getUser())
                    ->statePath('data')
                    ->inlineLabel(! static::isSimple()),
            ),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('changePassword')
                ->label(__('seo-content-ai::filament.profile.change_password'))
                ->icon('heroicon-o-key')
                ->url(fn (): string => SeoChangePassword::getUrl())
                ->color('gray'),
        ];
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return __('seo-content-ai::filament.profile.saved');
    }
}
