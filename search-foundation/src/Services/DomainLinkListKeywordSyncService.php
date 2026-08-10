<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Services;

use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\Seo\Support\CtaKeywordBlacklistFilter;
use App\Models\Site;
use Omnichannel\Addons\AiPrompt\Services\SiteDomainPromptContextService;

/**
 * Đồng bộ Link list (Technical SEO) → bảng keywords + target_url để gợi ý/chèn link nội bộ,

 * không đưa danh sách vào prompt AI.
 */
final class DomainLinkListKeywordSyncService
{
    public function __construct(

        private readonly SiteDomainPromptContextService $promptContext,

        private readonly CtaKeywordBlacklistFilter $ctaKeywordBlacklistFilter,

        private readonly KeywordPersistenceService $keywordPersistence,

    ) {}

    public function syncFromStoredContext(Site|int $site): int
    {

        $site = $site instanceof Site ? $site : Site::query()->findOrFail((int) $site);

        $payload = $this->promptContext->getForSite($site);

        return $this->syncLinks($site, $payload['links'] ?? []);

    }

    /**
     * @param  list<array{keyword?: string, link?: string}>  $links
     */
    public function syncLinks(Site $site, array $links): int
    {

        if (! \Omnichannel\Addons\SearchFoundation\Support\KeywordSyncIsolation::allowsDomainLinkListSync()) {
            return 0;
        }

        $siteId = (int) $site->getKey();

        if ($siteId <= 0 || $links === []) {

            return 0;

        }

        $synced = 0;

        foreach ($links as $row) {

            if (! is_array($row)) {

                continue;

            }

            $phrase = Keyword::decodePhrase((string) ($row['keyword'] ?? ''));
            $targetUrl = trim((string) ($row['link'] ?? ''));
            if ($phrase === '' || $targetUrl === '') {

                continue;

            }

            if ($this->ctaKeywordBlacklistFilter->isBlocked($phrase)) {

                continue;

            }

            $this->keywordPersistence->upsert(

                $phrase,

                Keyword::TYPE_NORMAL,

                $siteId,

                $targetUrl,

            );

            $synced++;

        }

        return $synced;

    }

    public function upsertLinkInDomainContext(int $siteId, string $phrase, string $url): bool
    {

        $phrase = trim($phrase);

        $url = trim($url);

        if ($siteId <= 0 || $phrase === '' || $url === '') {

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
}
