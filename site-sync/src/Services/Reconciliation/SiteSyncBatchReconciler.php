<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Reconciliation;

use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncBatch;
use Omnichannel\Addons\AiPrompt\Services\SiteDomainPromptContextService;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncBatchData;
use Omnichannel\Addons\WordPress\Services\SyncDomainContentService;
use App\Models\Site;

/**
 * Applies a staged batch into SeoArticle / LinkCatalog / Keywords / Scores / Profile suggests.
 * Normal path never parses HTML.
 */
final class SiteSyncBatchReconciler
{
    public const META_PROFILE_SUGGEST = 'seo_domain_profile_suggest';

    public function __construct(
        private readonly SyncDomainContentService $articleImport,
        private readonly SiteLinkCatalogReconciler $links,
        private readonly ProviderKeywordReconciler $keywords,
        private readonly ArticleScoreSourceReconciler $scores,
        private readonly SiteDomainPromptContextService $promptContext,
    ) {}

    /**
     * Profile / contacts suggest only — does not mark content batches applied.
     *
     * @param  array<string, mixed>|null  $profile
     * @param  list<array<string, mixed>>  $contacts
     */
    public function applyProfileSuggestOnly(Site $site, ?array $profile, array $contacts = []): void
    {
        $this->suggestProfile($site, $profile, $contacts);
    }

    /**
     * @return array<string, int>
     */
    public function apply(Site $site, SeoSiteSyncBatch $batch): array
    {
        if ($batch->applied_at !== null) {
            return [
                'articles' => 0,
                'links' => 0,
                'provider_keywords' => 0,
                'scores' => 0,
                'idempotent_skip' => 1,
            ];
        }

        $data = SiteSyncBatchData::fromArray($batch->decodedPayload());
        $articleCounts = $this->articleImport->importItems($site, $data->articles);
        $linkCounts = $this->links->reconcileWordPressLinks($site, $data->links);
        $keywordCounts = $this->keywords->reconcile($site, $data->providerKeywords);
        $scoreCounts = $this->scores->reconcile($site, $data->scores);
        $this->suggestProfile($site, $data->profile, $data->contactsSuggest);

        $batch->forceFill(['applied_at' => now()])->save();

        $articlesSynced = (int) ($articleCounts['article'] ?? 0)
            + (int) ($articleCounts['product'] ?? 0)
            + (int) ($articleCounts['category'] ?? 0)
            + (int) ($articleCounts['product_category'] ?? 0);
        $fetched = count($data->articles);
        $updated = min($articlesSynced, $fetched);
        $unchanged = max(0, $fetched - $updated);

        return [
            'articles' => $articlesSynced,
            'created' => (int) ($articleCounts['created'] ?? 0),
            'updated' => $updated,
            'unchanged' => $unchanged,
            'failed' => (int) ($articleCounts['failed'] ?? 0),
            'urls_synced' => $linkCounts['upserted'],
            'provider_keywords' => $keywordCounts['provider_updated'],
            'skipped_manual_keywords' => $keywordCounts['skipped_manual'],
            'scores' => $scoreCounts['upserted'],
            'idempotent_skip' => 0,
        ];
    }

    /**
     * Compat path: articles already imported by legacy; only enrich catalog/keywords/scores.
     *
     * @return array<string, int>
     */
    public function applyLinksKeywordsScoresOnly(Site $site, SeoSiteSyncBatch $batch): array
    {
        $data = SiteSyncBatchData::fromArray($batch->decodedPayload());
        $linkCounts = $this->links->reconcileWordPressLinks($site, $data->links);
        $keywordCounts = $this->keywords->reconcile($site, $data->providerKeywords);
        $scoreCounts = $this->scores->reconcile($site, $data->scores);

        if ($batch->applied_at === null) {
            $batch->forceFill(['applied_at' => now()])->save();
        }

        return [
            'urls_synced' => $linkCounts['upserted'],
            'provider_keywords' => $keywordCounts['provider_updated'],
            'scores' => $scoreCounts['upserted'],
        ];
    }

    /**
     * Auto-fill empty manual fields; otherwise store Suggest Update only (never overwrite manual).
     *
     * @param  array<string, mixed>|null  $profile
     * @param  list<array<string, mixed>>  $contacts
     */
    private function suggestProfile(Site $site, ?array $profile, array $contacts): void
    {
        if ($profile === null && $contacts === []) {
            return;
        }

        $current = $this->promptContext->getRawPayloadForSite($site);
        $suggest = [];
        $autoFill = $current;
        $changed = false;

        if (is_array($profile)) {
            foreach (['tone', 'short_description', 'cta_intro'] as $field) {
                $incoming = trim((string) ($profile[$field] ?? ''));
                $existing = trim((string) ($current[$field] ?? ''));
                if ($incoming === '') {
                    continue;
                }
                if ($existing === '') {
                    $autoFill[$field] = $incoming;
                    $changed = true;
                } elseif ($existing !== $incoming) {
                    $suggest[$field] = $incoming;
                }
            }
        }

        foreach ($contacts as $contact) {
            $type = (string) ($contact['type'] ?? '');
            $value = trim((string) ($contact['value'] ?? ''));
            if ($value === '' || ! in_array($type, ['phone', 'email'], true)) {
                continue;
            }
            $filled = false;
            for ($i = 1; $i <= 3; $i++) {
                $key = $type.'_'.$i;
                if (trim((string) ($autoFill[$key] ?? '')) === '') {
                    $autoFill[$key] = $value;
                    $changed = true;
                    $filled = true;
                    break;
                }
                if (trim((string) ($autoFill[$key] ?? '')) === $value) {
                    $filled = true;
                    break;
                }
            }
            if (! $filled) {
                $suggest['contacts'][] = ['type' => $type, 'value' => $value];
            }
        }

        if ($changed) {
            $this->promptContext->saveForSite($site, [
                'tone' => (string) ($autoFill['tone'] ?? ''),
                'short_description' => (string) ($autoFill['short_description'] ?? ''),
                'cta_intro' => (string) ($autoFill['cta_intro'] ?? ''),
                'phone_1' => (string) ($autoFill['phone_1'] ?? ''),
                'phone_2' => (string) ($autoFill['phone_2'] ?? ''),
                'phone_3' => (string) ($autoFill['phone_3'] ?? ''),
                'email_1' => (string) ($autoFill['email_1'] ?? ''),
                'email_2' => (string) ($autoFill['email_2'] ?? ''),
                'email_3' => (string) ($autoFill['email_3'] ?? ''),
                'cta' => is_array($autoFill['cta'] ?? null) ? $autoFill['cta'] : [],
                'links' => is_array($autoFill['links'] ?? null) ? $autoFill['links'] : [],
            ]);
        }

        $site->metas()->updateOrCreate(
            ['meta_key' => self::META_PROFILE_SUGGEST],
            ['meta_value' => json_encode($suggest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'],
        );
    }
}
