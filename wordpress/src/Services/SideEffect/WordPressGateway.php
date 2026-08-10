<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Services\SideEffect;

use Omnichannel\Addons\WordPress\Services\WpSyncLeaseHeartbeat;
use Omnichannel\Addons\WordPress\Services\WordPressWriteReadinessGuard;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Sole WordPress mutating HTTP boundary for article/post side effects.
 * Every POST/PUT/PATCH/DELETE to WP must pass through here with an explicit context.
 */
final class WordPressGateway
{
    public function __construct(
        private readonly WordPressSideEffectGuard $guard,
        private readonly WordPressSideEffectLedger $ledger,
        private readonly WordPressWriteReadinessGuard $writeReadiness,
    ) {}

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $query
     */
    public function getJson(
        ?WordPressExecutionContext $context,
        string $operation,
        string $url,
        string $bearerToken,
        int $timeoutSeconds,
        array $query = [],
        ?int $articleId = null,
        ?int $siteId = null,
    ): Response {
        $this->guard->assertAllowed($context, $operation, [
            'article_id' => $articleId ?? $context?->articleId(),
            'site_id' => $siteId ?? $context?->siteId(),
            'url_host' => parse_url($url, PHP_URL_HOST),
            'url_path' => parse_url($url, PHP_URL_PATH),
        ]);

        assert($context instanceof WordPressExecutionContext);

        $attemptId = $this->ledger->begin($operation, $context);

        try {
            $response = Http::timeout($timeoutSeconds)
                ->acceptJson()
                ->withToken($bearerToken)
                ->get($url, $query);

            WpSyncLeaseHeartbeat::touch();

            if ($response->successful()) {
                $this->ledger->complete($attemptId, null);
            } else {
                $this->ledger->fail($attemptId, 'HTTP '.$response->status());
            }

            return $response;
        } catch (\Throwable $e) {
            WpSyncLeaseHeartbeat::touch();
            $this->ledger->fail($attemptId, $e->getMessage());
            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    public function postJson(
        ?WordPressExecutionContext $context,
        string $operation,
        string $url,
        string $bearerToken,
        array $body,
        int $timeoutSeconds,
        ?int $articleId = null,
        ?int $siteId = null,
    ): Response {
        $this->guard->assertAllowed($context, $operation, [
            'article_id' => $articleId ?? $context?->articleId(),
            'site_id' => $siteId ?? $context?->siteId(),
            'url_host' => parse_url($url, PHP_URL_HOST),
            'url_path' => parse_url($url, PHP_URL_PATH),
        ]);

        assert($context instanceof WordPressExecutionContext);
        $articleIdForGuard = (int) ($articleId ?? $context->articleId() ?? 0);
        if ($articleIdForGuard > 0) {
            $articleForGuard = SeoArticle::query()->find($articleIdForGuard);
            if ($articleForGuard instanceof SeoArticle) {
                $this->writeReadiness->assertCanWriteToWordPress($articleForGuard, $operation);
            }
        }

        $attemptId = $this->ledger->begin($operation, $context);

        try {
            $response = Http::timeout($timeoutSeconds)
                ->acceptJson()
                ->withToken($bearerToken)
                ->post($url, $body);

            \Omnichannel\Addons\WordPress\Services\WpSyncLeaseHeartbeat::touch();

            if ($response->successful()) {
                $decoded = $response->json();
                $remoteId = is_array($decoded) ? (int) ($decoded['wp_post_id'] ?? 0) : 0;
                $this->ledger->complete($attemptId, $remoteId > 0 ? $remoteId : null);
            } else {
                $this->ledger->fail($attemptId, 'HTTP '.$response->status());
            }

            return $response;
        } catch (\Throwable $e) {
            \Omnichannel\Addons\WordPress\Services\WpSyncLeaseHeartbeat::touch();
            $this->ledger->fail($attemptId, $e->getMessage());
            throw $e;
        }
    }
}
