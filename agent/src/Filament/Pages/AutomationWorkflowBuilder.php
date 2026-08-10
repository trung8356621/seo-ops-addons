<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Filament\Pages;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRule;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\BusinessEvent;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Registry\AutomationActionRegistry;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Registry\BusinessEventRegistry;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Services\AutomationExecutionService;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Services\AutomationVersionService;
use Omnichannel\Addons\Agent\Automation\Exceptions\AutomationException;
use Omnichannel\Addons\Seo\Filament\Concerns\BelongsToAdminAutomationPanel;
use Omnichannel\Addons\Seo\Filament\Concerns\RedirectsSeoAutomationToAdmin;
use Omnichannel\Addons\Agent\Filament\Resources\AutomationRuleResource;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Livewire\Attributes\Url;

class AutomationWorkflowBuilder extends Page
{
    use BelongsToAdminAutomationPanel;
    use RedirectsSeoAutomationToAdmin;

    protected static ?string $slug = 'automation/workflow-builder';

    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?int $navigationSort = 89;

    protected static string $view = 'seo-content-ai::filament.pages.automation-workflow-builder';

    #[Url]
    public ?int $rule = null;

    public ?AutomationRule $record = null;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

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

    public function mount(): void
    {
        if ($this->redirectSeoAutomationToAdmin(static::getUrl(
            array_filter(['rule' => $this->rule]),
        ))) {
            return;
        }
        abort_unless(SeoAccessControl::canViewAutomation(), 403);
        abort_if($this->rule === null || $this->rule <= 0, 404);

        $this->record = AutomationRule::query()
            ->with(['nodes', 'edges'])
            ->findOrFail($this->rule);
    }

    public static function canAccess(): bool
    {
        return SeoAccessControl::canViewAutomation();
    }

    public function getMaxContentWidth(): MaxWidth|string|null
    {
        return MaxWidth::Full;
    }

    public function getHeading(): string
    {
        return '';
    }

    public function getSubheading(): ?string
    {
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getBuilderProps(): array
    {
        $rule = $this->record;
        if (! $rule instanceof AutomationRule) {
            return [];
        }

        return [
            'rule' => [
                'id' => $rule->id,
                'code' => $rule->code,
                'name' => $rule->name,
                'event_name' => $rule->event_name,
                'trigger_type' => $rule->trigger_type,
                'is_enabled' => $rule->is_enabled,
                'workflow_mode' => $rule->workflow_mode,
                'version' => $rule->version,
            ],
            'graph' => $this->serializeGraph($rule),
            'draft_revision' => (int) $rule->draft_revision,
            'registry' => $this->buildRegistryPayload(),
            'permissions' => [
                'view' => SeoAccessControl::canViewAutomation(),
                'edit' => SeoAccessControl::canEditAutomation(),
                'publish' => SeoAccessControl::canPublishAutomation(),
                'enable' => SeoAccessControl::canEnableAutomation(),
                'execute_test' => SeoAccessControl::canExecuteAutomationTest(),
            ],
            'back_url' => AutomationRuleResource::getUrl('edit', ['record' => $rule]),
            'back_label' => 'Back to rule',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function saveDraft(array $payload): void
    {
        SeoAccessControl::guardAutomationEdit();

        try {
            $rule = $this->resolveRule();
            $nodes = is_array($payload['nodes'] ?? null) ? $payload['nodes'] : [];
            $edges = is_array($payload['edges'] ?? null) ? $payload['edges'] : [];
            $layout = is_array($payload['layout'] ?? null) ? $payload['layout'] : null;
            $expectedRevision = isset($payload['draft_revision']) ? (int) $payload['draft_revision'] : null;

            $updated = app(AutomationVersionService::class)->saveDraft(
                $rule,
                $nodes,
                $edges,
                $layout,
                $expectedRevision,
                auth()->id() ? (int) auth()->id() : null,
            );

            $this->record = $updated->fresh(['nodes', 'edges']);

            $this->dispatch('automation-workflow-saved', [
                'draft_revision' => (int) $updated->draft_revision,
                'message' => 'Draft saved.',
            ]);
        } catch (AutomationException $e) {
            $this->dispatch('automation-workflow-save-failed', [
                'message' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            $this->dispatch('automation-workflow-save-failed', [
                'message' => 'Save failed: '.$e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function validateGraph(array $payload = []): void
    {
        abort_unless(SeoAccessControl::canViewAutomation(), 403);

        try {
            if ($payload !== [] && SeoAccessControl::canEditAutomation()) {
                $this->saveDraftInternal($payload);
            }

            $rule = $this->resolveRule()->fresh(['nodes', 'edges']);
            $result = app(AutomationVersionService::class)->validateDraft($rule);

            $this->dispatch('automation-workflow-validated', $result);
        } catch (\Throwable $e) {
            $this->dispatch('automation-workflow-validated', [
                'valid' => false,
                'errors' => [$e->getMessage()],
                'warnings' => [],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function publish(array $payload = []): void
    {
        SeoAccessControl::guardAutomationPublish();

        try {
            if ($payload !== [] && isset($payload['nodes'])) {
                $this->saveDraftInternal($payload);
            }

            $rule = $this->resolveRule()->fresh(['nodes', 'edges']);
            $expectedRevision = isset($payload['draft_revision']) ? (int) $payload['draft_revision'] : null;
            if ($expectedRevision !== null && (int) $rule->draft_revision !== $expectedRevision) {
                throw new AutomationException(
                    \Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\BusinessHookErrorCode::DraftConflict->value,
                    'Draft revision conflict.',
                );
            }

            app(AutomationVersionService::class)->publish(
                $rule,
                auth()->id() ? (int) auth()->id() : null,
            );

            $this->record = $rule->fresh(['nodes', 'edges']);

            $this->dispatch('automation-workflow-published', [
                'message' => 'Rule published.',
                'version' => (int) $this->record->version,
            ]);
        } catch (AutomationException $e) {
            $this->dispatch('automation-workflow-publish-failed', [
                'message' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            $this->dispatch('automation-workflow-publish-failed', [
                'message' => 'Publish failed: '.$e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function testDryRun(array $payload): void
    {
        SeoAccessControl::guardAutomationExecuteTest();

        try {
            if (isset($payload['nodes']) && SeoAccessControl::canEditAutomation()) {
                $this->saveDraftInternal($payload);
            }

            $rule = $this->resolveRule()->fresh(['nodes', 'edges']);
            $validation = app(AutomationVersionService::class)->validateDraft($rule);
            if (! $validation['valid']) {
                throw new AutomationException(
                    \Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\BusinessHookErrorCode::GraphValidationFailed->value,
                    implode(' ', $validation['errors']),
                );
            }

            $eventId = (int) ($payload['event_id'] ?? 0);
            if ($eventId <= 0) {
                $this->dispatch('automation-workflow-tested', [
                    'message' => 'Graph validated (no event_id — skipped execution).',
                    'validation' => $validation,
                ]);

                return;
            }

            $event = BusinessEvent::query()->findOrFail($eventId);
            $executionService = app(AutomationExecutionService::class);
            $execution = $executionService->createPendingExecution($event, $rule);
            if ($execution === null) {
                $this->dispatch('automation-workflow-tested', [
                    'message' => 'Idempotent execution already exists for this event/rule.',
                ]);

                return;
            }

            $result = $executionService->run((int) $execution->id, true);

            $this->dispatch('automation-workflow-tested', [
                'message' => 'Dry run finished: '.$result->status,
                'execution_id' => $result->id,
                'status' => $result->status,
            ]);
        } catch (AutomationException $e) {
            $this->dispatch('automation-workflow-test-failed', [
                'message' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            $this->dispatch('automation-workflow-test-failed', [
                'message' => 'Dry run failed: '.$e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function exportJson(array $payload = []): void
    {
        abort_unless(SeoAccessControl::canViewAutomation(), 403);

        $graph = [
            'nodes' => $payload['nodes'] ?? $this->serializeGraph($this->resolveRule())['nodes'],
            'edges' => $payload['edges'] ?? $this->serializeGraph($this->resolveRule())['edges'],
        ];

        $this->dispatch('automation-workflow-exported', [
            'json' => json_encode($graph, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function importJson(array $payload): void
    {
        SeoAccessControl::guardAutomationEdit();

        $graph = $payload['graph'] ?? null;
        if (! is_array($graph) || ! is_array($graph['nodes'] ?? null)) {
            Notification::make()->title('Invalid graph payload')->danger()->send();

            return;
        }

        $this->saveDraftInternal([
            'nodes' => $graph['nodes'],
            'edges' => is_array($graph['edges'] ?? null) ? $graph['edges'] : [],
            'draft_revision' => $payload['draft_revision'] ?? null,
        ]);

        $this->record = $this->resolveRule()->fresh(['nodes', 'edges']);

        $this->dispatch('automation-workflow-imported', [
            'graph' => $this->serializeGraph($this->record),
            'draft_revision' => (int) $this->record->draft_revision,
        ]);
    }

    public function loadPalette(): void
    {
        abort_unless(SeoAccessControl::canViewAutomation(), 403);

        $this->dispatch('automation-workflow-palette-loaded', [
            'registry' => $this->buildRegistryPayload(),
        ]);
    }

    /**
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    private function serializeGraph(AutomationRule $rule): array
    {
        $layout = is_array($rule->settings['layout'] ?? null) ? $rule->settings['layout'] : [];

        return [
            'nodes' => $rule->nodes->map(static function ($node, int $index) use ($layout): array {
                $ui = $node->ui_position;
                if (! is_array($ui) || $ui === []) {
                    $ui = [
                        'x' => 120 + ($index % 4) * 260,
                        'y' => 80 + intdiv($index, 4) * 140,
                    ];
                }

                return [
                    'node_key' => $node->node_key,
                    'node_type' => $node->node_type,
                    'name' => $node->name,
                    'action_code' => $node->action_code,
                    'position' => $node->position,
                    'config' => $node->config,
                    'input_mapping' => $node->input_mapping,
                    'settings' => $node->settings,
                    'is_enabled' => (bool) $node->is_enabled,
                    'ui_position' => $ui,
                ];
            })->values()->all(),
            'edges' => $rule->edges->map(static fn ($edge): array => [
                'from_node_key' => $edge->from_node_key,
                'to_node_key' => $edge->to_node_key,
                'branch' => $edge->branch,
                'priority' => $edge->priority,
                'condition' => $edge->condition,
            ])->values()->all(),
        ];
    }

    /**
     * @return array{actions: list<array<string, mixed>>, events: list<array<string, mixed>>}
     */
    private function buildRegistryPayload(): array
    {
        $actions = app(AutomationActionRegistry::class)->all();
        $events = app(BusinessEventRegistry::class)->all();

        return [
            'actions' => array_values(array_map(static fn ($def): array => [
                'code' => $def->actionCode,
                'description' => $def->description,
                'module' => $def->module,
                'input_rules' => $def->inputRules,
                'settings_rules' => $def->settingsRules,
            ], $actions)),
            'events' => array_values(array_map(static fn ($def): array => [
                'name' => $def->name,
                'description' => $def->description,
                'module' => $def->module,
                'subject' => $def->subject,
                'payload_schema' => $def->payloadSchema,
            ], $events)),
        ];
    }

    private function resolveRule(): AutomationRule
    {
        if ($this->record instanceof AutomationRule) {
            return $this->record;
        }

        abort(404);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function saveDraftInternal(array $payload): AutomationRule
    {
        $rule = $this->resolveRule();
        $nodes = is_array($payload['nodes'] ?? null) ? $payload['nodes'] : [];
        $edges = is_array($payload['edges'] ?? null) ? $payload['edges'] : [];
        $layout = is_array($payload['layout'] ?? null) ? $payload['layout'] : null;
        $expectedRevision = isset($payload['draft_revision']) ? (int) $payload['draft_revision'] : null;

        return app(AutomationVersionService::class)->saveDraft(
            $rule,
            $nodes,
            $edges,
            $layout,
            $expectedRevision,
            auth()->id() ? (int) auth()->id() : null,
        );
    }
}
