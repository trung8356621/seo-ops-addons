<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Support;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Seo\Enums\SeoLinkMapDestinationKind;
use Omnichannel\Addons\Seo\Enums\SeoLinkMapType;

/**
 * Single source of truth for link destination classification.
 *
 * Classification order:
 *   1. Normalize href / scheme
 *   2. Identify contact / CTA destination (by scheme)
 *   3. Identify social CTA destination (by host)
 *   4. Resolve same managed site → Internal
 *   5. Resolve another managed site → ManagedCrossSite
 *   6. Identify trusted / reference destination → WikiTrust
 *   7. Remaining unknown host → NeedsReview
 */
final class LinkDestinationClassifier
{
    /**
     * Known social domains (normalized, without www).
     *
     * @var list<string>
     */
    private const SOCIAL_DOMAINS = [
        'facebook.com',
        'fb.com',
        'twitter.com',
        'x.com',
        'instagram.com',
        'youtube.com',
        'tiktok.com',
        'threads.net',
        'pinterest.com',
        'linkedin.com',
        'snapchat.com',
        'reddit.com',
        'zalo.me',      // Vietnamese social
        't.me',         // Telegram
        'telegram.org',
        'wa.me',        // WhatsApp short URL
    ];

    /**
     * Known contact/CTA URI schemes.
     *
     * @var list<string>
     */
    private const CONTACT_SCHEMES = ['tel', 'mailto', 'sms', 'whatsapp', 'viber', 'callto'];

    /**
     * Classify a single href into a full destination descriptor.
     *
     * @param  string          $href                  The raw href value from the anchor.
     * @param  int             $sourceSiteId          The site_id of the article containing this link.
     * @param  SeoArticle|null $resolvedTargetArticle The resolved managed article, if any.
     * @param  bool            $isTrustHost           Whether the host was already identified as trusted/wiki.
     *
     * @return array{
     *   link_type: SeoLinkMapType,
     *   destination_kind: SeoLinkMapDestinationKind,
     *   target_site_id: int|null,
     *   is_semantic_eligible: bool,
     *   host: string,
     *   is_cta: bool,
     * }
     */
    public static function classify(
        string $href,
        int $sourceSiteId,
        ?SeoArticle $resolvedTargetArticle = null,
        bool $isTrustHost = false,
        ?int $targetSiteId = null,
    ): array {
        // --- Step 1: contact scheme check (tel:, mailto:, etc.) ---
        if (self::isContactScheme($href)) {
            return self::result(
                type: SeoLinkMapType::Contact,
                kind: SeoLinkMapDestinationKind::Contact,
                host: self::extractScheme($href),
                targetSiteId: null,
            );
        }

        $host = SeoLinkMapLinkTypeClassifier::resolveHost($href);

        // --- Step 2: social host check ---
        if (self::isSocialHost($host)) {
            return self::result(
                type: SeoLinkMapType::Social,
                kind: SeoLinkMapDestinationKind::Social,
                host: $host,
                targetSiteId: null,
            );
        }

        // --- Step 3 & 4: managed article or managed domain (same-site or cross-site) ---
        if ($resolvedTargetArticle !== null) {
            $targetSiteId = (int) ($resolvedTargetArticle->site_id ?? 0);
        } elseif ($targetSiteId === null && $host !== '') {
            $targetSiteId = self::resolveManagedSiteId($host);
        }

        if ($targetSiteId !== null && $targetSiteId > 0) {
            if ($targetSiteId === $sourceSiteId) {
                return self::result(
                    type: SeoLinkMapType::Internal,
                    kind: SeoLinkMapDestinationKind::Content,
                    host: $host,
                    targetSiteId: null,
                );
            }

            return self::result(
                type: SeoLinkMapType::ManagedCrossSite,
                kind: SeoLinkMapDestinationKind::Content,
                host: $host,
                targetSiteId: $targetSiteId,
            );
        }

        // --- Step 5: trusted / wiki host ---
        if ($isTrustHost) {
            return self::result(
                type: SeoLinkMapType::WikiTrust,
                kind: SeoLinkMapDestinationKind::Reference,
                host: $host,
                targetSiteId: null,
            );
        }

        // --- Step 6: unmanaged unknown external host → NeedsReview ---
        return self::result(
            type: SeoLinkMapType::NeedsReview,
            kind: SeoLinkMapDestinationKind::Other,
            host: $host,
            targetSiteId: null,
        );
    }

    public static function resolveManagedSiteId(string $host): ?int
    {
        $normalized = SeoLinkMapLinkTypeClassifier::normalizeDomainHost($host);
        if ($normalized === '') {
            return null;
        }

        if (\Illuminate\Support\Facades\Schema::hasTable('sites')) {
            try {
                $sites = \App\Models\Site::query()->get(['id', 'domain']);
                foreach ($sites as $site) {
                    if ($site instanceof \App\Models\Site) {
                        $siteHost = SeoLinkMapLinkTypeClassifier::normalizeDomainHost((string) $site->domain);
                        if ($siteHost !== '' && ($siteHost === $normalized || $normalized === 'www.'.$siteHost || 'www.'.$normalized === $siteHost)) {
                            return (int) $site->id;
                        }
                    }
                }
            } catch (\Throwable) {
            }
        }

        return null;
    }

    /**
     * Returns true when the host belongs to a known social network.
     */
    public static function isSocialHost(string $host): bool
    {
        $normalized = SeoLinkMapLinkTypeClassifier::normalizeDomainHost($host);

        if ($normalized === '') {
            return false;
        }

        foreach (self::SOCIAL_DOMAINS as $social) {
            // Exact match or subdomain match (e.g. m.facebook.com)
            if ($normalized === $social || str_ends_with($normalized, '.'.$social)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns true when the href starts with a contact/CTA URI scheme.
     */
    public static function isContactScheme(string $href): bool
    {
        $href = trim(strtolower($href));

        foreach (self::CONTACT_SCHEMES as $scheme) {
            if (str_starts_with($href, $scheme.':')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Delegate: whether this link_type carries semantic graph value.
     */
    public static function isSemanticEligible(SeoLinkMapType $type): bool
    {
        return $type->isSemanticEligible();
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * @return array{
     *   link_type: SeoLinkMapType,
     *   destination_kind: SeoLinkMapDestinationKind,
     *   target_site_id: int|null,
     *   is_semantic_eligible: bool,
     *   host: string,
     *   is_cta: bool,
     * }
     */
    private static function result(
        SeoLinkMapType $type,
        SeoLinkMapDestinationKind $kind,
        string $host,
        ?int $targetSiteId,
    ): array {
        return [
            'link_type'           => $type,
            'destination_kind'    => $kind,
            'target_site_id'      => $targetSiteId,
            'is_semantic_eligible' => $type->isSemanticEligible(),
            'host'                => $host,
            'is_cta'              => $type->isCta(),
        ];
    }

    /**
     * Extracts only the scheme portion of a URI (e.g. "tel" from "tel:+1234").
     */
    private static function extractScheme(string $href): string
    {
        $colon = strpos($href, ':');

        return $colon !== false ? strtolower(substr($href, 0, $colon)) : '';
    }
}
