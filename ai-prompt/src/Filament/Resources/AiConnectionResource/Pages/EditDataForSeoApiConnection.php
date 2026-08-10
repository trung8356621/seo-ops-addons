<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Filament\Resources\AiConnectionResource\Pages;

use Omnichannel\Addons\AiPrompt\Filament\Resources\AiConnectionResource;
use Omnichannel\Addons\SearchIntelligence\Services\DataForSeoConnectionService;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionFormSchema;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\Seo\Filament\Concerns\HidesFilamentPageHeader;
use Filament\Actions\Action;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page as ResourcePage;

class EditDataForSeoApiConnection extends ResourcePage implements HasForms
{
    use HidesFilamentPageHeader;
    use InteractsWithForms;

    protected static string $resource = AiConnectionResource::class;

    protected static ?string $slug = 'dataforseo/edit';

    protected static string $view = 'seo-content-ai::filament.pages.seo-settings-api-form';

    protected static bool $shouldRegisterNavigation = false;

    /** @var array<string, mixed> */
    public ?array $data = [];

    private DataForSeoConnectionService $dataForSeo;

    public function boot(DataForSeoConnectionService $dataForSeo): void
    {
        $this->dataForSeo = $dataForSeo;
    }

    public static function canAccess(array $parameters = []): bool
    {
        return SeoAccessControl::canAccessManagerFeatures();
    }

    public function mount(): void
    {
        $connection = $this->dataForSeo->resolveForUser((int) auth()->id());

        $this->form->fill([
            'provider' => ApiConnectionProviders::DATAFORSEO,
            'name' => (string) ($connection?->login ?? 'DataForSEO'),
            'dataforseo_login' => (string) ($connection?->login ?? ''),
            'dataforseo_password' => '',
            'dataforseo_location' => (string) ($connection?->default_location ?? ''),
            'dataforseo_language' => (string) ($connection?->default_language ?? ''),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema(ApiConnectionFormSchema::components(operation: 'edit', lockProvider: true))
            ->statePath('data');
    }

    public function getTitle(): string
    {
        return __('seo-content-ai::filament.api_connections.edit_dataforseo');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('test')
                ->label(__('seo-content-ai::filament.api_connections.test_connection'))
                ->action('testConnection'),
        ];
    }

    public function save(): void
    {
        if (! SeoAccessControl::canMutateInSeoPanel()) {
            $this->denyMutation();

            return;
        }

        $data = $this->form->getState();
        $this->dataForSeo->saveForUser((int) auth()->id(), [
            'login' => $data['dataforseo_login'] ?? $data['name'] ?? '',
            'password' => $data['dataforseo_password'] ?? null,
            'default_location' => $data['dataforseo_location'] ?? null,
            'default_language' => $data['dataforseo_language'] ?? null,
        ]);

        Notification::make()
            ->title(__('seo-content-ai::filament.api_connections.dataforseo_saved'))
            ->success()
            ->send();

        $this->redirect(AiConnectionResource::getUrl('index'));
    }

    public function testConnection(): void
    {
        if (! SeoAccessControl::canMutateInSeoPanel()) {
            $this->denyMutation();

            return;
        }

        $data = $this->form->getState();
        $connection = $this->dataForSeo->saveForUser((int) auth()->id(), [
            'login' => $data['dataforseo_login'] ?? $data['name'] ?? '',
            'password' => $data['dataforseo_password'] ?? null,
            'default_location' => $data['dataforseo_location'] ?? null,
            'default_language' => $data['dataforseo_language'] ?? null,
        ]);

        $result = $this->dataForSeo->testConnection($connection);
        Notification::make()
            ->title($result['ok']
                ? __('seo-content-ai::filament.api_connections.test_success')
                : __('seo-content-ai::filament.api_connections.test_failed'))
            ->body($result['message'])
            ->{$result['ok'] ? 'success' : 'danger'}()
            ->send();
    }

    private function denyMutation(): void
    {
        Notification::make()
            ->title(__('seo-content-ai::filament.keyword.workspace_save_denied'))
            ->danger()
            ->send();
    }
}
