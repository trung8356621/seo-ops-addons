<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Filament\Pages;

use Omnichannel\Addons\Agent\Automation\Presentation\Workflow\AutomationWorkflowProjectionService;
use Omnichannel\Addons\Agent\Automation\Presentation\Workflow\AutomationWorkflowViewerPayload;
use Omnichannel\Addons\Seo\Filament\Concerns\BelongsToAdminAutomationPanel;
use Omnichannel\Addons\Seo\Filament\Concerns\RedirectsSeoAutomationToAdmin;
use Omnichannel\Addons\Agent\Filament\Resources\AutomationExecutionResource;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

class AutomationFlowsPage extends Page
{
    use BelongsToAdminAutomationPanel;
    use RedirectsSeoAutomationToAdmin;

    protected static ?string $slug = 'automation/flows';

    protected static ?string $navigationIcon = 'heroicon-o-map';

    protected static ?int $navigationSort = 0;

    protected static ?string $navigationLabel = null;

    protected static ?string $title = 'Automation Flows';

    protected static string $view = 'seo-content-ai::filament.pages.automation-flows';

    #[Url]
    public string $viewMode = 'workflows';

    #[Url]
    public string $category = '';

    #[Url]
    public string $eventName = '';

    #[Url]
    public string $health = '';

    #[Url]
    public string $mapping = '';

    #[Url]
    public ?string $workflowId = null;

    #[Url]
    public ?string $flowId = null;

    public string $search = '';

    /** @var list<array<string, mixed>> */
    public array $workflows = [];

    /** @var list<array<string, mixed>> */
    public array $components = [];

    /** @var list<array<string, mixed>> */
    public array $unmapped = [];

    /** @var array<string, mixed>|null */
    public ?array $selectedWorkflow = null;

    /** @var array<string, mixed>|null */
    public ?array $viewerWorkflow = null;

    /** @var array<string, mixed>|null */
    public ?array $selectedFlow = null;

    /** @var array<string, string> */
    public array $categoryOptions = [];

    /** @var array<string, string> */
    public array $eventOptions = [];

    /** @var array{workflows: int, components: int, mapped: int, unmapped: int} */
    public array $summary = [
        'workflows' => 0,
        'components' => 0,
        'mapped' => 0,
        'unmapped' => 0,
    ];

    /**
     * @param  array<string, mixed>  $parameters
     */
    public static function getUrl(
        array $parameters = [],
        bool $isAbsolute = true,
        ?string $panel = null,
        ?\Illuminate\Database\Eloquent\Model $tenant = null,
    ): string {
        return parent::getUrl(
            $parameters,
            $isAbsolute,
            $panel ?? self::adminPanelId(),
            $tenant,
        );
    }

    public static function getNavigationLabel(): string
    {
        return __('seo-content-ai::filament.automation.nav_flows');
    }

    public static function canAccess(): bool
    {
        return SeoAccessControl::canViewAutomation();
    }

    public function mount(AutomationWorkflowProjectionService $projection): void
    {
        if ($this->redirectSeoAutomationToAdmin(static::getUrl(
            parameters: array_filter([
                'viewMode' => $this->viewMode !== 'workflows' ? $this->viewMode : null,
                'workflowId' => $this->workflowId,
                'flowId' => $this->flowId,
                'category' => $this->category !== '' ? $this->category : null,
            ]),
        ))) {
            return;
        }

        abort_unless(SeoAccessControl::canViewAutomation(), 403);
        $this->refreshProjection($projection);
    }

    public function updatedViewMode(): void
    {
        $this->selectedWorkflow = null;
        $this->viewerWorkflow = null;
        $this->selectedFlow = null;
        $this->workflowId = null;
        $this->flowId = null;
        $this->refreshProjection();
    }

    public function updatedCategory(): void
    {
        $this->refreshProjection();
    }

    public function updatedEventName(): void
    {
        $this->refreshProjection();
    }

    public function updatedHealth(): void
    {
        $this->refreshProjection();
    }

    public function updatedMapping(): void
    {
        $this->refreshProjection();
    }

    public function setViewMode(string $mode): void
    {
        $allowed = ['workflows', 'components', 'unmapped'];
        if (! in_array($mode, $allowed, true)) {
            return;
        }
        $this->viewMode = $mode;
        $this->updatedViewMode();
    }

    public function selectWorkflow(string $workflowId): void
    {
        $this->workflowId = $workflowId;
        $this->flowId = null;
        $this->selectedFlow = null;
        $this->refreshProjection();
    }

    public function selectFlow(string $flowId): void
    {
        $this->flowId = $flowId;
        $this->workflowId = null;
        $this->selectedWorkflow = null;
        $this->viewerWorkflow = null;
        $this->refreshProjection();
    }

    public function updatedSearch(): void
    {
        // Client filter via filteredWorkflows(); no server re-query.
    }

    /**
     * Mobile <x-select wire:model.live="workflowId"> path.
     * Desktop list uses selectWorkflow() which already refreshes — skip duplicate.
     */
    public function updatedWorkflowId(?string $value): void
    {
        if ($value !== null && $value !== '' && ($this->viewerWorkflow['id'] ?? null) === $value) {
            return;
        }

        $this->flowId = null;
        $this->selectedFlow = null;

        if ($value === null || $value === '') {
            $this->selectedWorkflow = null;
            $this->viewerWorkflow = null;

            return;
        }

        $this->refreshProjection();
    }

    public function clearSelection(): void
    {
        $this->workflowId = null;
        $this->flowId = null;
        $this->selectedWorkflow = null;
        $this->viewerWorkflow = null;
        $this->selectedFlow = null;
    }

    /**
     * @return array<string, mixed>
     */
    public function viewerProps(): array
    {
        return [
            'workflow' => $this->viewerWorkflow,
            'executions_url' => $this->executionsIndexUrl(),
            'operations_url' => $this->operationsUrl(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function filteredWorkflows(): array
    {
        $q = mb_strtolower(trim($this->search));
        if ($q === '') {
            return $this->workflows;
        }

        return array_values(array_filter(
            $this->workflows,
            static function (array $workflow) use ($q): bool {
                $hay = mb_strtolower(
                    ($workflow['name'] ?? '').' '.($workflow['category'] ?? '').' '.($workflow['id'] ?? '')
                );

                return str_contains($hay, $q);
            },
        ));
    }

    public function getTitle(): string
    {
        return __('seo-content-ai::filament.automation.flows.title');
    }

    public function executionsIndexUrl(): string
    {
        return AutomationExecutionResource::getUrl('index');
    }

    public function operationsUrl(): string
    {
        return AutomationOperationsDashboard::getUrl();
    }

    private function refreshProjection(?AutomationWorkflowProjectionService $projection = null): void
    {
        $projection ??= app(AutomationWorkflowProjectionService::class);
        $serializer = app(AutomationWorkflowViewerPayload::class);
        $this->summary = $projection->summary();

        if ($this->viewMode === 'components') {
            $this->categoryOptions = $projection->componentCategoryOptions();
            $this->eventOptions = $projection->componentEventOptions();
            $this->components = $projection->listComponents(
                category: $this->category !== '' ? $this->category : null,
                eventName: $this->eventName !== '' ? $this->eventName : null,
                health: $this->health !== '' ? $this->health : null,
            );
            $this->workflows = [];
            $this->unmapped = [];
            $this->selectedWorkflow = null;
            $this->viewerWorkflow = null;
            if ($this->flowId !== null && $this->flowId !== '') {
                $this->selectedFlow = $projection->findComponent($this->flowId);
                if ($this->selectedFlow === null) {
                    $this->flowId = null;
                }
            } else {
                $this->selectedFlow = null;
            }

            return;
        }

        if ($this->viewMode === 'unmapped') {
            $this->categoryOptions = [];
            $this->eventOptions = [];
            $this->unmapped = $projection->listUnmapped();
            $this->workflows = [];
            $this->components = [];
            $this->selectedWorkflow = null;
            $this->viewerWorkflow = null;
            $this->selectedFlow = null;

            return;
        }

        $this->viewMode = 'workflows';
        $this->categoryOptions = $projection->workflowCategoryOptions();
        $this->eventOptions = [];
        $this->workflows = $projection->listWorkflows(
            category: $this->category !== '' ? $this->category : null,
            mapping: $this->mapping !== '' ? $this->mapping : null,
            health: $this->health !== '' ? $this->health : null,
        );
        $this->components = [];
        $this->unmapped = [];
        $this->selectedFlow = null;

        if ($this->workflowId !== null && $this->workflowId !== '') {
            $this->selectedWorkflow = $projection->findWorkflow($this->workflowId);
            if ($this->selectedWorkflow === null) {
                $this->workflowId = null;
                $this->viewerWorkflow = null;
            } else {
                $payload = $serializer->fromProjectedWorkflow($this->selectedWorkflow);
                $payload['links'] = [
                    'executions' => $this->executionsIndexUrl(),
                    'operations' => $this->operationsUrl(),
                ];
                $this->viewerWorkflow = $payload;
            }
        } else {
            $this->selectedWorkflow = null;
            $this->viewerWorkflow = null;
        }
    }
}
