<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Jobs;

use Omnichannel\Addons\Seo\Enums\SeoLinkMapStatus;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SearchFoundation\Models\SeoLinkMap;
use Omnichannel\Addons\SearchFoundation\Services\KeywordLinkTargetResolver;
use Omnichannel\Addons\Seo\Services\LinkAuditCacheService;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Omnichannel\Addons\Seo\Support\SeoLinkMapHttpAuditClassifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

final class AuditLinkStatusJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** SEO link-health maintenance — must not share WP publish worker. */
    public const QUEUE_NAME = 'seo-audit';

    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    public int $timeout = 45;

    public int $tries = 2;

    public function __construct(
        public int $linkMapId,
        public int $siteId,
    ) {
        $this->onQueue(self::QUEUE_NAME);
    }

    public function handle(
        SeoDatabaseConnectionService $databaseConnection,
        KeywordLinkTargetResolver $targetResolver,
        LinkAuditCacheService $auditCache,
    ): void {
        if ($this->siteId > 0) {
            $databaseConnection->bootstrapSeoDatabaseConnection($this->siteId);
        }

        $linkMap = SeoLinkMap::query()
            ->with(['targetArticle'])
            ->find($this->linkMapId);

        if (! $linkMap instanceof SeoLinkMap) {
            return;
        }

        if ($linkMap->status === SeoLinkMapStatus::Ignored) {
            return;
        }

        $targetUrl = $this->resolveTargetUrl($linkMap, $targetResolver);
        if ($targetUrl === '') {
            $linkMap->update([
                'status' => SeoLinkMapStatus::Broken,
                'last_http_status' => null,
                'last_audited_at' => now(),
            ]);

            return;
        }

        try {
            $response = Http::timeout(5)
                ->withHeaders(['User-Agent' => self::USER_AGENT])
                ->withOptions([
                    'allow_redirects' => [
                        'max' => 5,
                        'strict' => false,
                        'referer' => true,
                        'track_redirects' => false,
                    ],
                ])
                ->get($targetUrl);

            $status = SeoLinkMapHttpAuditClassifier::classifyResponse($response);
            $httpStatus = $response->status();
            $auditedAt = now();

            $linkMap->update([
                'status' => $status,
                'last_http_status' => $httpStatus,
                'last_audited_at' => $auditedAt,
            ]);

            $auditCache->upsertFromLinkMap($this->siteId, $targetUrl, $status, $httpStatus, $auditedAt);
        } catch (\Throwable) {
            $auditedAt = now();
            $linkMap->update([
                'status' => SeoLinkMapStatus::Broken,
                'last_http_status' => null,
                'last_audited_at' => $auditedAt,
            ]);

            $auditCache->upsertFromLinkMap(
                $this->siteId,
                $targetUrl,
                SeoLinkMapStatus::Broken,
                null,
                $auditedAt,
            );
        }
    }

    private function resolveTargetUrl(SeoLinkMap $linkMap, KeywordLinkTargetResolver $targetResolver): string
    {
        $externalUrl = trim((string) ($linkMap->target_external_url ?? ''));
        if ($externalUrl !== '') {
            return $externalUrl;
        }

        if ((int) ($linkMap->target_article_id ?? 0) <= 0) {
            return '';
        }

        $targetArticle = $linkMap->targetArticle;
        if (! $targetArticle instanceof SeoArticle) {
            return '';
        }

        return trim((string) ($targetResolver->resolveArticlePublicUrl($targetArticle) ?? ''));
    }
}
