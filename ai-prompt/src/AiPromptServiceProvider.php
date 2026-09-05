<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt;

use App\Core\Capability\CapabilityRegistry;
use App\Core\Settings\SettingsSectionRegistry;
use Illuminate\Support\ServiceProvider;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookBindingRunner;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookExplicitBindingExecutor;
use Omnichannel\Addons\AiPrompt\Services\AiCenterModelPresenter;
use Omnichannel\Addons\AiPrompt\Services\AiModelInventory;
use Omnichannel\Addons\AiPrompt\Services\AiModelPriorityService;
use Omnichannel\Addons\AiPrompt\Services\AiRoutingTargetService;
use Omnichannel\Addons\AiPrompt\Services\Contracts\DomainPromptContextFieldPatcher;
use Omnichannel\Addons\AiPrompt\Services\Contracts\WordPressFieldSyncAccessChecker;
use Omnichannel\Addons\AiPrompt\Services\SiteDomainPromptContextService;
use Omnichannel\Addons\AiPrompt\Services\WordPressFieldSyncAccessGate;
use Omnichannel\Addons\AiPrompt\Settings\AiCoreSettingsContributor;
use Omnichannel\Addons\SiteSync\Services\Profile\Contracts\WordPressSiteProfileSource;
use Omnichannel\Addons\SiteSync\Services\Profile\WordPressSiteProfileReader;

/**
 * Peer addon skeleton: registers capabilities into Client Core.
 * Implementation still migrating out of SeoContentAi legacy monolith.
 */
final class AiPromptServiceProvider extends ServiceProvider
{
    public const SLUG = 'ai-prompt';

    public function register(): void
    {
        $this->registerCapabilities();
        $this->app->bind(WordPressSiteProfileSource::class, WordPressSiteProfileReader::class);
        $this->app->bind(DomainPromptContextFieldPatcher::class, SiteDomainPromptContextService::class);
        $this->app->bind(WordPressFieldSyncAccessChecker::class, WordPressFieldSyncAccessGate::class);
        $this->app->bind(PromptHookBindingRunner::class, PromptHookExplicitBindingExecutor::class);
        $this->app->scoped(AiModelPriorityService::class);
        $this->app->scoped(AiRoutingTargetService::class);
        $this->app->scoped(\Omnichannel\Addons\AiPrompt\Services\CanonicalAiRouteResolver::class);
        $this->app->scoped(AiCenterModelPresenter::class);
        $this->app->scoped(AiModelInventory::class);
        $this->app->scoped(\Omnichannel\Addons\AiPrompt\Services\AiConnectionPresenter::class);
        $this->app->scoped(\Omnichannel\Addons\AiPrompt\Services\AiExecutionTargetPresenter::class);
        $this->app->singleton(AiCoreSettingsContributor::class);
    }

    public function boot(): void
    {
        // Ensure AI Center Blade/lang resolve even when seo-content-ai panel provider is skipped.
        $compatRoot = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'seo-content-ai-compat';
        $views = $compatRoot.DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'views';
        $lang = $compatRoot.DIRECTORY_SEPARATOR.'lang';
        if (is_dir($views)) {
            $this->loadViewsFrom($views, 'seo-content-ai');
        }
        if (is_dir($lang)) {
            $this->loadTranslationsFrom($lang, 'seo-content-ai');
        }

        if ($this->app->bound(SettingsSectionRegistry::class)) {
            $this->app->make(SettingsSectionRegistry::class)
                ->register($this->app->make(AiCoreSettingsContributor::class));
        }
    }

    private function registerCapabilities(): void
    {
        if (! $this->app->bound(CapabilityRegistry::class)) {
            return;
        }

        /** @var CapabilityRegistry $caps */
        $caps = $this->app->make(CapabilityRegistry::class);
        foreach ($this->providedCapabilityIds() as $id) {
            if ($caps->has($id)) {
                continue;
            }
            $caps->register($id, new CapabilityMarker($id, self::SLUG), self::SLUG);
        }
    }

    /** @return list<string> */
    private function providedCapabilityIds(): array
    {
        $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'addon.json';
        if (! is_file($path)) {
            return [];
        }

        $meta = json_decode((string) file_get_contents($path), true);
        if (! is_array($meta) || ! is_array($meta['provides'] ?? null)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $meta['provides'])));
    }
}

final class CapabilityMarker
{
    public function __construct(
        public readonly string $id,
        public readonly string $ownerSlug,
    ) {}
}
