<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Filament\Pages;

use Omnichannel\Addons\Seo\Filament\Pages\SeoPanelPage;
use Omnichannel\Addons\Seo\Support\SeoConnectionContext;

/**
 * Compatibility redirect: /seo/{hash}/agent → /seo/{hash}/chat?tab=agent
 * Preserves deep-link query params (project_ref, etc.).
 */
final class AgentWorkspaceLegacyRedirect extends SeoPanelPage
{
    protected static ?string $slug = 'agent';

    protected static ?string $navigationIcon = 'heroicon-o-cpu-chip';

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'seo-content-ai::filament.pages.agent-workspace-legacy-redirect';

    public static function canAccess(): bool
    {
        return AgentWorkspacePage::canAccess();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $hash = SeoConnectionContext::resolveHashFromRequest()
            ?? (is_string(request()->route('connection_hash')) ? (string) request()->route('connection_hash') : null);

        if (! is_string($hash) || ! SeoConnectionContext::isValidHashFormat($hash)) {
            $this->redirect(url('/seo'));

            return;
        }

        SeoConnectionContext::applyUrlDefaults($hash);

        $url = AgentWorkspacePage::getUrl(
            parameters: ['connection_hash' => $hash],
            panel: 'seo',
        );

        $params = array_merge(
            request()->query(),
            ['tab' => 'agent'],
        );
        unset($params['connection_hash']);

        $target = $url.(str_contains($url, '?') ? '&' : '?').http_build_query($params);

        $this->redirect($target);
    }

    public function getTitle(): string
    {
        return __('seo-content-ai::filament.chat_workspace.title');
    }
}
