<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Omnichannel\Addons\Seeding\Support\SeedingAccess;

/**
 * Compatibility stub — redirects to canonical /seeding workspace.
 */
final class ManageSeedingTopicPage extends Page
{
    protected static ?string $slug = 'topic-manage';

    protected static string $view = 'seeding::filament.pages.manage-seeding-topic-redirect';

    protected static bool $shouldRegisterNavigation = false;

    /**
     * @param  array<string, mixed>  $parameters
     */
    public static function getUrl(
        array $parameters = [],
        bool $isAbsolute = true,
        ?string $panel = null,
        ?\Illuminate\Database\Eloquent\Model $tenant = null,
    ): string {
        return parent::getUrl($parameters, $isAbsolute, $panel ?? 'seeding', $tenant);
    }

    public static function canAccess(array $parameters = []): bool
    {
        return app(SeedingAccess::class)->canAccess();
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
        return __('seeding::filament.topics.title');
    }

    public function mount(): void
    {
        $params = [];
        $siteId = request()->query('site_id');
        if (is_numeric($siteId) && (int) $siteId > 0) {
            $params['site_id'] = (int) $siteId;
        }
        $topicId = request()->query('topic_id');
        if (is_numeric($topicId) && (int) $topicId > 0) {
            $params['topic_id'] = (int) $topicId;
        }

        $this->redirect(SeedingTopicsPage::getUrl($params), navigate: false);
    }
}
