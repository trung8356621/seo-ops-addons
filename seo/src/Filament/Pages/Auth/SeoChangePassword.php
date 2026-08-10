<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Filament\Pages\Auth;

use Omnichannel\Addons\Seo\Filament\Concerns\InteractsWithSeoConnectionRoutes;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Form;
use Filament\Pages\Auth\EditProfile;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;

final class SeoChangePassword extends EditProfile
{
    use InteractsWithSeoConnectionRoutes;

    protected static bool $shouldRegisterNavigation = false;

    public static function getLabel(): string
    {
        return __('seo-content-ai::filament.profile.change_password');
    }

    public function getTitle(): string|Htmlable
    {
        return static::getLabel();
    }

    public static function getRelativeRouteName(): string
    {
        return 'change-password';
    }

    public static function getSlug(): string
    {
        return 'change-password';
    }

    public static function getRouteName(?string $panel = null): string
    {
        $panel = $panel ? Filament::getPanel($panel) : Filament::getCurrentPanel();

        return $panel->generateRouteName(static::getRelativeRouteName());
    }

    protected function fillForm(): void
    {
        $this->form->fill([
            'password' => null,
            'passwordConfirmation' => null,
        ]);
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
                        $this->getPasswordFormComponent(),
                        $this->getPasswordConfirmationFormComponent(),
                    ])
                    ->operation('edit')
                    ->model($this->getUser())
                    ->statePath('data')
                    ->inlineLabel(! static::isSimple()),
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return [
            'password' => $data['password'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $record->update([
            'password' => $data['password'],
        ]);

        return $record;
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return __('seo-content-ai::filament.profile.password_saved');
    }

    protected function getRedirectUrl(): ?string
    {
        return SeoEditProfile::getUrl();
    }

    protected function getCancelFormAction(): Action
    {
        return Action::make('back')
            ->label(__('seo-content-ai::filament.profile.back_to_profile'))
            ->url(SeoEditProfile::getUrl())
            ->color('gray');
    }

    protected function getPasswordFormComponent(): \Filament\Forms\Components\Component
    {
        return parent::getPasswordFormComponent()
            ->required()
            ->label(__('seo-content-ai::filament.profile.new_password'));
    }

    protected function getPasswordConfirmationFormComponent(): \Filament\Forms\Components\Component
    {
        return parent::getPasswordConfirmationFormComponent()
            ->label(__('seo-content-ai::filament.profile.confirm_password'));
    }
}
