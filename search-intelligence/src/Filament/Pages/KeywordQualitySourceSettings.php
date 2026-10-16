<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Pages;

use App\Core\Addon\AddonEnablement;
use App\Models\Site;
use App\Models\User;
use Filament\Forms;
use Illuminate\Http\Request;
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

        $requested = $this->explicitRequestSiteId();
        $siteId = $requested > 0 ? $requested : (int) (SeoAccessControl::globalSiteId() ?? 0);
        abort_unless($siteId > 0 && SeoAccessControl::canAccessSite($siteId), 403);

        $site = Site::query()->find($siteId);
        abort_unless($site instanceof Site, 404);

        $this->siteId = $siteId;
        $this->siteLabel = trim((string) ($site->domain ?: $site->name ?: ('#'.$siteId)));

        if ($requested !== $siteId) {
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

        $user = auth()->user();
        if ($user instanceof User
            && in_array((string) $user->role, [User::ROLE_OWNER, User::ROLE_ADMIN], true)
        ) {
            return true;
        }

        return SeoAccessControl::canMutateInSeoPanel()
            && SeoAccessControl::canAccessPlannerFeatures();
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
        $siteId = $this->explicitRequestSiteId();
        abort_unless($siteId > 0 && SeoAccessControl::canAccessSite($siteId), 403);
        abort_unless($siteId === $this->siteId, 403);

        return $siteId;
    }

    /**
     * GET carries site_id on the query string. Livewire saves POST /livewire/update
     * and only the Referer still has the page query. Never fall back to public state.
     */
    private function explicitRequestSiteId(): int
    {
        $request = request();
        foreach ([$request->query('site_id'), $this->refererSiteId($request)] as $value) {
            if (is_numeric($value) && (int) $value > 0) {
                return (int) $value;
            }
        }

        return 0;
    }

    private function refererSiteId(Request $request): ?string
    {
        $referer = (string) $request->headers->get('referer', '');
        if ($referer === '') {
            return null;
        }

        $queryString = parse_url($referer, PHP_URL_QUERY);
        if (! is_string($queryString) || $queryString === '') {
            return null;
        }

        $query = [];
        parse_str($queryString, $query);
        $siteId = $query['site_id'] ?? null;

        return is_scalar($siteId) ? (string) $siteId : null;
    }
}
