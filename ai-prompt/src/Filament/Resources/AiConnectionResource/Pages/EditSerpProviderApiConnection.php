<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Filament\Resources\AiConnectionResource\Pages;

use Omnichannel\Addons\AiPrompt\Filament\Resources\AiConnectionResource;
use Omnichannel\Addons\Seo\Filament\Concerns\HidesFilamentPageHeader;
use Omnichannel\Addons\SearchIntelligence\Services\SeoSerpProviderConnectionService;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionFormSchema;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\SearchIntelligence\Support\SerpProviderKeys;
use Filament\Actions\Action;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page as ResourcePage;

class EditSerpProviderApiConnection extends ResourcePage implements HasForms
{
    use HidesFilamentPageHeader;
    use InteractsWithForms;

    protected static string $resource = AiConnectionResource::class;

    protected static ?string $slug = 'serp/{provider}/edit';

    protected static string $view = 'seo-content-ai::filament.pages.seo-settings-api-form';

    protected static bool $shouldRegisterNavigation = false;

    public string $provider = '';

    /** @var array<string, mixed> */
    public ?array $data = [];

    private SeoSerpProviderConnectionService $serpConnections;

    public function boot(SeoSerpProviderConnectionService $serpConnections): void
    {
        $this->serpConnections = $serpConnections;
    }

    public static function canAccess(array $parameters = []): bool
    {
        return SeoAccessControl::canAccessManagerFeatures();
    }

    public function mount(string $provider): void
    {
        if (! SerpProviderKeys::isValid($provider)) {
            abort(404);
        }

        $this->provider = $provider;
        $connection = $this->serpConnections->resolveForUser((int) auth()->id(), $provider);

        $this->form->fill([
            'provider' => $provider,
            'name' => (string) ($connection?->name ?? SerpProviderKeys::label($provider)),
            'serp_api_key' => '',
            'serp_status' => (string) ($connection?->status === 'active' ? 'active' : 'inactive'),
            'serp_default_country' => (string) ($connection?->default_country ?? ''),
            'serp_default_language' => (string) ($connection?->default_language ?? ''),
            'serp_default_location' => (string) ($connection?->default_location ?? ''),
            'serp_default_device' => (string) ($connection?->default_device ?? 'desktop'),
            'serp_result_depth' => (int) ($connection?->result_depth ?? 100),
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
        return __('seo-content-ai::filament.api_connections.edit_serp_provider', [
            'provider' => SerpProviderKeys::label($this->provider),
        ]);
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
        $this->persistFromForm($data);

        Notification::make()
            ->title(__('seo-content-ai::filament.api_connections.serp_saved'))
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
        $connection = $this->persistFromForm($data);
        $result = $this->serpConnections->testConnection($connection);

        Notification::make()
            ->title($result['ok']
                ? __('seo-content-ai::filament.api_connections.test_success')
                : __('seo-content-ai::filament.api_connections.test_failed'))
            ->body((string) ($result['message'] ?? ''))
            ->{$result['ok'] ? 'success' : 'danger'}()
            ->send();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function persistFromForm(array $data): \Omnichannel\Addons\SearchIntelligence\Models\SeoSerpProviderConnection
    {
        return $this->serpConnections->saveForUser((int) auth()->id(), $this->provider, [
            'name' => $data['name'] ?? SerpProviderKeys::label($this->provider),
            'api_key' => $data['serp_api_key'] ?? null,
            'status' => $data['serp_status'] ?? 'inactive',
            'default_country' => $data['serp_default_country'] ?? null,
            'default_language' => $data['serp_default_language'] ?? null,
            'default_location' => $data['serp_default_location'] ?? null,
            'default_device' => $data['serp_default_device'] ?? 'desktop',
            'result_depth' => $data['serp_result_depth'] ?? 100,
        ]);
    }

    private function denyMutation(): void
    {
        Notification::make()
            ->title(__('seo-content-ai::filament.keyword.workspace_save_denied'))
            ->danger()
            ->send();
    }
}
