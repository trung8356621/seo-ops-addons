<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Services;

use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\Seo\Support\CtaKeywordBlacklistFilter;
use App\Models\Site;
use Omnichannel\Addons\AiPrompt\Services\SiteDomainPromptContextService;
use Omnichannel\Addons\SearchFoundation\Services\SiteLink\SiteLinkPolicyResolver;

/**
 * Đồng bộ Link list (Technical SEO) → bảng keywords + target_url để gợi ý/chèn link nội bộ,
 * không đưa danh sách vào prompt AI.
 *
 * Effective Keyword input: {@see SiteLinkPolicyResolver::forKeyword()}.
 * Manual prompt `links` ownership stays on {@see SiteDomainPromptContextService} —
 * product_cat policy rows are materialized as Keywords only (never written into prompt).
 */
final class DomainLinkListKeywordSyncService
{
    public function __construct(
        private readonly SiteDomainPromptContextService $promptContext,
        private readonly CtaKeywordBlacklistFilter $ctaKeywordBlacklistFilter,
        private readonly KeywordPersistenceService $keywordPersistence,
        private readonly KeywordMetaRepository $keywordMeta,
        private readonly ?SiteLinkPolicyResolver $policy = null,
    ) {}

    public function syncFromStoredContext(Site|int $site): int
    {
        $site = $site instanceof Site ? $site : Site::query()->findOrFail((int) $site);
        $records = $this->policy()->forKeyword($site);

        return $this->materializePolicyRecords($site, $records);
    }

    /**
     * Materialize policy records into Keyword rows. Does not mutate prompt context.
     *
     * @param  list<array{keyword?: string, url?: string, link?: string, source?: string}>  $records
     */
    public function materializePolicyRecords(Site $site, array $records): int
    {
        if (! \Omnichannel\Addons\SearchFoundation\Support\KeywordSyncIsolation::allowsDomainLinkListSync()) {
            return 0;
        }

        $siteId = (int) $site->getKey();
        if ($siteId <= 0 || $records === []) {
            return 0;
        }

        $synced = 0;

        foreach ($records as $row) {
            if (! is_array($row)) {
                continue;
            }

            $phrase = Keyword::decodePhrase((string) ($row['keyword'] ?? ''));
            $targetUrl = trim((string) ($row['url'] ?? $row['link'] ?? ''));
            if ($phrase === '' || $targetUrl === '') {
                continue;
            }

            if ($this->ctaKeywordBlacklistFilter->isBlocked($phrase)) {
                continue;
            }

            $source = (string) ($row['source'] ?? SiteLinkPolicyResolver::SOURCE_DOMAIN_LINK_LIST);
            if ($source === SiteLinkPolicyResolver::SOURCE_MAIN_DOMAIN) {
                // Editor-only composition — never materialize main domain as Keyword.
                continue;
            }

            $keyword = $this->keywordPersistence->upsert(
                $phrase,
                Keyword::TYPE_NORMAL,
                $siteId,
                $targetUrl,
            );
            if (! $keyword instanceof Keyword) {
                continue;
            }

            $this->keywordMeta->set(
                (int) $keyword->id,
                KeywordMetaKey::siteLinkPolicySource($siteId),
                $source === SiteLinkPolicyResolver::SOURCE_PRODUCT_CAT
                    ? SiteLinkPolicyResolver::SOURCE_PRODUCT_CAT
                    : SiteLinkPolicyResolver::SOURCE_DOMAIN_LINK_LIST,
            );

            $synced++;
        }

        return $synced;
    }

    /**
     * @param  list<array{keyword?: string, link?: string}>  $links
     */
    public function syncLinks(Site $site, array $links): int
    {
        $records = [];
        foreach ($links as $row) {
            if (! is_array($row)) {
                continue;
            }
            $records[] = [
                'keyword' => (string) ($row['keyword'] ?? ''),
                'url' => (string) ($row['link'] ?? ''),
                'source' => SiteLinkPolicyResolver::SOURCE_DOMAIN_LINK_LIST,
            ];
        }

        return $this->materializePolicyRecords($site, $records);
    }

    /**
     * Manual Domain Link List only — never write product_cat policy rows into prompt.
     */
    public function upsertLinkInDomainContext(int $siteId, string $phrase, string $url): bool
    {
        $phrase = trim($phrase);
        $url = trim($url);

        if ($siteId <= 0 || $phrase === '' || $url === '') {
            return false;
        }

        if ($this->isProductCatPolicyKeyword($siteId, $phrase)) {
            return false;
        }

        $site = Site::query()->find($siteId);
        if ($site === null) {
            return false;
        }

        $context = $this->promptContext->getForSite($site);
        $links = $context['links'] ?? [];
        $phraseLower = mb_strtolower($phrase);
        $updated = false;

        foreach ($links as $index => $row) {
            if (mb_strtolower(trim((string) ($row['keyword'] ?? ''))) === $phraseLower) {
                $links[$index] = ['keyword' => $phrase, 'link' => $url];
                $updated = true;
                break;
            }
        }

        if (! $updated) {
            $links[] = ['keyword' => $phrase, 'link' => $url];
        }

        $this->promptContext->saveForSite($site, array_merge($context, ['links' => $links]));

        return true;
    }

    public function removeLinkFromDomainContext(int $siteId, string $phrase): bool
    {
        $phrase = trim($phrase);

        if ($siteId <= 0 || $phrase === '') {
            return false;
        }

        $site = Site::query()->find($siteId);
        if ($site === null) {
            return false;
        }

        $context = $this->promptContext->getForSite($site);
        $links = $context['links'] ?? [];
        $phraseLower = mb_strtolower($phrase);
        $nextLinks = [];

        foreach ($links as $row) {
            if (mb_strtolower(trim((string) ($row['keyword'] ?? ''))) === $phraseLower) {
                continue;
            }
            $nextLinks[] = $row;
        }

        if (count($nextLinks) === count($links)) {
            return false;
        }

        $this->promptContext->saveForSite($site, array_merge($context, ['links' => $nextLinks]));

        return true;
    }

    /**
     * True when keyword was materialized from verified product_cat policy (not manual list).
     */
    public function isProductCatPolicyKeyword(int $siteId, string $phrase): bool
    {
        $phrase = Keyword::decodePhrase($phrase);
        if ($siteId <= 0 || $phrase === '') {
            return false;
        }

        $keyword = Keyword::query()
            ->whereRaw('phrase COLLATE utf8mb4_unicode_ci = ?', [$phrase])
            ->first();
        if (! $keyword instanceof Keyword) {
            return false;
        }

        $source = trim((string) ($this->keywordMeta->get(
            (int) $keyword->id,
            KeywordMetaKey::siteLinkPolicySource($siteId),
        ) ?? ''));

        return $source === SiteLinkPolicyResolver::SOURCE_PRODUCT_CAT;
    }

    private function policy(): SiteLinkPolicyResolver
    {
        if ($this->policy instanceof SiteLinkPolicyResolver) {
            return $this->policy;
        }

        return app(SiteLinkPolicyResolver::class);
    }
}
