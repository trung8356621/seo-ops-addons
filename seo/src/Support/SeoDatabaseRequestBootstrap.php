<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Support;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Illuminate\Http\Request;

final class SeoDatabaseRequestBootstrap
{
    public function __construct(
        private readonly SeoDatabaseConnectionService $databaseConnection,
    ) {}

    public function shouldBootstrap(Request $request): bool
    {
        if ($request->is('seo', 'seo/*', 'api/seo', 'api/seo/*')) {
            return true;
        }

        if ($request->is('admin', 'admin/*')) {
            return false;
        }

        if ($request->is('livewire/*')) {
            return $this->isSeoLivewireRequest($request);
        }

        if ($this->resolveHashId($request) !== null) {
            return true;
        }

        if (($this->resolveSiteId($request) ?? 0) > 0) {
            return true;
        }

        return $this->hasArticleReference($request);
    }

    public function bootstrap(Request $request): void
    {
        $hashId = $this->resolveHashId($request);

        if ($hashId !== null && SeoConnectionContext::isValidHashFormat($hashId)) {
            try {
                $this->databaseConnection->bootstrapByHash($hashId);
                SeoConnectionContext::applyUrlDefaults($hashId);

                return;
            } catch (\RuntimeException) {
                $siteId = $this->resolveSiteId($request);
                if ($siteId !== null && $siteId > 0) {
                    $this->databaseConnection->bootstrapBySiteId($siteId);

                    return;
                }

                if ($this->shouldUseLegacyFallback($request)) {
                    $this->databaseConnection->bootstrapLegacySharedConnection();
                }

                return;
            }
        }

        $siteId = $this->resolveSiteId($request);

        if ($siteId === null || $siteId <= 0) {
            $siteId = $this->resolveSiteIdFromArticleReference($request);
        }

        if ($siteId !== null && $siteId > 0) {
            $this->databaseConnection->bootstrapBySiteId($siteId);

            return;
        }

        if ($this->shouldUseLegacyFallback($request)) {
            $this->databaseConnection->bootstrapLegacySharedConnection();
        }
    }

    private function isSeoLivewireRequest(Request $request): bool
    {
        if ($this->refererIsAdminContext($request)) {
            return false;
        }

        if ($this->refererIsSeoContext($request)) {
            return true;
        }

        return trim((string) $request->header('X-SEO-Connection', '')) !== '';
    }

    private function refererIsAdminContext(Request $request): bool
    {
        $referer = (string) $request->headers->get('referer', '');
        if ($referer === '') {
            return false;
        }

        $path = parse_url($referer, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return false;
        }

        $path = '/'.ltrim($path, '/');

        return str_starts_with($path, '/admin/') || $path === '/admin';
    }

    private function refererIsSeoContext(Request $request): bool
    {
        $referer = (string) $request->headers->get('referer', '');
        if ($referer === '') {
            return false;
        }

        $path = parse_url($referer, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return false;
        }

        $path = '/'.ltrim($path, '/');

        return str_starts_with($path, '/seo/')
            || $path === '/seo'
            || str_starts_with($path, '/api/seo/');
    }

    private function shouldUseLegacyFallback(Request $request): bool
    {
        return $request->is('seo', 'seo/*', 'api/seo', 'api/seo/*');
    }

    private function resolveHashId(Request $request): ?string
    {
        $routeHash = $request->route('connection_hash');
        if (is_string($routeHash) && $routeHash !== '') {
            return $routeHash;
        }

        $headerHash = trim((string) $request->header('X-SEO-Connection', ''));
        if ($headerHash !== '') {
            return $headerHash;
        }

        if ($request->is('admin', 'admin/*')) {
            return null;
        }

        $sessionHash = session('seo_current_connection_hash');
        if (is_string($sessionHash) && $sessionHash !== '') {
            if ($request->is('livewire/*') && ! $this->refererIsSeoContext($request)) {
                return null;
            }

            return $sessionHash;
        }

        return null;
    }

    private function resolveSiteId(Request $request): ?int
    {
        $header = trim((string) $request->header('X-Site-ID', ''));
        if ($header !== '' && ctype_digit($header)) {
            return (int) $header;
        }

        $routeSiteId = $request->route('site_id') ?? $request->route('site');
        if (is_numeric($routeSiteId)) {
            return (int) $routeSiteId;
        }

        $inputSiteId = $request->input('site_id');
        if (is_numeric($inputSiteId)) {
            return (int) $inputSiteId;
        }

        if ($request->is('admin', 'admin/*')) {
            return null;
        }

        $globalSiteId = SeoAccessControl::globalSiteId();
        if ($globalSiteId !== null && $globalSiteId > 0) {
            return $globalSiteId;
        }

        return null;
    }

    private function hasArticleReference(Request $request): bool
    {
        if (is_numeric($request->route('article'))) {
            return true;
        }

        return is_numeric($request->input('article_id'));
    }

    private function resolveSiteIdFromArticleReference(Request $request): ?int
    {
        $articleId = $request->route('article');
        if (! is_numeric($articleId)) {
            $inputArticleId = $request->input('article_id');
            $articleId = is_numeric($inputArticleId) ? $inputArticleId : null;
        }

        if (! is_numeric($articleId) || (int) $articleId <= 0) {
            return null;
        }

        if ($this->shouldUseLegacyFallback($request)) {
            $this->databaseConnection->bootstrapLegacySharedConnection();
        }

        $siteId = SeoArticle::query()
            ->whereKey((int) $articleId)
            ->value('site_id');

        return is_numeric($siteId) && (int) $siteId > 0 ? (int) $siteId : null;
    }
}
