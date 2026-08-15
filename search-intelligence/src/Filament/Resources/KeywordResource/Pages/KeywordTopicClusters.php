<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages;

use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns\HasKeywordWorkspaceNavigation;
use Omnichannel\Addons\SearchIntelligence\Jobs\RecomputeKeywordGroupMembershipsJob;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordRuleGroup;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordRuleGroupRule;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterQuery;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordGroupMembershipService;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordNormalizer;

final class KeywordTopicClusters extends Page
{
    use HasKeywordWorkspaceNavigation;

    protected static string $resource = KeywordResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.keywords.pages.topic-cluster-index';

    protected static bool $shouldRegisterNavigation = false;

    #[Url(as: 'section')]
    public string $section = 'clusters';

    public string $clusterSearch = '';

    public string $coverageFilter = '';

    public bool $hasArticles = false;

    public string $groupSearch = '';

    public string $groupTypeFilter = '';

    public string $newGroupLabel = '';

    public string $newRulePhrase = '';

    public ?int $editingGroupId = null;

    public function mount(): void
    {
        $this->initializeKeywordWorkspaceSiteFilter();
        if (! in_array($this->section, ['clusters', 'groups'], true)) {
            $this->section = 'clusters';
        }
        app(KeywordGroupMembershipService::class)->ensureSystemGroups();
    }

    public static function canAccess(array $parameters = []): bool
    {
        return KeywordResource::canViewAny();
    }

    public function getTitle(): string|Htmlable
    {
        return __('seo-content-ai::filament.keyword.topic_cluster_title');
    }

    protected function getActiveKeywordWorkspaceKey(): string
    {
        return 'workspace-2';
    }

    /**
     * @return array<string, int>
     */
    public function getSummary(): array
    {
        return app(KeywordClusterQuery::class)->summary($this->resolveKeywordWorkspaceSiteId());
    }

    public function getClusters()
    {
        return app(KeywordClusterQuery::class)->paginateClusters(
            $this->resolveKeywordWorkspaceSiteId(),
            [
                'search' => $this->clusterSearch,
                'coverage' => $this->coverageFilter,
                'has_articles' => $this->hasArticles,
            ],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getGroups(): array
    {
        $memberships = app(KeywordGroupMembershipService::class);
        if (! $memberships->tablesReady()) {
            return [];
        }

        $search = mb_strtolower(trim($this->groupSearch));
        $type = trim($this->groupTypeFilter);

        return KeywordRuleGroup::query()
            ->withCount(['rules', 'members'])
            ->orderBy('sort_order')
            ->get()
            ->filter(function (KeywordRuleGroup $group) use ($search, $type): bool {
                if ($type !== '' && (string) $group->group_type !== $type) {
                    return false;
                }
                if ($search === '') {
                    return true;
                }
                $hay = mb_strtolower($group->label.' '.$group->group_key);

                return str_contains($hay, $search);
            })
            ->map(static fn (KeywordRuleGroup $group): array => [
                'id' => (int) $group->id,
                'key' => (string) $group->group_key,
                'label' => (string) $group->label,
                'type' => (string) $group->group_type,
                'rules' => (int) ($group->rules_count ?? 0),
                'keywords' => (int) ($group->members_count ?? 0),
                'active' => (bool) $group->is_active,
            ])
            ->values()
            ->all();
    }

    public function getEditingGroup(): ?KeywordRuleGroup
    {
        if ($this->editingGroupId === null) {
            return null;
        }

        return KeywordRuleGroup::query()->with('rules')->find($this->editingGroupId);
    }

    public function showClusters(): void
    {
        $this->section = 'clusters';
    }

    public function showGroups(): void
    {
        $this->section = 'groups';
    }

    public function createCustomGroup(): void
    {
        $label = trim($this->newGroupLabel);
        if ($label === '') {
            return;
        }
        $normalizer = app(KeywordNormalizer::class);
        $key = preg_replace('/[^a-z0-9_]+/', '_', $normalizer->normalize($label)['folded_text']) ?: 'custom';
        $group = KeywordRuleGroup::query()->create([
            'site_id' => 0,
            'group_key' => 'custom_'.$key.'_'.substr(sha1((string) microtime(true)), 0, 6),
            'label' => $label,
            'group_type' => 'custom',
            'is_active' => true,
            'sort_order' => 100,
        ]);
        $this->newGroupLabel = '';
        $this->editingGroupId = (int) $group->id;
        $this->section = 'groups';
        Notification::make()->title(__('seo-content-ai::filament.keyword.topic_group_created'))->success()->send();
    }

    public function editGroup(int $groupId): void
    {
        $this->editingGroupId = $groupId;
        $this->section = 'groups';
    }

    public function addRuleToEditingGroup(): void
    {
        $group = $this->getEditingGroup();
        $phrase = trim($this->newRulePhrase);
        if (! $group instanceof KeywordRuleGroup || $phrase === '') {
            return;
        }
        $norm = app(KeywordNormalizer::class)->normalize($phrase);
        KeywordRuleGroupRule::query()->create([
            'group_id' => (int) $group->id,
            'match_type' => 'contains',
            'phrase' => $norm['raw_text'],
            'folded_phrase' => $norm['folded_text'],
            'is_active' => true,
        ]);
        $this->newRulePhrase = '';
        $siteId = $this->resolveKeywordWorkspaceSiteId() ?? 0;
        RecomputeKeywordGroupMembershipsJob::dispatch($siteId);
        Notification::make()->title(__('seo-content-ai::filament.keyword.topic_rule_added'))->success()->send();
    }

    public function deleteRule(int $ruleId): void
    {
        KeywordRuleGroupRule::query()->whereKey($ruleId)->delete();
        $siteId = $this->resolveKeywordWorkspaceSiteId() ?? 0;
        RecomputeKeywordGroupMembershipsJob::dispatch($siteId);
    }

    public function toggleGroup(int $groupId): void
    {
        $group = KeywordRuleGroup::query()->find($groupId);
        if (! $group instanceof KeywordRuleGroup) {
            return;
        }
        $group->is_active = ! $group->is_active;
        $group->save();
        RecomputeKeywordGroupMembershipsJob::dispatch($this->resolveKeywordWorkspaceSiteId() ?? 0);
    }

    public function clusterUrl(string $clusterKey): string
    {
        $url = KeywordResource::getUrl('cluster', ['clusterKey' => $clusterKey]);
        $siteId = $this->resolveKeywordWorkspaceSiteId();
        if ($siteId !== null) {
            $url .= (str_contains($url, '?') ? '&' : '?').'site_id='.$siteId;
        }

        return $url;
    }

    public function unclusteredUrl(): string
    {
        return app(KeywordClusterQuery::class)->unclusteredListUrl($this->resolveKeywordWorkspaceSiteId());
    }
}
