<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Filament\Resources\DomainResource\Pages;

use Omnichannel\Addons\SearchFoundation\Filament\Resources\DomainResource;
use Omnichannel\Addons\AiPrompt\Filament\Resources\DomainResource\Pages\Concerns\PersistsDomainPromptContext;
use Omnichannel\Addons\SearchFoundation\Filament\Resources\DomainResource\Pages\Concerns\PersistsSeoDomainMetas;
use Omnichannel\Addons\Seo\Filament\Resources\Pages\SeoEditRecord;
use Omnichannel\Addons\SearchFoundation\Filament\Resources\DomainResource\Pages\Concerns\SyncsDomainPromptContextFromWordPress;
use Omnichannel\Addons\SearchFoundation\Services\SiteMcp\SiteMcpDraft;
use Omnichannel\Addons\SearchFoundation\Services\SiteMcp\SiteMcpGenerator;
use Omnichannel\Addons\SearchFoundation\Services\SiteMcp\SiteMcpPreview;
use Omnichannel\Addons\WordPress\Services\SitePrimaryLanguageService;
use App\Models\Site;
use App\Support\RuntimeLogger;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Throwable;

class EditDomain extends SeoEditRecord
{
    use PersistsDomainPromptContext;
    use PersistsSeoDomainMetas;
    use SyncsDomainPromptContextFromWordPress;

    protected static string $resource = DomainResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.domain-resource.pages.edit-domain';

    public bool $siteMcpDraftGenerating = false;

    public bool $siteMcpDraftPanelOpen = false;

    /** @var array<string, mixed>|null */
    public ?array $siteMcpDraftPreview = null;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Site $record */
        $record = $this->record;

        app(SitePrimaryLanguageService::class)->seedIfEmpty($record);
        $record->unsetRelation('metas');

        $data = $this->fillSeoMetaFormData($record, $data);

        return $this->fillDomainPromptContextFormData($record, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var Site $record */
        $record = $this->record;

        $data = $this->stripPromptContextFromFormData($data);

        return $this->persistSeoMetaFormData($record, $data);
    }

    protected function afterSave(): void
    {
        /** @var Site $site */
        $site = $this->record;

        $this->queuePromptContextFromFormState($this->form->getState());
        $this->persistPendingDomainPromptContext($site);
    }

    /**
     * @return array<string, mixed>
     */
    public function getSiteMcpDraftPreview(): array
    {
        if (is_array($this->siteMcpDraftPreview)) {
            return $this->siteMcpDraftPreview;
        }

        return $this->loadSiteMcpDraftPreview();
    }

    /**
     * @return array<string, mixed>
     */
    public function loadSiteMcpDraftPreview(): array
    {
        /** @var Site $site */
        $site = $this->record;
        $this->siteMcpDraftPreview = app(SiteMcpPreview::class)->present(
            app(SiteMcpDraft::class)->get($site)
        );

        return $this->siteMcpDraftPreview;
    }

    public function openSiteMcpDraftPanel(): void
    {
        $this->loadSiteMcpDraftPreview();
        $this->siteMcpDraftPanelOpen = true;
    }

    public function closeSiteMcpDraftPanel(): void
    {
        $this->siteMcpDraftPanelOpen = false;
    }

    /**
     * Generate or regenerate Site MCP draft — never touches official form fields.
     */
    public function generateSiteMcpDraftAction(): void
    {
        $this->siteMcpDraftGenerating = true;

        try {
            /** @var Site $site */
            $site = $this->record;
            $draft = app(SiteMcpGenerator::class)->generateDraft($site);
            $this->siteMcpDraftPreview = app(SiteMcpPreview::class)->present($draft);
            $this->siteMcpDraftPanelOpen = true;

            Notification::make()
                ->title(__('Site MCP draft đã tạo'))
                ->body(__('Draft only — official data unchanged.'))
                ->success()
                ->send();
        } catch (Throwable $e) {
            RuntimeLogger::report($e, [
                'endpoint' => 'seo.site_mcp.generate_draft',
                'site_id' => (int) $this->record->getKey(),
            ]);

            Notification::make()
                ->title(__('Tạo Site MCP draft thất bại'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        } finally {
            $this->siteMcpDraftGenerating = false;
        }
    }

    public function hasSiteMcpDraft(): bool
    {
        return ($this->getSiteMcpDraftPreview()['has_draft'] ?? false) === true;
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('generate_site_mcp_draft')
                ->label(fn (): string => $this->hasSiteMcpDraft()
                    ? (string) __('Regenerate draft')
                    : (string) __('Generate Site MCP Draft'))
                ->icon('heroicon-o-sparkles')
                ->color('primary')
                ->action('generateSiteMcpDraftAction'),
            Action::make('show_site_mcp_draft')
                ->label(__('Show draft panel'))
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->visible(fn (): bool => $this->hasSiteMcpDraft() && ! $this->siteMcpDraftPanelOpen)
                ->action('openSiteMcpDraftPanel'),
            ...parent::getHeaderActions(),
        ];
    }
}
