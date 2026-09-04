<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Filament\Pages;

use App\Models\Site;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Omnichannel\Addons\Seo\Filament\Pages\SeoPanelPage;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\Seeding\Enums\SeedingTopicStatus;
use Omnichannel\Addons\Seeding\Models\SeedingTopic;
use Omnichannel\Addons\Seeding\Services\SeedingTopicService;
use InvalidArgumentException;
use Throwable;

/**
 * Create / update Seeding Topic V2 + social URL section.
 */
final class ManageSeedingTopicPage extends SeoPanelPage
{
    protected static ?string $navigationIcon = null;

    protected static ?string $slug = 'seeding-topic-manage';

    protected static string $view = 'seeding::filament.pages.manage-seeding-topic-page';

    protected static bool $shouldRegisterNavigation = false;

    #[Url(as: 'site_id')]
    public ?int $siteId = null;

    #[Url(as: 'topic_id')]
    public ?int $topicId = null;

    public string $fullText = '';

    public ?string $sourceHtml = null;

    public string $socialUrl = '';

    public bool $saving = false;

    public bool $savingSocial = false;

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
        return __('seeding::filament.topics.manage_nav');
    }

    public function getTitle(): string|Htmlable
    {
        return $this->topicId
            ? __('seeding::filament.topics.edit_title')
            : __('seeding::filament.topics.create_title');
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
        $this->hydrateFromTopic();
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

    public function saveTopic(): void
    {
        abort_unless(SeoAccessControl::canMutateInSeoPanel(), 403);
        $this->assertSiteAccess();

        $siteId = (int) ($this->siteId ?? 0);
        if ($siteId <= 0) {
            Notification::make()
                ->title(__('seeding::filament.topics.need_site'))
                ->warning()
                ->send();

            return;
        }

        if (trim($this->fullText) === '') {
            Notification::make()
                ->title(__('seeding::filament.topics.validation_content'))
                ->warning()
                ->send();

            return;
        }

        $this->saving = true;

        try {
            $service = app(SeedingTopicService::class);
            $payload = [
                'full_text' => $this->fullText,
                'source_html' => $this->sourceHtml,
                'social_url' => $this->socialUrl !== '' ? $this->socialUrl : null,
            ];

            if ($this->topicId !== null && $this->topicId > 0) {
                $topic = $this->findScopedTopic((int) $this->topicId);
                if ($topic === null) {
                    throw new InvalidArgumentException('Topic not found');
                }
                $topic = $service->update($topic, $payload);
            } else {
                $topic = $service->create([
                    'site_id' => $siteId,
                    'created_by' => Auth::id() !== null ? (int) Auth::id() : null,
                    ...$payload,
                ]);
                $this->topicId = (int) $topic->id;
            }

            $this->hydrateFromTopic();

            Notification::make()
                ->title(__('seeding::filament.topics.saved'))
                ->success()
                ->send();
        } catch (Throwable $e) {
            Notification::make()
                ->title(__('seeding::filament.topics.save_failed'))
                ->body(mb_substr($e->getMessage(), 0, 200))
                ->danger()
                ->send();
        }

        $this->saving = false;
    }

    public function saveSocialUrl(): void
    {
        abort_unless(SeoAccessControl::canMutateInSeoPanel(), 403);

        if ($this->topicId === null || $this->topicId <= 0) {
            Notification::make()
                ->title(__('seeding::filament.topics.save_content_first'))
                ->warning()
                ->send();

            return;
        }

        $topic = $this->findScopedTopic((int) $this->topicId);
        if ($topic === null) {
            return;
        }

        $this->savingSocial = true;

        try {
            $updated = app(SeedingTopicService::class)->updateSocialUrl(
                $topic,
                $this->socialUrl !== '' ? $this->socialUrl : null,
            );
            $this->hydrateFromModel($updated);

            Notification::make()
                ->title(__('seeding::filament.topics.social_updated'))
                ->success()
                ->send();
        } catch (Throwable $e) {
            Notification::make()
                ->title(__('seeding::filament.topics.save_failed'))
                ->body(mb_substr($e->getMessage(), 0, 200))
                ->danger()
                ->send();
        }

        $this->savingSocial = false;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function topicView(): ?array
    {
        $topic = $this->resolveTopic();
        if ($topic === null) {
            return null;
        }

        $topic->loadCount('linkResources');
        $topic->load('linkResources');

        return [
            'id' => (int) $topic->id,
            'status' => $topic->status->value,
            'status_label' => $topic->status->label(),
            'is_draft' => $topic->isDraft(),
            'is_active' => $topic->isActive(),
            'full_text' => (string) $topic->full_text,
            'social_url' => $topic->social_url,
            'social_platform_label' => $topic->social_platform?->label(),
            'links_count' => (int) $topic->link_resources_count,
            'links' => $topic->linkResources->map(static fn ($link): array => [
                'id' => (int) $link->id,
                'url' => (string) $link->original_url,
                'normalized_url' => (string) $link->normalized_url,
                'domain' => (string) $link->domain,
            ])->all(),
            'published_at' => $topic->published_at?->toDateTimeString(),
        ];
    }

    public function showActiveEditWarning(): bool
    {
        $topic = $this->resolveTopic();

        return $topic !== null && $topic->status === SeedingTopicStatus::Active;
    }

    public function listUrl(): string
    {
        return SeedingTopicsPage::getUrl([
            'site_id' => (int) ($this->siteId ?? 0),
        ]);
    }

    public function copyText(): string
    {
        $topic = $this->resolveTopic();
        if ($topic === null) {
            return $this->fullText;
        }

        return app(SeedingTopicService::class)->copyPayload($topic);
    }

    private function hydrateFromTopic(): void
    {
        $topic = $this->resolveTopic();
        if ($topic === null) {
            return;
        }

        $this->hydrateFromModel($topic);
    }

    private function hydrateFromModel(SeedingTopic $topic): void
    {
        $this->topicId = (int) $topic->id;
        $this->siteId = (int) $topic->site_id;
        $this->fullText = (string) $topic->full_text;
        $this->sourceHtml = $topic->source_html;
        $this->socialUrl = (string) ($topic->social_url ?? '');
    }

    private function resolveTopic(): ?SeedingTopic
    {
        if ($this->topicId === null || $this->topicId <= 0) {
            return null;
        }

        return $this->findScopedTopic((int) $this->topicId);
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
