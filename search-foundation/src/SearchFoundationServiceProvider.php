<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation;

use App\Core\Capability\CapabilityRegistry;
use App\Core\Members\MembersSectionRegistry;
use Illuminate\Support\ServiceProvider;
use Omnichannel\Addons\SearchFoundation\Console\BackfillLinkClassificationCommand;
use Omnichannel\Addons\SearchFoundation\Members\SeoMembersSectionContributor;

/**
 * Peer addon skeleton: registers capabilities into Client Core.
 * Implementation still migrating out of SeoContentAi legacy monolith.
 */
final class SearchFoundationServiceProvider extends ServiceProvider
{
    public const SLUG = 'search-foundation';

    public function register(): void
    {
        $this->registerCapabilities();

        $this->app->singleton(SeoMembersSectionContributor::class);
        $this->app->singleton(\Omnichannel\Addons\SearchFoundation\Services\MatchResearch\IndustryMatchResearchKeyDeriver::class);
        $this->app->singleton(\Omnichannel\Addons\SearchFoundation\Services\MatchResearch\IndustryMatchResearchProjector::class);
        $this->app->singleton(\Omnichannel\Addons\SearchFoundation\Services\MatchResearch\CustomMatchResearchStore::class);
        $this->app->singleton(\Omnichannel\Addons\SearchFoundation\Services\MatchResearch\MatchResearchLocaleOverlayStore::class);
        $this->app->singleton(\Omnichannel\Addons\SearchFoundation\Services\MatchResearch\MatchResearchConsumerPolicyStore::class);
        $this->app->singleton(\Omnichannel\Addons\SearchFoundation\Services\MatchResearch\MatchResearchLocalizationPromptBuilder::class);
        $this->app->singleton(\Omnichannel\Addons\SearchFoundation\Services\MatchResearch\MatchResearchLocalizationImporter::class);
        $this->app->singleton(
            \Omnichannel\Addons\SearchFoundation\Contracts\MatchResearch\MatchResearchRegistry::class,
            \Omnichannel\Addons\SearchFoundation\Services\MatchResearch\MatchResearchRegistryService::class,
        );
        $this->app->singleton(
            \Omnichannel\Addons\SearchFoundation\Contracts\IndustryGroup\IndustryGroupProvider::class,
            \Omnichannel\Addons\SearchFoundation\Services\IndustryGroup\IndustryGroupReadModel::class,
        );
    }

    public function boot(): void
    {
        if ($this->app->bound(MembersSectionRegistry::class)) {
            $registry = $this->app->make(MembersSectionRegistry::class);
            $contributor = $this->app->make(SeoMembersSectionContributor::class);
            if (! $registry->has($contributor->addonSlug())) {
                $registry->register($contributor);
            }
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                BackfillLinkClassificationCommand::class,
            ]);
        }
    }

    private function registerCapabilities(): void
    {
        if (! $this->app->bound(CapabilityRegistry::class)) {
            return;
        }

        /** @var CapabilityRegistry $caps */
        $caps = $this->app->make(CapabilityRegistry::class);

        $this->app->singleton(CanonicalKeywordIdentity::class);

        if (! $caps->has('search.keyword')) {
            $caps->register(
                'search.keyword',
                $this->app->make(CanonicalKeywordIdentity::class),
                self::SLUG,
            );
        }

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
