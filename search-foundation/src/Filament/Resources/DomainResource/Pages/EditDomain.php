<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Filament\Resources\DomainResource\Pages;

use Omnichannel\Addons\SearchFoundation\Filament\Resources\DomainResource;
use Omnichannel\Addons\AiPrompt\Filament\Resources\DomainResource\Pages\Concerns\PersistsDomainPromptContext;
use Omnichannel\Addons\SearchFoundation\Filament\Resources\DomainResource\Pages\Concerns\PersistsSeoDomainMetas;
use Omnichannel\Addons\Seo\Filament\Resources\Pages\SeoEditRecord;
use Omnichannel\Addons\SearchFoundation\Filament\Resources\DomainResource\Pages\Concerns\SyncsDomainPromptContextFromWordPress;
use Omnichannel\Addons\SearchFoundation\Services\SiteMcp\SiteMcpAppliedFieldPersister;
use Omnichannel\Addons\SearchFoundation\Services\SiteMcp\SiteMcpDraft;
use Omnichannel\Addons\SearchFoundation\Services\SiteMcp\SiteMcpFormApplicator;
use Omnichannel\Addons\SearchFoundation\Services\SiteMcp\SiteMcpGenerator;
use Omnichannel\Addons\SearchFoundation\Services\SiteMcp\SiteMcpPreview;
use Omnichannel\Addons\WordPress\Services\SitePrimaryLanguageService;
use App\Models\Site;
use App\Support\RuntimeLogger;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Throwable;

class EditDomain extends SeoEditRecord
{
    use PersistsDomainPromptContext;
    use PersistsSeoDomainMetas;
    use SyncsDomainPromptContextFromWordPress;

    protected static string $resource = DomainResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.domain-resource.pages.edit-domain';

    public bool $siteMcpDraftGenerating = false;

    public bool $siteMcpApplying = false;

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
     * Regenerate Site MCP, then open the panel. Form state is left untouched.
     * A failed run keeps the previous preview.
     */
    public function generateSiteMcpDraftAction(): void
    {
        $this->assertCanEditDomain();

        /** @var Site $site */
        $site = $this->record;
        $lock = Cache::lock('site-mcp-generate:'.(int) $site->getKey(), 180);
        if (! $lock->get()) {
            Notification::make()
                ->title('Đang chuẩn bị thông tin MCP.')
                ->warning()
                ->send();

            return;
        }

        $this->siteMcpDraftGenerating = true;

        try {
            $draft = app(SiteMcpGenerator::class)->generateDraft($site);
            $this->siteMcpDraftPreview = app(SiteMcpPreview::class)->present($draft);
            $this->siteMcpDraftPanelOpen = true;

            Notification::make()
                ->title('Đã cập nhật thông tin MCP.')
                ->success()
                ->send();
        } catch (Throwable $e) {
            RuntimeLogger::report($e, [
                'endpoint' => 'seo.site_mcp.generate_draft',
                'site_id' => (int) $site->getKey(),
            ]);

            Notification::make()
                ->title('Không tạo được thông tin MCP')
                ->body($e->getMessage())
                ->danger()
                ->send();
        } finally {
            $this->siteMcpDraftGenerating = false;
            $lock->release();
        }
    }

    /**
     * Apply mapped MCP values, then persist only those fields. Does not sync WordPress.
     */
    public function applySiteMcpToForm(): void
    {
        $this->assertCanEditDomain();

        if ($this->siteMcpDraftGenerating || $this->siteMcpApplying) {
            return;
        }

        /** @var Site $site */
        $site = $this->record;
        $lock = Cache::lock('site-mcp-apply:'.(int) $site->getKey(), 30);
        if (! $lock->get()) {
            Notification::make()
                ->title('Đang áp dụng và lưu MCP.')
                ->warning()
                ->send();

            return;
        }

        $this->siteMcpApplying = true;

        try {
            $plan = app(SiteMcpFormApplicator::class)->plan(
                $this->getSiteMcpDraftPreview(),
                $this->form->getRawState(),
            );
            $applied = $plan['applied'];
            if ($applied === []) {
                Notification::make()
                    ->title('Không có trường MCP nào để áp dụng vào biểu mẫu.')
                    ->warning()
                    ->send();

                return;
            }

            $saved = app(SiteMcpAppliedFieldPersister::class)->persist($site, $applied, $plan['state']);
            if ($saved === 0) {
                Notification::make()
                    ->title('Không có trường MCP nào để áp dụng vào biểu mẫu.')
                    ->warning()
                    ->send();

                return;
            }

            $this->form->fill($plan['state']);

            Notification::make()
                ->title('Đã áp dụng và lưu '.$saved.' trường từ MCP.')
                ->success()
                ->send();
        } catch (InvalidArgumentException $e) {
            Notification::make()
                ->title('Không áp dụng được thông tin MCP')
                ->body($e->getMessage())
                ->danger()
                ->send();
        } catch (Throwable $e) {
            RuntimeLogger::report($e, [
                'endpoint' => 'seo.site_mcp.apply_form',
                'site_id' => (int) $site->getKey(),
            ]);

            Notification::make()
                ->title('Không áp dụng được thông tin MCP')
                ->body($e->getMessage())
                ->danger()
                ->send();
        } finally {
            $this->siteMcpApplying = false;
            $lock->release();
        }
    }

    private function assertCanEditDomain(): void
    {
        $site = $this->getRecord();
        abort_unless($site instanceof Site, 404);
        abort_unless(DomainResource::canEdit($site), 403);
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('apply_site_mcp')
                ->label('Áp dụng & Lưu MCP')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->disabled(fn (): bool => $this->siteMcpDraftGenerating || $this->siteMcpApplying)
                ->extraAttributes([
                    'wire:loading.attr' => 'disabled',
                    'wire:target' => 'mountAction,callMountedAction',
                ])
                ->action('applySiteMcpToForm'),
            Action::make('view_site_mcp')
                ->label('Xem MCP')
                ->icon('heroicon-o-eye')
                ->color('primary')
                ->disabled(fn (): bool => $this->siteMcpDraftGenerating)
                ->extraAttributes([
                    'wire:loading.attr' => 'disabled',
                    'wire:target' => 'mountAction',
                ])
                ->action('generateSiteMcpDraftAction'),
            ...parent::getHeaderActions(),
        ];
    }
}
