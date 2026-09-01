<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Reconciliation;

use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncSchema;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordFocusAttach;
use App\Models\Site;

final class ProviderKeywordReconciler
{
    public function __construct(
        private readonly KeywordNormalizationService $normalizer = new KeywordNormalizationService(),
        private readonly CanonicalKeywordReconciler $canonical = new CanonicalKeywordReconciler(),
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
        $ctx = [
            'site_id' => (int) $site->id,
            'user_id' => $userId,
            'run_id' => (int) ($keywords[0]['run_id'] ?? 0),
        ];

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
            $rawPhrase = (string) ($row['phrase'] ?? '');
            $phrase = Keyword::preparePhraseForStorage($rawPhrase);
            if ($phrase === '') {
                continue;
            }

            $source = (string) ($row['source'] ?? SiteSyncSchema::SOURCE_PROVIDER);
            if ($source === SiteSyncSchema::SOURCE_WORKSPACE) {
                $workspaceEligible++;
            }

            $existing = $this->canonical->findByPhrase($phrase);
            if ($existing instanceof Keyword) {
                $existingSource = (string) ($existing->source ?? '');
                $locked = (bool) ($existing->source_locked ?? false);
                if ($locked || $existingSource === SiteSyncSchema::SOURCE_MANUAL) {
                    $skippedManual++;
                    continue;
                }
            }

            $keyword = $this->canonical->findOrAttachEligible(
                $rawPhrase,
                SiteSyncKeywordCandidateEvaluator::CANDIDATE_PROVIDER,
                $source,
                $ctx,
            );
            if (! $keyword instanceof Keyword) {
                continue;
            }

            $providerUpdated++;

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
}
