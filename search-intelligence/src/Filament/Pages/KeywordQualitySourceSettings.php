<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Pages;

use App\Core\Addon\AddonEnablement;
use App\Models\Site;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordQuality\KeywordQualityService;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordQuality\KeywordQualityCode;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordQuality\KeywordQualitySchema;
use Omnichannel\Addons\Seo\Support\DomainContextResolver;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;

/**
 * Site-scoped Topic exclusion policy. Reuses KeywordQualityService::setExcluded.
 */
class KeywordQualitySourceSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $slug = 'settings/keyword-sources';

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'search-intelligence::filament.pages.keyword-quality-source-settings';

    public int $siteId = 0;

    public string $siteLabel = '';

    /** @var array<string, bool> */
    public array $policyData = [];

    public function mount(KeywordQualityService $service): void
    {
        abort_unless(static::canAccess(), 403);

        $requested = request()->query('site_id');
        $siteId = is_numeric($requested) ? (int) $requested : (int) (SeoAccessControl::globalSiteId() ?? 0);
        abort_unless($siteId > 0 && SeoAccessControl::canAccessSite($siteId), 403);

        $site = Site::query()->find($siteId);
        abort_unless($site instanceof Site, 404);

        $this->siteId = $siteId;
        $this->siteLabel = trim((string) ($site->domain ?: $site->name ?: ('#'.$siteId)));

        if (! is_numeric($requested) || (int) $requested !== $siteId) {
            $this->redirect(app(DomainContextResolver::class)->appendSiteToUrl(static::getUrl(panel: 'admin'), $siteId));

            return;
        }

        $data = [];
        foreach (KeywordQualityCode::all() as $code) {
            $data['exclude_'.$code] = KeywordQualitySchema::tablesReady() && $service->isExcluded($siteId, $code);
        }
        $this->policyData = $data;
        $this->form->fill($data);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema(collect(KeywordQualityCode::all())->map(
                static fn (string $code): Forms\Components\Toggle => Forms\Components\Toggle::make('exclude_'.$code)
                    ->label(KeywordQualityCode::label($code))
                    ->helperText(KeywordQualityCode::description($code))
                    ->default(false),
            )->all())
            ->statePath('policyData');
    }

    public function savePolicy(KeywordQualityService $service): void
    {
        $siteId = $this->authorizedSiteId();
        if (! KeywordQualitySchema::tablesReady()) {
            Notification::make()->title(__('seo-content-ai::filament.keyword.quality_edit_failed'))->danger()->send();

            return;
        }

        $data = $this->form->getState();
        foreach (KeywordQualityCode::all() as $code) {
            $service->setExcluded($siteId, $code, (bool) ($data['exclude_'.$code] ?? false));
        }

        Notification::make()->title(__('seo-content-ai::filament.keyword.quality_settings_saved'))->success()->send();
    }

    public function getTitle(): string
    {
        return __('seo-content-ai::filament.keyword.quality_settings');
    }

    public static function canAccess(): bool
    {
        if (! AddonEnablement::seoStackEnabled()) {
            return false;
        }

        return SeoAccessControl::canMutateInSeoPanel() && SeoAccessControl::canAccessPlannerFeatures();
    }

    public static function urlForSite(int $siteId): string
    {
        try {
            $url = static::getUrl(panel: 'admin');
        } catch (\Throwable) {
            $url = url('/admin/settings/keyword-sources');
        }

        return app(DomainContextResolver::class)->appendSiteToUrl($url, $siteId);
    }

    private function authorizedSiteId(): int
    {
        $fromUrl = request()->query('site_id');
        $siteId = is_numeric($fromUrl) ? (int) $fromUrl : 0;
        abort_unless(
            $siteId > 0 && $siteId === $this->siteId && SeoAccessControl::canAccessSite($siteId),
            403,
        );

        return $siteId;
    }
}
