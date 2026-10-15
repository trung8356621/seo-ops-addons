<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Livewire;

use App\Models\Site;
use Livewire\Component;
use Omnichannel\Addons\SearchFoundation\Contracts\IndustryGroup\IndustryGroupProvider;
use Omnichannel\Addons\SearchFoundation\Contracts\IndustryMatchRuleProvider;
use Omnichannel\Addons\SearchFoundation\Contracts\MatchResearch\MatchResearchRegistry;
use Omnichannel\Addons\SearchFoundation\DTO\IndustryGroup\IndustryGroup;
use Omnichannel\Addons\SearchFoundation\Enums\MatchResearchOrigin;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;

/**
 * Read-only Industry Group projection for Semantic Routing.
 * No create, update, delete, import, or match execution.
 */
final class IndustryMatchingPanel extends Component
{
    public ?string $industryContextKey = null;

    public ?string $industryContextUrl = null;

    /** @var array<string, mixed>|null */
    public ?array $provenance = null;

    public string $status = '';

    /** @var list<array<string, mixed>> */
    public array $groups = [];

    public function mount(
        IndustryMatchRuleProvider $industryProvider,
        IndustryGroupProvider $industryGroups,
        MatchResearchRegistry $registry,
    ): void {
        $siteId = SeoAccessControl::globalSiteId();
        $site = $siteId !== null ? Site::query()->find($siteId) : null;
        $this->industryContextKey = trim((string) $site?->getMeta('seo_industry_context_key')) ?: null;
        $this->industryContextUrl = self::industryContextUrl();
        $this->provenance = $industryProvider->provenanceForKey($this->industryContextKey);
        $this->status = $industryProvider->statusForKey($this->industryContextKey);

        $descriptions = [];
        foreach ($registry->list($siteId, MatchResearchOrigin::Industry, $this->industryContextKey) as $resource) {
            $description = trim($resource->description);
            if ($description !== '') {
                $descriptions[$resource->key] = $description;
            }
        }

        $this->groups = self::present(
            $industryGroups->list($siteId, $this->industryContextKey),
            $descriptions,
            $this->provenance,
        );
    }

    /**
     * @param  list<IndustryGroup>  $groups
     * @param  array<string, string>  $descriptions
     * @param  array<string, mixed>|null  $provenance
     * @return list<array<string, mixed>>
     */
    public static function present(array $groups, array $descriptions, ?array $provenance): array
    {
        $revision = $provenance['match_revision_id'] ?? null;
        $rows = [];
        foreach ($groups as $group) {
            if (! $group instanceof IndustryGroup) {
                continue;
            }
            $description = trim((string) ($descriptions[$group->key] ?? ''));
            $rows[] = [
                'key' => $group->key,
                'name' => $group->name,
                'group_type' => $group->groupType->value,
                'group_type_label' => $group->groupType->label(),
                'description' => $description,
                'language' => $group->effectiveLocale !== '' ? $group->effectiveLocale : $group->sourceLocale,
                'source_locale' => $group->sourceLocale,
                'enabled' => $group->enabled,
                'stale' => $group->stale,
                'positive_examples' => $group->positiveExamples,
                'negative_examples' => $group->negativeExamples,
                'aliases' => $group->aliases,
                'match_mode' => $group->matchMode,
                'revision' => $revision,
                'industry_context_key' => $group->industryContextKey ?? ($provenance['industry_context_key'] ?? null),
            ];
        }

        return $rows;
    }

    public static function industryContextUrl(): string
    {
        $resource = 'App\\Filament\\Resources\\IndustryContextProfileResource';
        if (class_exists($resource)) {
            try {
                return $resource::getUrl(panel: 'admin');
            } catch (\Throwable) {
                // fall through
            }
        }

        return url('/admin/industry-context-profiles');
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        return view('search-intelligence::livewire.industry-matching-panel');
    }
}
