<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Reconciliation;

use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncSchema;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordFocusAttach;
use App\Models\Site;
use Illuminate\Support\Facades\Schema;

final class ProviderKeywordReconciler
{
    public function __construct(
        private readonly KeywordNormalizationService $normalizer = new KeywordNormalizationService(),
    ) {}

    /**
     * @param  list<array<string, mixed>>  $keywords
     * @return array{provider_updated: int, skipped_manual: int, workspace_eligible: int}
     */
    public function reconcile(Site $site, array $keywords): array
    {
        $providerUpdated = 0;
        $skippedManual = 0;
        $workspaceEligible = 0;
        $userId = (int) $site->user_id;
        $hasSource = $this->keywordsHaveSourceColumn();

        $expanded = [];
        foreach ($keywords as $row) {
            $raw = (string) ($row['phrase'] ?? '');
            $parts = preg_split('/\s*,\s*/u', $raw) ?: [$raw];
            foreach ($this->normalizer->dedupeCaseInsensitive($parts) as $norm) {
                $expanded[] = array_merge($row, [
                    'phrase' => $norm['phrase'],
                    'display' => $norm['display'],
                ]);
            }
        }

        foreach ($expanded as $row) {
            $phrase = Keyword::preparePhraseForStorage((string) ($row['phrase'] ?? ''));
            if ($phrase === '') {
                continue;
            }

            $source = (string) ($row['source'] ?? SiteSyncSchema::SOURCE_PROVIDER);
            if ($source === SiteSyncSchema::SOURCE_WORKSPACE) {
                $workspaceEligible++;
            }

            $existing = Keyword::query()
                ->where('phrase', $phrase)
                ->when(
                    Schema::connection('omi_seo_ai')->hasColumn('keywords', 'user_id'),
                    static fn ($q) => $q->where('user_id', $userId),
                )
                ->first();

            if ($existing !== null) {
                $existingSource = (string) ($existing->source ?? '');
                $locked = (bool) ($existing->source_locked ?? false);
                if ($locked || $existingSource === SiteSyncSchema::SOURCE_MANUAL) {
                    $skippedManual++;
                    continue;
                }
                if ($existingSource === SiteSyncSchema::SOURCE_MANUAL) {
                    $skippedManual++;
                    continue;
                }
                // Priority: Manual > Provider > Workspace — never downgrade.
                if ($this->priorityRank($existingSource) < $this->priorityRank($source)) {
                    $skippedManual++;
                    continue;
                }

                if ($hasSource) {
                    $existing->forceFill([
                        'source' => $source === SiteSyncSchema::SOURCE_WORKSPACE
                            ? SiteSyncSchema::SOURCE_WORKSPACE
                            : SiteSyncSchema::SOURCE_PROVIDER,
                        'source_locked' => false,
                    ])->save();
                }
                $providerUpdated++;
            } else {
                $attrs = [
                    'phrase' => $phrase,
                    'type' => Keyword::TYPE_NORMAL,
                ];
                if (Schema::connection('omi_seo_ai')->hasColumn('keywords', 'user_id')) {
                    $attrs['user_id'] = $userId;
                }
                if (Schema::connection('omi_seo_ai')->hasColumn('keywords', 'site_id')) {
                    $attrs['site_id'] = (int) $site->id;
                }
                if ($hasSource) {
                    $attrs['source'] = $source === SiteSyncSchema::SOURCE_WORKSPACE
                        ? SiteSyncSchema::SOURCE_WORKSPACE
                        : SiteSyncSchema::SOURCE_PROVIDER;
                    $attrs['source_locked'] = false;
                }
                $existing = Keyword::query()->create($attrs);
                $providerUpdated++;
            }

            $wpId = (int) ($row['wordpress_id'] ?? 0);
            if ($wpId > 0 && class_exists(KeywordFocusAttach::class)) {
                $article = SeoArticle::query()
                    ->where('site_id', (int) $site->id)
                    ->whereWpPostId($wpId)
                    ->first();
                if ($article !== null) {
                    KeywordFocusAttach::syncMainKeyword($article, (int) $site->id, $userId, $phrase);
                }
            }
        }

        return [
            'provider_updated' => $providerUpdated,
            'skipped_manual' => $skippedManual,
            'workspace_eligible' => $workspaceEligible,
        ];
    }

    private function priorityRank(string $source): int
    {
        $idx = array_search($source, SiteSyncSchema::KEYWORD_PRIORITY, true);

        return $idx === false ? 99 : (int) $idx;
    }

    private function keywordsHaveSourceColumn(): bool
    {
        try {
            return Schema::connection('omi_seo_ai')->hasColumn('keywords', 'source');
        } catch (\Throwable) {
            return false;
        }
    }
}
