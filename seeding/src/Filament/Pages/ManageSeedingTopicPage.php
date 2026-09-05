<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Filament\Pages;

use Illuminate\Contracts\Support\Htmlable;
use Omnichannel\Addons\Seo\Filament\Pages\SeoPanelPage;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;

/**
 * Compatibility stub — main flow is SeedingTopicsPage React workspace.
 */
final class ManageSeedingTopicPage extends SeoPanelPage
{
    protected static ?string $slug = 'seeding-topic-manage';

    protected static string $view = 'seeding::filament.pages.manage-seeding-topic-redirect';

    protected static bool $shouldRegisterNavigation = false;

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
