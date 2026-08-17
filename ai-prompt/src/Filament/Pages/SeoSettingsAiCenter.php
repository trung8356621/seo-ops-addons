<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Filament\Pages;

use App\Models\ApiConnection;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Renderless;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Omnichannel\Addons\AiPrompt\Exceptions\AiProviderTemplateException;
use Omnichannel\Addons\AiPrompt\Filament\Resources\AiConnectionResource;
use Omnichannel\Addons\AiPrompt\Filament\Resources\PromptResource;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Services\AiCenterModelPresenter;
use Omnichannel\Addons\AiPrompt\Services\AiConnectionPresenter;
use Omnichannel\Addons\AiPrompt\Services\AiExecutionTargetPresenter;
use Omnichannel\Addons\AiPrompt\Services\AiModelFamilyCatalog;
use Omnichannel\Addons\AiPrompt\Services\AiModelInventory;
use Omnichannel\Addons\AiPrompt\Services\AiModelPriorityService;
use Omnichannel\Addons\AiPrompt\Services\AiModelRouterService;
use Omnichannel\Addons\AiPrompt\Services\AiRoutingBootstrapService;
use Omnichannel\Addons\AiPrompt\Services\AiRoutingTargetService;
use Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\AiProviderConnectionTester;
use Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\AiProviderTemplateCatalog;
use Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\AiProviderTemplateParser;
use Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\AiProviderTemplateStore;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Omnichannel\Addons\AiPrompt\Support\AiModelArea;
use Omnichannel\Addons\AiPrompt\Support\AiUsageMode;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SeoSettingsAiCenter extends Page
{
    use WithFileUploads;

    protected static ?string $slug = 'settings/ai-center';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'AI Center';

    protected static string $view = 'seo-content-ai::filament.pages.seo-settings-ai-center';

    public string $tab = 'models';

    public string $modelArea = 'text';

    public string $modelSearch = '';

    public string $modelProvider = 'all';

    public string $modelType = 'all';

    public string $modelStatus = 'all';

    public bool $showHidden = false;

    public bool $modelTechnical = false;

    public bool $showRoutingTechnical = false;

    public string $globalUsageMode = 'economy';

    public string $routingGroup = 'text';

    public ?string $editingProfile = null;

    public bool $modelsHydrated = true;

    public bool $routingHydrated = false;

    public bool $routingUnsaved = false;

    public bool $showImportModal = false;

    public ?int $pickerConnectionId = null;

    public bool $pickerOpen = false;

    public string $pickerProvider = 'all';

    public string $pickerSearch = '';

    public string $pickerType = 'all';

    public string $pickerStatus = 'available';

    public int $pickerPage = 1;

    public string $importJson = '';

    /** @var TemporaryUploadedFile|null */
    public $templateFile = null;

    /** @var array<string, mixed>|null */
    public ?array $importPreview = null;

    /** @var list<string> */
    public array $importWarnings = [];

    /** @var list<string> */
    public array $importDiff = [];

    public ?string $pendingTemplateJson = null;

    /** @var array<string, mixed> */
    public array $routingData = [];

    /** @var array<string, mixed> */
    public array $routingSavedSnapshot = [];

    /** @var array<int, array<string, mixed>> */
    public array $testStages = [];

    public function mount(
        AiRoutingBootstrapService $bootstrap,
        AiRoutingTargetService $targets,
        SeoCreateArticleSettingsService $articleSettings,
    ): void {
        $aliases = [
            'overview' => 'models',
            'providers' => 'models',
            'connections' => 'models',
        ];
        $requestedTab = (string) request()->query('tab', $this->tab);
        $this->tab = $aliases[$requestedTab] ?? $requestedTab;
        $this->modelArea = AiModelArea::tryFromMixed(
            (string) request()->query('modelArea', $this->modelArea),
        )->value;
        $this->routingGroup = $this->modelArea;
        if ($this->tab === 'prompts') {
            $this->redirect(PromptResource::getUrl(), navigate: true);

            return;
        }
        if ($this->tab === 'advanced') {
            $this->redirect(SeoSettingsAiAdvanced::getUrl(), navigate: true);

            return;
        }
        $allowed = ['models', 'routing'];
        if (! in_array($this->tab, $allowed, true)) {
            $this->tab = 'models';
        }
        // Models panel always present; Routing lazy-hydrates once. Tab visibility is Alpine-only.
        $this->modelsHydrated = true;
        $this->routingHydrated = $this->tab === 'routing';
        $userId = (int) auth()->id();
        $bootstrap->bootstrapForUser($userId);
        $this->globalUsageMode = $articleSettings->getDefaultAiUsageMode();
        $this->fillRouting($targets, $userId);
    }

    public function loadPanel(string $panel): void
    {
        if ($panel === 'models') {
            $this->modelsHydrated = true;
        }
        if ($panel === 'routing') {
            $this->routingHydrated = true;
        }
    }

    #[Renderless]
    public function setModelArea(string $area): void
    {
        $value = AiModelArea::tryFromMixed($area)->value;
        $this->modelArea = $value;
        $this->routingGroup = $value;
    }

    public function updatedGlobalUsageMode(SeoCreateArticleSettingsService $articleSettings): void
    {
        $this->assertManager();
        $mode = AiUsageMode::tryFromMixed($this->globalUsageMode) ?? AiUsageMode::Economy;
        $articleSettings->saveSettings([
            SeoCreateArticleSettingsService::KEY_DEFAULT_AI_USAGE_MODE => $mode->value,
        ]);
        $this->globalUsageMode = $mode->value;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function providerRows(): array
    {
        $userId = (int) auth()->id();
        $rows = [];
        foreach (app(AiModelPriorityService::class)->aiConnections($userId) as $connection) {
            if (ApiConnectionProviders::isExternal((string) $connection->provider)
                || ApiConnectionProviders::isSeo((string) $connection->provider)) {
                continue;
            }
            $rows[] = [
                'id' => (int) $connection->id,
                'name' => ApiConnectionProviders::label((string) $connection->provider),
                'connection_key' => (string) $connection->provider,
                'connection_name' => (string) $connection->name,
                'status' => filled($connection->api_key) && (string) $connection->status === 'active'
                    ? 'connected'
                    : 'not_configured',
                'enabled' => (string) $connection->status !== 'inactive',
                'model_count' => SeoAiModel::query()->where('api_connection_id', $connection->id)->count(),
                'edit_url' => AiConnectionResource::getUrl('edit', ['record' => $connection]),
                'can_delete' => AiConnectionResource::canDelete($connection),
                'is_global' => (bool) $connection->is_global,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function modelRows(): array
    {
        return app(AiCenterModelPresenter::class)->tableRows((int) auth()->id(), [
            'search' => $this->modelSearch,
            'provider' => $this->modelProvider,
            'type' => $this->modelType,
            'status' => $this->modelStatus,
            'show_hidden' => $this->showHidden,
            'technical' => $this->modelTechnical,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function areaModelRows(?string $area = null): array
    {
        return $this->areaModelRowsFor($area ?? $this->modelArea);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function areaModelRowsFor(string $area): array
    {
        return app(AiModelInventory::class)->enabledRows(
            (int) auth()->id(),
            AiModelArea::tryFromMixed($area),
            [
                'search' => $this->modelSearch,
                'provider' => $this->modelProvider,
                'status' => $this->modelStatus,
                'technical' => $this->modelTechnical,
            ],
        );
    }

    /**
     * @return array<string, array{enabled: int, available: int}>
     */
    public function areaCounts(): array
    {
        return app(AiModelInventory::class)->areaCounts((int) auth()->id());
    }

    /**
     * @return array{discovered: int, enabled: int}
     */
    public function modelCounts(): array
    {
        return app(AiCenterModelPresenter::class)->counts((int) auth()->id());
    }

    /**
     * @return array<string, string>
     */
    public function aiProviderFilterOptions(): array
    {
        $keys = [
            ApiConnectionProviders::GEMINI,
            ApiConnectionProviders::DEEPSEEK,
            ApiConnectionProviders::OPENROUTER,
            ApiConnectionProviders::CLAUDE,
        ];
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = ApiConnectionProviders::label($key);
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function routingCards(?string $group = null): array
    {
        return $this->routingCardsFor($group ?? $this->routingGroup);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function routingCardsFor(string $group): array
    {
        $inventory = app(AiModelInventory::class);
        $targets = app(AiRoutingTargetService::class);
        $userId = (int) auth()->id();
        $cards = [];
        foreach (AiExecutionProfile::inGroup($group) as $profile) {
            $key = $this->formKey($profile);
            $item = (array) ($this->routingData[$key] ?? []);
            $mode = (string) ($item['selection_mode'] ?? 'automatic');
            $options = $inventory->executionOptions($userId, $profile);
            $familyKeys = array_values(array_filter(
                array_map(static fn (mixed $value): string => (string) $value, (array) ($item['family_keys'] ?? [])),
                static fn (string $family): bool => $family !== '' && $family !== AiModelFamilyCatalog::AUTOMATIC,
            ));
            $familyLabels = [];
            $unavailable = [];
            foreach ($familyKeys as $familyKey) {
                if (isset($options[$familyKey])) {
                    $familyLabels[$familyKey] = $options[$familyKey];
                } else {
                    $unavailable[$familyKey] = $familyKey;
                }
            }
            $cards[] = [
                'key' => $key,
                'name' => $profile->displayName(),
                'description' => $profile->description(),
                'enabled' => (bool) ($item['enabled'] ?? true),
                'selection_mode' => $mode === 'custom' ? 'custom' : 'automatic',
                'family_keys' => array_keys($familyLabels),
                'family_labels' => $familyLabels,
                'family_options' => $options,
                'unavailable_keys' => $unavailable,
                'eligible_count' => $inventory->eligibleCount($userId, $profile),
                'editing' => $this->editingProfile === $key,
                'resolved_order' => $this->showRoutingTechnical
                    ? $this->resolvedOrderText($targets, $profile)
                    : '',
            ];
        }

        return $cards;
    }

    public function openImportModal(): void
    {
        $this->assertManager();
        $this->showImportModal = true;
    }

    public function closeImportModal(): void
    {
        $this->showImportModal = false;
        $this->templateFile = null;
        $this->importJson = '';
        $this->importPreview = null;
        $this->importWarnings = [];
        $this->importDiff = [];
        $this->pendingTemplateJson = null;
    }

    public function updatedTemplateFile(): void
    {
        $this->assertManager();
        $this->importPreview = null;
        if (! $this->templateFile instanceof TemporaryUploadedFile) {
            return;
        }
        $path = $this->templateFile->getRealPath();
        if (! is_string($path) || ! is_readable($path)) {
            return;
        }
        $this->importJson = (string) file_get_contents($path);
        $this->previewImport(app(AiProviderTemplateParser::class));
    }

    public function downloadTemplate(): StreamedResponse
    {
        $this->assertManager();
        $json = app(AiProviderTemplateCatalog::class)->downloadableDocument();

        return response()->streamDownload(static function () use ($json): void {
            echo $json;
        }, 'seo-ops-ai-provider-template.json', [
            'Content-Type' => 'application/json; charset=UTF-8',
        ]);
    }

    public function previewImport(AiProviderTemplateParser $parser): void
    {
        $this->assertManager();
        $this->importPreview = null;
        $this->importWarnings = [];
        $this->importDiff = [];
        $this->pendingTemplateJson = null;
        try {
            $parsed = $parser->parse($this->importJson);
            $this->importPreview = $parsed->preview();
            $this->importWarnings = $parsed->warnings;
            $this->pendingTemplateJson = json_encode($parsed->toStorageArray(), JSON_THROW_ON_ERROR);
            $existing = \Omnichannel\Addons\AiPrompt\Models\AiProviderTemplate::query()
                ->where('user_id', (int) auth()->id())
                ->where('provider_key', $parsed->providerKey)
                ->first();
            if ($existing !== null && is_array($existing->config)) {
                $this->importDiff = app(AiProviderTemplateStore::class)->diff($existing->config, $parsed->toStorageArray());
            }
        } catch (AiProviderTemplateException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();
        }
    }

    public function confirmImport(AiProviderTemplateParser $parser, AiProviderTemplateStore $store): void
    {
        $this->assertManager();
        if (! is_string($this->pendingTemplateJson) || $this->pendingTemplateJson === '') {
            Notification::make()->title(__('seo-content-ai::filament.ai_center.import_preview_first'))->danger()->send();

            return;
        }
        try {
            $parsed = $parser->parse($this->pendingTemplateJson);
            $userId = (int) auth()->id();
            $store->persist($userId, $parsed);
            $store->createDraftConnection($userId, $parsed);
            $this->closeImportModal();
            Notification::make()->title(__('seo-content-ai::filament.ai_center.imported'))->success()->send();
        } catch (AiProviderTemplateException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();
        }
    }

    public function exportTemplate(int $connectionId, AiProviderTemplateStore $store): StreamedResponse
    {
        $this->assertManager();
        $connection = $this->ownedConnection($connectionId);
        $json = $store->exportForConnection((int) auth()->id(), $connection);

        return response()->streamDownload(static function () use ($json): void {
            echo $json;
        }, 'seo-ops-ai-provider-'.$connection->provider.'.json', [
            'Content-Type' => 'application/json; charset=UTF-8',
        ]);
    }

    public function testConnection(int $connectionId, AiProviderConnectionTester $tester): void
    {
        $this->assertManager();
        $connection = $this->ownedConnection($connectionId);
        $this->testStages[$connectionId] = $tester->test($connection);
    }

    public function syncConnection(int $connectionId, AiModelRouterService $router): void
    {
        $this->assertManager();
        $connection = $this->ownedConnection($connectionId);
        $ok = $router->syncModelsForConnection((int) $connection->id);
        $notification = Notification::make()
            ->title($ok
                ? __('seo-content-ai::filament.ai_center.sync_ok')
                : __('seo-content-ai::filament.ai_center.sync_failed'));
        if ($ok) {
            $notification->success()->send();
        } else {
            $notification->danger()->send();
        }
        $this->bustInventoryCache();
    }

    public function toggleConnection(int $connectionId): void
    {
        $this->assertManager();
        $connection = $this->ownedConnection($connectionId);
        if ((bool) $connection->is_global) {
            Notification::make()->title(__('seo-content-ai::filament.keyword.workspace_save_denied'))->danger()->send();

            return;
        }
        $connection->status = (string) $connection->status === 'inactive' ? 'active' : 'inactive';
        $connection->save();
        $this->bustInventoryCache();
    }

    public function deleteConnection(int $connectionId): void
    {
        $this->assertManager();
        $connection = $this->ownedConnection($connectionId);
        if (! AiConnectionResource::deleteRecord($connection)) {
            Notification::make()
                ->title(__('seo-content-ai::filament.api_connections.delete_connection'))
                ->danger()
                ->send();

            return;
        }
        Notification::make()
            ->title(__('seo-content-ai::filament.api_connections.delete_connection_success'))
            ->success()
            ->send();
    }

    public function syncAllModels(AiModelRouterService $router): void
    {
        $this->assertManager();
        $result = $router->syncAllConnectionsForUser();
        $notification = Notification::make()
            ->title($result['failed'] === 0
                ? __('seo-content-ai::filament.ai_center.sync_ok')
                : __('seo-content-ai::filament.ai_center.sync_failed'));
        $result['failed'] === 0 ? $notification->success()->send() : $notification->warning()->send();
        $this->bustInventoryCache();
    }

    /**
     * @param  list<int>  $ids
     */
    public function toggleHidden(array $ids, bool $hidden, AiCenterModelPresenter $presenter): void
    {
        $this->assertManager();
        $area = AiModelArea::tryFromMixed($this->modelArea);
        $priorities = app(AiModelPriorityService::class);
        if ($hidden) {
            $priorities->removeFromArea((int) auth()->id(), $area, $ids);
        } else {
            $priorities->appendToArea((int) auth()->id(), $area, $ids);
        }
        unset($presenter);
        $this->bustInventoryCache();
        $this->reconcileRoutingDraftAgainstInventory();
    }

    /**
     * @param  list<int>  $orderedIds
     */
    public function reorderAreaModels(array $orderedIds): bool
    {
        return $this->reorderCapabilityModels($this->modelArea, $orderedIds);
    }

    /**
     * @param  list<int|string>  $orderedTargetIds
     */
    public function reorderCapabilityModels(string $capabilityArea, array $orderedTargetIds): bool
    {
        $this->assertManager();
        $ids = array_values(array_map(static fn (mixed $id): int => (int) $id, $orderedTargetIds));
        try {
            app(AiModelPriorityService::class)->reorderCapabilityModels(
                (int) auth()->id(),
                AiModelArea::tryFromMixed($capabilityArea),
                $ids,
            );
            $this->bustInventoryCache();

            return true;
        } catch (\Throwable $exception) {
            Notification::make()
                ->title(__('seo-content-ai::filament.ai_center.reorder_failed'))
                ->danger()
                ->send();

            return false;
        }
    }

    public function moveAreaModel(int $targetId, int $delta): void
    {
        $this->assertManager();
        if (! $this->canReorderModels()) {
            return;
        }
        $ids = array_map(
            static fn (array $row): int => (int) ($row['ids'][0] ?? 0),
            $this->areaModelRows(),
        );
        $ids = array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
        $from = array_search($targetId, $ids, true);
        if ($from === false) {
            return;
        }
        $to = $from + $delta;
        if ($to < 0 || $to >= count($ids)) {
            return;
        }
        array_splice($ids, $from, 1);
        array_splice($ids, $to, 0, [$targetId]);
        $this->reorderCapabilityModels($this->modelArea, $ids);
    }

    public function canReorderModels(): bool
    {
        return trim($this->modelSearch) === ''
            && ($this->modelProvider === '' || $this->modelProvider === 'all')
            && ($this->modelStatus === '' || $this->modelStatus === 'all')
            && ! $this->modelTechnical;
    }

    public function openModelPicker(?int $connectionId = null): void
    {
        $this->assertManager();
        $this->pickerOpen = true;
        $this->pickerConnectionId = $connectionId;
        $this->pickerSearch = '';
        $this->pickerProvider = 'all';
        $this->pickerType = 'all';
        $this->pickerStatus = 'available';
        $this->pickerPage = 1;
    }

    public function closeModelPicker(): void
    {
        $this->pickerOpen = false;
        $this->pickerConnectionId = null;
        $this->pickerPage = 1;
    }

    public function updatedPickerSearch(): void
    {
        $this->pickerPage = 1;
    }

    public function updatedPickerProvider(): void
    {
        $this->pickerPage = 1;
    }

    public function updatedPickerStatus(): void
    {
        $this->pickerPage = 1;
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, per_page: int, last_page: int, connection_name: string}
     */
    public function pickerState(): array
    {
        if (! $this->pickerOpen) {
            return ['rows' => [], 'total' => 0, 'page' => 1, 'per_page' => 50, 'last_page' => 1, 'connection_name' => '', 'area' => $this->modelArea];
        }
        $page = app(AiCenterModelPresenter::class)->availablePage(
            (int) auth()->id(),
            $this->pickerConnectionId,
            [
                'search' => $this->pickerSearch,
                'type' => $this->pickerType,
                'status' => $this->pickerStatus,
                'provider' => $this->pickerProvider,
                'area' => $this->modelArea,
            ],
            $this->pickerPage,
            50,
        );
        $page['connection_name'] = '';
        $page['area'] = $this->modelArea;

        return $page;
    }

    /**
     * @param  list<int>  $ids
     */
    public function addAvailableModels(array $ids, AiCenterModelPresenter $presenter): void
    {
        $this->assertManager();
        $presenter->setHidden((int) auth()->id(), $ids, false);
        app(AiModelPriorityService::class)->appendToArea(
            (int) auth()->id(),
            AiModelArea::tryFromMixed($this->modelArea),
            $ids,
        );
        $this->bustInventoryCache();
    }

    public function pickerPrevPage(): void
    {
        $this->pickerPage = max(1, $this->pickerPage - 1);
    }

    public function pickerNextPage(): void
    {
        if ($this->pickerConnectionId === null && ! $this->pickerOpen) {
            return;
        }
        $state = $this->pickerState();
        $this->pickerPage = min((int) $state['last_page'], $this->pickerPage + 1);
    }

    #[Renderless]
    public function startEditProfile(string $formKey): void
    {
        $this->editingProfile = $formKey;
    }

    #[Renderless]
    public function stopEditProfile(): void
    {
        $this->editingProfile = null;
    }

    #[Renderless]
    public function setSelectionMode(string $formKey, string $mode): void
    {
        $this->assertManager();
        $this->routingData[$formKey]['selection_mode'] = $mode === 'custom' ? 'custom' : 'automatic';
        if ($mode !== 'custom') {
            $this->routingData[$formKey]['family_keys'] = [];
        }
        $this->routingUnsaved = true;
    }

    #[Renderless]
    public function toggleFamily(string $formKey, string $familyKey): void
    {
        $this->assertManager();
        $keys = array_values(array_filter(
            array_map(static fn (mixed $value): string => (string) $value, (array) ($this->routingData[$formKey]['family_keys'] ?? [])),
            static fn (string $key): bool => $key !== AiModelFamilyCatalog::AUTOMATIC,
        ));
        if (in_array($familyKey, $keys, true)) {
            $keys = array_values(array_filter($keys, static fn (string $key): bool => $key !== $familyKey));
        } else {
            $keys[] = $familyKey;
        }
        $this->routingData[$formKey]['family_keys'] = $keys;
        $this->routingData[$formKey]['selection_mode'] = 'custom';
        $this->routingUnsaved = true;
    }

    #[Renderless]
    public function toggleRoutingEnabled(string $formKey): void
    {
        $this->assertManager();
        $current = (bool) ($this->routingData[$formKey]['enabled'] ?? true);
        $this->routingData[$formKey]['enabled'] = ! $current;
        $this->routingUnsaved = true;
    }

    public function saveRouting(AiRoutingTargetService $targets, SeoCreateArticleSettingsService $settings): void
    {
        $this->assertManager();
        $userId = (int) auth()->id();
        $mode = AiUsageMode::tryFromMixed($this->globalUsageMode) ?? AiUsageMode::Economy;
        $settings->saveSettings([
            SeoCreateArticleSettingsService::KEY_DEFAULT_AI_USAGE_MODE => $mode->value,
        ]);
        $inventory = app(AiModelInventory::class);

        try {
            foreach (AiExecutionProfile::cases() as $profile) {
                $formKey = $this->formKey($profile);
                $item = (array) ($this->routingData[$formKey] ?? []);
                $baseline = (array) ($this->routingSavedSnapshot[$formKey] ?? []);
                if ($baseline !== [] && $this->routingProfileUnchanged($item, $baseline)) {
                    continue;
                }
                $automatic = ($item['selection_mode'] ?? 'automatic') !== 'custom';
                $familyKeys = $automatic
                    ? [AiModelFamilyCatalog::AUTOMATIC]
                    : array_values(array_filter(
                        array_map(static fn (mixed $key): string => (string) $key, (array) ($item['family_keys'] ?? [])),
                        static fn (string $key): bool => $key !== '' && $key !== AiModelFamilyCatalog::AUTOMATIC,
                    ));
                if (! $automatic) {
                    $options = $inventory->executionOptions($userId, $profile);
                    $familyKeys = array_values(array_filter(
                        $familyKeys,
                        static fn (string $key): bool => isset($options[$key]),
                    ));
                    $this->routingData[$formKey]['family_keys'] = $familyKeys;
                }
                $targets->saveSimplifiedSelection(
                    $userId,
                    $profile,
                    $familyKeys,
                    $mode,
                    (bool) ($item['enabled'] ?? true),
                    ! $profile->isMedia(),
                );
            }
        } catch (\Throwable $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            return;
        }

        $this->editingProfile = null;
        $this->routingUnsaved = false;
        $this->routingSavedSnapshot = $this->routingData;
        Notification::make()->title(__('seo-content-ai::filament.settings_ai_routing.saved'))->success()->send();
    }

    public static function canAccess(): bool
    {
        return SeoAccessControl::canAccessManagerFeatures();
    }

    public static function shouldRegisterNavigation(array $parameters = []): bool
    {
        unset($parameters);

        return false;
    }

    private function fillRouting(AiRoutingTargetService $targets, int $userId): void
    {
        $fill = [];
        foreach (AiExecutionProfile::cases() as $profile) {
            $settings = $targets->profileSettings($userId, $profile);
            $families = is_array($settings['allowed_family_keys'] ?? null)
                ? array_values(array_map(static fn (mixed $key): string => (string) $key, $settings['allowed_family_keys']))
                : [];
            $execution = is_array($settings['allowed_execution_keys'] ?? null)
                ? array_values(array_filter(
                    array_map(static fn (mixed $key): string => (string) $key, $settings['allowed_execution_keys']),
                    static fn (string $key): bool => $key !== '',
                ))
                : [];
            $automatic = $execution === [] && ($families === [] || in_array(AiModelFamilyCatalog::AUTOMATIC, $families, true));
            if (! $automatic && $execution === [] && $families !== []) {
                foreach ($targets->eligibleExecutionOptionMap($userId, $profile) as $execKey => $label) {
                    unset($label);
                    $parts = explode('|', (string) $execKey, 2);
                    $familyKey = $parts[1] ?? (string) $execKey;
                    if (in_array($familyKey, $families, true) || in_array((string) $execKey, $families, true)) {
                        $execution[] = (string) $execKey;
                    }
                }
            }
            $fill[$this->formKey($profile)] = [
                'selection_mode' => $automatic ? 'automatic' : 'custom',
                'family_keys' => $automatic ? [] : ($execution !== [] ? array_values(array_unique($execution)) : array_values(array_filter(
                    $families,
                    static fn (string $key): bool => $key !== AiModelFamilyCatalog::AUTOMATIC,
                ))),
                'enabled' => (bool) ($settings['enabled'] ?? true),
            ];
        }
        $this->routingData = $fill;
        $this->routingSavedSnapshot = $fill;
        $this->routingUnsaved = false;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $baseline
     */
    private function routingProfileUnchanged(array $item, array $baseline): bool
    {
        $modeA = (($item['selection_mode'] ?? 'automatic') === 'custom') ? 'custom' : 'automatic';
        $modeB = (($baseline['selection_mode'] ?? 'automatic') === 'custom') ? 'custom' : 'automatic';
        if ($modeA !== $modeB) {
            return false;
        }
        if ((bool) ($item['enabled'] ?? true) !== (bool) ($baseline['enabled'] ?? true)) {
            return false;
        }
        $keysA = array_values(array_map('strval', (array) ($item['family_keys'] ?? [])));
        $keysB = array_values(array_map('strval', (array) ($baseline['family_keys'] ?? [])));
        sort($keysA);
        sort($keysB);

        return $keysA === $keysB;
    }

    private function reconcileRoutingDraftAgainstInventory(): void
    {
        $inventory = app(AiModelInventory::class);
        $userId = (int) auth()->id();
        foreach (AiExecutionProfile::cases() as $profile) {
            $formKey = $this->formKey($profile);
            $item = (array) ($this->routingData[$formKey] ?? []);
            if (($item['selection_mode'] ?? 'automatic') !== 'custom') {
                continue;
            }
            $options = $inventory->executionOptions($userId, $profile);
            $keys = array_values(array_filter(
                array_map(static fn (mixed $key): string => (string) $key, (array) ($item['family_keys'] ?? [])),
                static fn (string $key): bool => isset($options[$key]),
            ));
            $this->routingData[$formKey]['family_keys'] = $keys;
        }
    }

    private function bustInventoryCache(): void
    {
        app(AiModelInventory::class)->forget();
        app(AiConnectionPresenter::class)->forgetMemo();
        app(AiExecutionTargetPresenter::class)->forgetMemo();
    }

    private function resolvedOrderText(
        AiRoutingTargetService $targets,
        AiExecutionProfile $profile,
    ): string {
        $presenter = app(AiExecutionTargetPresenter::class);
        $userId = (int) auth()->id();
        $rows = [];
        foreach ($targets->eligibleCandidates($userId, $profile, new \Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext(userId: $userId)) as $index => $candidate) {
            $label = $presenter->present($candidate->connection, $candidate->model, null, $userId);
            $rows[] = ($index + 1).'. '.$label['full_label'];
        }

        return $rows !== [] ? implode("\n", $rows) : (string) __('seo-content-ai::filament.prompt.routing_empty');
    }

    private function formKey(AiExecutionProfile $profile): string
    {
        return str_replace('.', '__', $profile->value);
    }

    private function ownedConnection(int $connectionId): ApiConnection
    {
        $userId = (int) auth()->id();
        $connection = ApiConnection::query()->find($connectionId);
        if (! $connection instanceof ApiConnection) {
            throw AiProviderTemplateException::rejected('Connection not found.');
        }
        if ((int) $connection->user_id !== $userId && ! (bool) $connection->is_global) {
            throw AiProviderTemplateException::rejected('Not authorized to use this connection.');
        }

        return $connection;
    }

    private function assertManager(): void
    {
        if (! SeoAccessControl::canAccessManagerFeatures()) {
            abort(403);
        }
    }
}
