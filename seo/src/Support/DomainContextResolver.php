<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Support;

use App\Models\Site;
use Illuminate\Http\Request;

/**
 * Server-side Global Domain Context resolver.
 *
 * Client (URL + sessionStorage + last-used) is the source of truth.
 * This class only normalizes / validates keys the browser sends.
 */
final class DomainContextResolver
{
    private ?DomainContext $resolved = null;

    private bool $resolvedOnce = false;

    public function current(?Request $request = null): DomainContext
    {
        if ($this->resolvedOnce && $this->resolved instanceof DomainContext) {
            return $this->resolved;
        }

        $this->resolvedOnce = true;
        $this->resolved = $this->resolveFromRequest($request);

        return $this->resolved;
    }

    public function bind(DomainContext $context): DomainContext
    {
        $this->resolved = $context;
        $this->resolvedOnce = true;

        return $context;
    }

    public function reset(): void
    {
        $this->resolved = null;
        $this->resolvedOnce = false;
    }

    public function resolveKey(?string $key): DomainContext
    {
        $normalized = DomainContext::normalizeKey($key);
        if (DomainContext::isAllKey($normalized)) {
            return DomainContext::all();
        }

        $site = $this->findAccessibleSiteByKey($normalized);
        if (! $site instanceof Site) {
            return DomainContext::all();
        }

        return DomainContext::forSite((int) $site->getKey(), $this->domainKeyForSite($site));
    }

    public function contextForAccessibleSiteId(?int $siteId): DomainContext
    {
        if ($siteId === null || $siteId <= 0) {
            return DomainContext::all();
        }

        if (! SeoAccessControl::canAccessSite($siteId)) {
            return DomainContext::all();
        }

        $site = SeoAccessControl::accessibleSitesQuery()->whereKey($siteId)->first();
        if (! $site instanceof Site) {
            return DomainContext::all();
        }

        return DomainContext::forSite($siteId, $this->domainKeyForSite($site));
    }

    public function hasExplicitRequestKey(?Request $request = null): bool
    {
        $request ??= $this->currentRequest();
        if (! $request instanceof Request) {
            return false;
        }

        return $this->rawRequestKey($request) !== null;
    }

    public function domainKeyForSite(Site $site): string
    {
        $domain = strtolower(trim((string) ($site->domain ?? '')));

        return $domain !== '' ? $domain : (string) $site->getKey();
    }

    /**
     * @return list<string>
     */
    public function accessibleDomainKeys(): array
    {
        return SeoAccessControl::accessibleSitesQuery()
            ->orderBy('domain')
            ->get(['id', 'domain'])
            ->map(fn (Site $site): string => $this->domainKeyForSite($site))
            ->values()
            ->all();
    }

    private function resolveFromRequest(?Request $request = null): DomainContext
    {
        $request ??= $this->currentRequest();
        if (! $request instanceof Request) {
            return DomainContext::all();
        }

        $raw = $this->rawRequestKey($request);
        if ($raw === null) {
            return DomainContext::all();
        }

        return $this->resolveKey($raw);
    }

    private function rawRequestKey(Request $request): ?string
    {
        $query = $request->query(DomainContext::QUERY_KEY);
        if (is_string($query) && trim($query) !== '') {
            return $query;
        }

        $header = trim((string) $request->headers->get(DomainContext::HEADER_KEY, ''));
        if ($header !== '') {
            return $header;
        }

        return null;
    }

    private function findAccessibleSiteByKey(string $key): ?Site
    {
        if (ctype_digit($key)) {
            $byId = SeoAccessControl::accessibleSitesQuery()->whereKey((int) $key)->first();
            if ($byId instanceof Site) {
                return $byId;
            }
        }

        return SeoAccessControl::accessibleSitesQuery()
            ->whereRaw('LOWER(domain) = ?', [$key])
            ->first();
    }

    private function currentRequest(): ?Request
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = request();

        return $request instanceof Request ? $request : null;
    }
}
