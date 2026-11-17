<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Filament\Resources\DomainResource\Pages;

use App\Models\Site;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Omnichannel\Addons\AiPrompt\Filament\Resources\PromptResource;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultSiteCtaTemplatesPromptInstaller;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\SettingsPromptBindingResolver;
use Omnichannel\Addons\SearchFoundation\Filament\Resources\DomainResource;
use Omnichannel\Addons\Seo\Services\CtaManager\SiteCtaTemplateGenerateService;
use Omnichannel\Addons\Seo\Services\CtaManager\SiteCtaTemplatePoolService;
use Omnichannel\Addons\Seo\Services\CtaManager\SiteCtaTemplatePreviewResolver;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Throwable;

/**
 * CTA Manager V2.1 — dynamic append-only Site CTA pool.
 * Route: /seo/domains/{record}/cta-settings
 */
class ManageDomainCtaTemplates extends Page implements HasForms
{
    use InteractsWithForms;
    use InteractsWithRecord;

    protected static string $resource = DomainResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.domain-resource.pages.manage-domain-cta-templates';

    /** @var array<string, mixed> */
    public array $data = [];

    /** @var list<array{slot: int, intent: string, content: string}> */
    public array $proposals = [];

    /** @var list<string> */
    public array $warnings = [];

    public string $statusMessage = '';

    public bool $generating = false;

    public bool $proposalOpen = false;

    /** @var list<int> */
    public array $slotOrder = [];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(DomainResource::canEdit($this->getRecord()), 403);

        $this->fillFromPool();
    }

    public function getTitle(): string|Htmlable
    {
        return __('seo-content-ai::filament.domain.cta_manager_title');
    }

    public function getHeading(): string|Htmlable
    {
        $site = $this->getRecord();

        return __('seo-content-ai::filament.domain.cta_manager_heading', [
            'domain' => (string) $site->domain,
        ]);
    }

    public function form(Form $form): Form
    {
        $slots = [];
        foreach ($this->slotOrder as $slot) {
            $slot = (int) $slot;
            $slots[] = Forms\Components\Section::make('CTA_'.$slot)
                ->headerActions([
                    Forms\Components\Actions\Action::make('delete_slot_'.$slot)
                        ->label(__('seo-content-ai::filament.domain.cta_manager_delete'))
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading(__('seo-content-ai::filament.domain.cta_manager_delete_confirm_title', [
                            'slot' => 'CTA_'.$slot,
                        ]))
                        ->modalDescription(__('seo-content-ai::filament.domain.cta_manager_delete_confirm_body'))
                        ->action(fn () => $this->deleteSlot($slot)),
                ])
                ->schema([
                    Forms\Components\Hidden::make("templates.{$slot}.slot")->default($slot),
                    Forms\Components\TextInput::make("templates.{$slot}.intent")
                        ->label(__('seo-content-ai::filament.domain.cta_manager_intent'))
                        ->maxLength(80),
                    Forms\Components\Textarea::make("templates.{$slot}.content")
                        ->label(__('seo-content-ai::filament.domain.cta_manager_content'))
                        ->rows(3)
                        ->maxLength(2000),
                    Forms\Components\Placeholder::make("templates.{$slot}.preview")
                        ->label(__('seo-content-ai::filament.domain.cta_manager_preview'))
                        ->content(function () use ($slot): Htmlable|string {
                            $content = (string) data_get($this->data, "templates.{$slot}.content", '');
                            if (trim($content) === '') {
                                return '—';
                            }
                            $resolved = app(SiteCtaTemplatePreviewResolver::class)
                                ->preview($this->getRecord(), $content);

                            return new HtmlString(
                                '<div class="prose prose-sm dark:prose-invert max-w-none">'
                                .$resolved['html']
                                .'</div>'
                            );
                        }),
                    Forms\Components\Placeholder::make("templates.{$slot}.status")
                        ->label(__('seo-content-ai::filament.domain.cta_manager_status'))
                        ->content(fn (): string => (string) data_get($this->data, "templates.{$slot}.status_label", '')),
                ])
                ->columns(1)
                ->collapsible();
        }

        if ($slots === []) {
            $slots[] = Forms\Components\Placeholder::make('empty_pool')
                ->label('')
                ->content(__('seo-content-ai::filament.domain.cta_manager_empty_pool'));
        }

        return $form
            ->schema($slots)
            ->statePath('data');
    }

    public function saveTemplates(): void
    {
        $site = $this->getRecord();
        abort_unless(DomainResource::canEdit($site), 403);

        $state = $this->form->getState();
        $rows = is_array($state['templates'] ?? null) ? $state['templates'] : [];
        $templates = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $templates[] = [
                'slot' => (int) ($row['slot'] ?? 0),
                'intent' => trim((string) ($row['intent'] ?? '')),
                'content' => trim((string) ($row['content'] ?? '')),
                'source' => 'manual',
            ];
        }

        app(SiteCtaTemplatePoolService::class)->savePool($site, $templates);
        $this->fillFromPool();
        $this->statusMessage = __('seo-content-ai::filament.domain.cta_manager_saved');

        Notification::make()
            ->title(__('seo-content-ai::filament.domain.cta_manager_saved'))
            ->success()
            ->send();
    }

    public function deleteSlot(int $slot): void
    {
        $site = $this->getRecord();
        abort_unless(DomainResource::canEdit($site), 403);

        // Persist other unsaved edits first so delete does not silently discard them.
        try {
            $this->persistFormEditsWithoutNotification();
        } catch (Throwable) {
            // Continue with delete of the selected slot even if sibling save fails.
        }

        try {
            app(SiteCtaTemplatePoolService::class)->deleteSlot($site, $slot);
        } catch (Throwable $exception) {
            Notification::make()
                ->title(__('seo-content-ai::filament.domain.cta_manager_delete_failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->fillFromPool();
        $this->statusMessage = __('seo-content-ai::filament.domain.cta_manager_deleted', [
            'slot' => 'CTA_'.$slot,
        ]);
        Notification::make()
            ->title(__('seo-content-ai::filament.domain.cta_manager_deleted', [
                'slot' => 'CTA_'.$slot,
            ]))
            ->success()
            ->send();
    }

    public function generateWithAi(): void
    {
        $site = $this->getRecord();
        abort_unless(DomainResource::canEdit($site), 403);

        // Persist edits before append so generate does not race unsaved form state.
        try {
            $this->persistFormEditsWithoutNotification();
        } catch (Throwable) {
        }

        $this->generating = true;
        $this->proposalOpen = false;
        $this->proposals = [];
        $this->statusMessage = __('seo-content-ai::filament.domain.cta_manager_generating');

        try {
            $result = app(SiteCtaTemplateGenerateService::class)->propose($site);
        } catch (Throwable $exception) {
            $this->generating = false;
            $this->statusMessage = __('seo-content-ai::filament.domain.cta_manager_generate_failed');
            Notification::make()
                ->title(__('seo-content-ai::filament.domain.cta_manager_generate_failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->generating = false;
        $this->warnings = $result['warnings'];

        if (! ($result['ok'] ?? false)) {
            $this->statusMessage = __('seo-content-ai::filament.domain.cta_manager_generate_failed');
            Notification::make()
                ->title(__('seo-content-ai::filament.domain.cta_manager_generate_failed'))
                ->body(implode(', ', $result['errors'] ?? []))
                ->danger()
                ->send();

            return;
        }

        $this->proposals = $result['proposals'];
        $this->proposalOpen = true;
        $this->statusMessage = __('seo-content-ai::filament.domain.cta_manager_proposal_ready_append');
    }

    public function confirmProposals(): void
    {
        $site = $this->getRecord();
        abort_unless(DomainResource::canEdit($site), 403);

        if ($this->proposals === []) {
            return;
        }

        app(SiteCtaTemplatePoolService::class)->appendBatch($site, $this->proposals);

        $this->proposals = [];
        $this->proposalOpen = false;
        $this->fillFromPool();
        $this->statusMessage = __('seo-content-ai::filament.domain.cta_manager_appended');

        Notification::make()
            ->title(__('seo-content-ai::filament.domain.cta_manager_appended'))
            ->success()
            ->send();
    }

    public function discardProposals(): void
    {
        $this->proposals = [];
        $this->proposalOpen = false;
        $this->statusMessage = __('seo-content-ai::filament.domain.cta_manager_discarded');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label(__('seo-content-ai::filament.domain.back_to_domains'))
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(DomainResource::getUrl('index')),
            Action::make('configure_prompt')
                ->label(__('seo-content-ai::filament.domain.cta_manager_configure_prompt'))
                ->icon('heroicon-o-cog-6-tooth')
                ->color('gray')
                ->url(fn (): ?string => $this->promptEditUrl()),
            Action::make('generate')
                ->label(__('seo-content-ai::filament.domain.cta_manager_generate_append'))
                ->icon('heroicon-o-sparkles')
                ->color('primary')
                ->action('generateWithAi')
                ->disabled(fn (): bool => $this->generating),
            Action::make('save')
                ->label(__('filament-panels::resources/pages/edit-record.form.actions.save.label'))
                ->icon('heroicon-o-check')
                ->color('success')
                ->action('saveTemplates')
                ->visible(fn (): bool => $this->slotOrder !== []),
        ];
    }

    public static function canAccess(array $parameters = []): bool
    {
        return SeoAccessControl::canAccessManagerFeatures();
    }

    private function persistFormEditsWithoutNotification(): void
    {
        if ($this->slotOrder === []) {
            return;
        }
        $state = $this->form->getState();
        $rows = is_array($state['templates'] ?? null) ? $state['templates'] : [];
        $templates = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $templates[] = [
                'slot' => (int) ($row['slot'] ?? 0),
                'intent' => trim((string) ($row['intent'] ?? '')),
                'content' => trim((string) ($row['content'] ?? '')),
                'source' => 'manual',
            ];
        }
        if ($templates === []) {
            return;
        }
        app(SiteCtaTemplatePoolService::class)->savePool($this->getRecord(), $templates);
    }

    private function fillFromPool(): void
    {
        /** @var Site $site */
        $site = $this->getRecord();
        $pool = app(SiteCtaTemplatePoolService::class)->getPool($site);
        $templates = [];
        $order = [];
        foreach ($pool['templates'] as $row) {
            $slot = (int) $row['slot'];
            $order[] = $slot;
            $templates[$slot] = [
                'slot' => $slot,
                'intent' => (string) $row['intent'],
                'content' => (string) $row['content'],
                'status_label' => match ((string) $row['status']) {
                    SiteCtaTemplatePoolService::STATUS_EMPTY => __('seo-content-ai::filament.domain.cta_manager_status_empty'),
                    SiteCtaTemplatePoolService::STATUS_AI => __('seo-content-ai::filament.domain.cta_manager_status_ai'),
                    default => __('seo-content-ai::filament.domain.cta_manager_status_manual'),
                },
            ];
        }

        $this->slotOrder = $order;
        $this->data = [
            'templates' => $templates,
            'last_generated_at' => $pool['last_generated_at'],
            'next_slot_seq' => $pool['next_slot_seq'],
            'site_domain' => (string) $site->domain,
            'site_id' => (int) $site->id,
            'pool_count' => count($order),
        ];
        $this->form->fill($this->data);
    }

    private function promptEditUrl(): ?string
    {
        try {
            app(DefaultSiteCtaTemplatesPromptInstaller::class)->install();
            $id = app(SettingsPromptBindingResolver::class)
                ->resolveId(DefaultSiteCtaTemplatesPromptInstaller::HOOK_KEY);
            if ($id === null || $id <= 0) {
                return PromptResource::getUrl('index', panel: 'seo-main');
            }

            return PromptResource::getUrl('edit', ['record' => $id], panel: 'seo-main');
        } catch (Throwable) {
            return PromptResource::getUrl('index', panel: 'seo-main');
        }
    }
}
