<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime;

use App\Core\Capability\CapabilityRegistry;
use Illuminate\Support\ServiceProvider;
use Omnichannel\Addons\AgentRuntime\Answer\AiSettingsAnswerModelGateway;
use Omnichannel\Addons\AgentRuntime\Answer\AnswerModelGateway;
use Omnichannel\Addons\AgentRuntime\Decision\AiSettingsDecisionModelGateway;
use Omnichannel\Addons\AgentRuntime\Decision\DecisionModelGateway;
use Omnichannel\Addons\AgentRuntime\Projects\EloquentSiteDirectory;
use Omnichannel\Addons\AgentRuntime\Projects\SiteDirectory;
use Omnichannel\Addons\AgentRuntime\Retrieval\ConfigSeoAccessCredential;
use Omnichannel\Addons\AgentRuntime\Retrieval\LaravelSeoAccessTransport;
use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalExecutor;
use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalPlanner;
use Omnichannel\Addons\AgentRuntime\Retrieval\SeoAccessCredential;
use Omnichannel\Addons\AgentRuntime\Retrieval\SeoAccessExecutor;
use Omnichannel\Addons\AgentRuntime\Retrieval\SeoAccessTransport;
use Omnichannel\Addons\AgentRuntime\Retrieval\SeoAccessUrlPolicy;
use Omnichannel\Addons\AiPrompt\Contracts\AnswerTextCompletion;
use Omnichannel\Addons\AiPrompt\Contracts\DecisionModelCompletion;
use Omnichannel\Addons\AiPrompt\Contracts\DecisionModelSource;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\HtmlString;

final class AgentRuntimeServiceProvider extends ServiceProvider
{
    public const SLUG = 'agent-runtime';

    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__).'/config/agent-runtime.php', 'agent-runtime');
        $this->app->singleton(SiteDirectory::class, EloquentSiteDirectory::class);
        $this->app->singleton(SeoAccessCredential::class, ConfigSeoAccessCredential::class);
        $this->app->singleton(SeoAccessTransport::class, LaravelSeoAccessTransport::class);
        $this->app->singleton(SeoAccessUrlPolicy::class);
        $this->app->singleton(RetrievalPlanner::class, static function (): RetrievalPlanner {
            $threshold = config('agent-runtime.need_threshold', RetrievalPlanner::DEFAULT_NEED_THRESHOLD);

            return new RetrievalPlanner(is_numeric($threshold) ? (float) $threshold : RetrievalPlanner::DEFAULT_NEED_THRESHOLD);
        });
        $this->app->singleton(SeoAccessExecutor::class, static function ($app): SeoAccessExecutor {
            $base = config('agent-runtime.seo_access_base_url');
            if (! is_string($base) || trim($base) === '') {
                $base = (string) config('app.url');
            }

            return new SeoAccessExecutor(
                $app->make(SeoAccessTransport::class),
                $app->make(SeoAccessCredential::class),
                $app->make(SeoAccessUrlPolicy::class),
                $base,
            );
        });
        $this->app->singleton(RetrievalExecutor::class);
        $this->app->singleton(\Omnichannel\Addons\AgentRuntime\Model\AssumedModelResolver::class, \Omnichannel\Addons\AgentRuntime\Model\AiSettingsAssumedModelResolver::class);
        $this->app->singleton(DecisionModelGateway::class, static function ($app): DecisionModelGateway {
            $max = (int) config('agent-runtime.decision_max_output_tokens', 800);

            return new AiSettingsDecisionModelGateway(
                $app->make(DecisionModelSource::class),
                $app->make(DecisionModelCompletion::class),
                $max > 0 ? $max : 800,
            );
        });
        $this->app->singleton(AnswerModelGateway::class, static function ($app): AnswerModelGateway {
            $max = (int) config('agent-runtime.answer_max_output_tokens', 2048);

            return new AiSettingsAnswerModelGateway(
                $app->make(AnswerTextCompletion::class),
                $max > 0 ? $max : 2048,
            );
        });
        $this->registerCapabilities();
    }

    public function boot(): void
    {
        $this->loadViewsFrom(dirname(__DIR__).'/resources/views', 'agent-runtime');
        $this->loadMigrationsFrom(dirname(__DIR__).'/database/migrations');
        if (! $this->app->routesAreCached()) {
            $this->loadRoutesFrom(dirname(__DIR__).'/routes/web.php');
        }
        $this->registerGlobalHeaderLauncherHook();
        $this->registerGlobalDrawerHook();
    }

    private function registerGlobalHeaderLauncherHook(): void
    {
        if (! class_exists(FilamentView::class) || ! class_exists(PanelsRenderHook::class)) {
            return;
        }

        FilamentView::registerRenderHook(
            PanelsRenderHook::USER_MENU_BEFORE,
            static function (): HtmlString {
                if (! auth()->check()) {
                    return new HtmlString('');
                }

                try {
                    $panelId = Filament::getCurrentPanel()?->getId();
                } catch (\Throwable) {
                    return new HtmlString('');
                }

                if (! is_string($panelId) || ! in_array($panelId, ['admin', 'seo', 'seo-main', 'seeding'], true)) {
                    return new HtmlString('');
                }

                return new HtmlString(
                    view('agent-runtime::filament.hooks.agent-launcher')->render()
                );
            },
        );
    }

    private function registerGlobalDrawerHook(): void
    {
        if (! class_exists(FilamentView::class) || ! class_exists(PanelsRenderHook::class)) {
            return;
        }

        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            static function (): HtmlString {
                if (! auth()->check()) {
                    return new HtmlString('');
                }

                try {
                    $panelId = Filament::getCurrentPanel()?->getId();
                } catch (\Throwable) {
                    return new HtmlString('');
                }

                if (! is_string($panelId) || ! in_array($panelId, ['admin', 'seo', 'seo-main', 'seeding'], true)) {
                    return new HtmlString('');
                }

                return new HtmlString(
                    view('agent-runtime::filament.hooks.agent-drawer')->render()
                );
            },
        );
    }

    private function registerCapabilities(): void
    {
        if (! $this->app->bound(CapabilityRegistry::class)) {
            return;
        }

        /** @var CapabilityRegistry $caps */
        $caps = $this->app->make(CapabilityRegistry::class);
        if ($caps->has('agent.runtime')) {
            return;
        }
        $caps->register('agent.runtime', new CapabilityMarker('agent.runtime', self::SLUG), self::SLUG);
    }
}

final class CapabilityMarker
{
    public function __construct(
        public readonly string $id,
        public readonly string $ownerSlug,
    ) {}
}
