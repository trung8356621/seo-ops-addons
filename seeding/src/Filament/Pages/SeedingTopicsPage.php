<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Filament\Pages;

use App\Models\Site;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Omnichannel\Addons\Seo\Filament\Pages\SeoPanelPage;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\Seeding\Models\SeedingTopic;
use Omnichannel\Addons\Seeding\Services\SeedingTopicService;

/**
 * Seeding Topic V2 list — parallel to any legacy seeding surfaces; does not replace them.
 */
final class SeedingTopicsPage extends SeoPanelPage
{
    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationGroup = null;

    protected static ?int $navigationSort = \Omnichannel\Addons\Seo\Support\SeoUserNavigation::SORT_SEO + 3;

    protected static ?string $slug = 'seeding-topics';

    protected static string $view = 'seeding::filament.pages.seeding-topics-page';

    protected static bool $shouldRegisterNavigation = false;

    #[Url(as: 'site_id')]
    public ?int $siteId = null;

    public static function canAccess(array $parameters = []): bool
    {
        return SeoAccessControl::canAccessPlannerFeatures();
    }

    public static function shouldRegisterNavigation(array $parameters = []): bool
    {
        return false;
    }

    public static function getNavigationLabel(): string
    {
        return __('seeding::filament.topics.nav');
    }

    public function getTitle(): string|Htmlable
    {
        $domain = $this->currentSiteDomain();

        return $domain !== null && $domain !== ''
            ? __('seeding::filament.topics.title_with_domain', ['domain' => $domain])
            : __('seeding::filament.topics.title');
    }

    public function mount(): void
    {
        $globalSiteId = SeoAccessControl::globalSiteId();
        if ($globalSiteId !== null) {
            $this->siteId = $globalSiteId;
        } elseif ($this->siteId === null || $this->siteId <= 0) {
            $first = SeoAccessControl::accessibleSitesQuery()->orderBy('domain')->first();
            $this->siteId = $first instanceof Site ? (int) $first->id : null;
        }

        $this->assertSiteAccess();
    }

    #[On('domain-context-changed')]
    #[On('seoGlobalSiteChanged')]
    public function onDomainContextChanged(mixed $domain = null, mixed $siteId = null): void
    {
        $resolved = is_numeric($siteId) ? (int) $siteId : SeoAccessControl::globalSiteId();
        if ($resolved !== null && $resolved > 0) {
            $this->siteId = $resolved;
        }
    }

    public function updatedSiteId(): void
    {
        $global = SeoAccessControl::globalSiteId();
        if ($global !== null) {
            $this->siteId = $global;
        }
        $this->assertSiteAccess();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function topics(): array
    {
        $siteId = (int) ($this->siteId ?? 0);
        if ($siteId <= 0) {
            return [];
        }

        return SeedingTopic::query()
            ->forSite($siteId)
            ->withCount('linkResources')
            ->orderByDesc('id')
            ->get()
            ->map(static function (SeedingTopic $topic): array {
                return [
                    'id' => (int) $topic->id,
                    'status' => $topic->status->value,
                    'status_label' => $topic->status->label(),
                    'preview' => $topic->preview(80),
                    'full_text' => (string) $topic->full_text,
                    'links_count' => (int) $topic->link_resources_count,
                    'social_url' => $topic->social_url,
                    'social_platform' => $topic->social_platform?->value,
                    'social_platform_label' => $topic->social_platform?->label(),
                    'is_draft' => $topic->isDraft(),
                    'is_active' => $topic->isActive(),
                    'manage_url' => ManageSeedingTopicPage::getUrl([
                        'topic_id' => (int) $topic->id,
                        'site_id' => (int) $topic->site_id,
                    ]),
                ];
            })
            ->all();
    }

    public function deleteTopic(int $topicId): void
    {
        abort_unless(SeoAccessControl::canMutateInSeoPanel(), 403);
        $topic = $this->findScopedTopic($topicId);
        if ($topic === null) {
            return;
        }

        try {
            app(SeedingTopicService::class)->deleteDraft($topic);
            Notification::make()
                ->title(__('seeding::filament.topics.deleted'))
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title(__('seeding::filament.topics.delete_failed'))
                ->body(mb_substr($e->getMessage(), 0, 200))
                ->danger()
                ->send();
        }
    }

    public function createUrl(): string
    {
        return ManageSeedingTopicPage::getUrl([
            'site_id' => (int) ($this->siteId ?? 0),
        ]);
    }

    public function currentSiteDomain(): ?string
    {
        $siteId = (int) ($this->siteId ?? 0);
        if ($siteId <= 0) {
            return null;
        }

        return Site::query()->find($siteId)?->domain;
    }

    public function hasLockedGlobalSite(): bool
    {
        return SeoAccessControl::globalSiteId() !== null;
    }

    /**
     * @return list<Site>
     */
    public function sites(): array
    {
        return SeoAccessControl::accessibleSitesQuery()
            ->orderBy('domain')
            ->get()
            ->all();
    }

    private function findScopedTopic(int $topicId): ?SeedingTopic
    {
        $siteId = (int) ($this->siteId ?? 0);
        if ($siteId <= 0 || $topicId <= 0) {
            return null;
        }

        return SeedingTopic::query()
            ->forSite($siteId)
            ->whereKey($topicId)
            ->first();
    }

    private function assertSiteAccess(): void
    {
        $siteId = (int) ($this->siteId ?? 0);
        if ($siteId <= 0) {
            return;
        }

        abort_unless(SeoAccessControl::canAccessSite($siteId), 403);
    }
}
