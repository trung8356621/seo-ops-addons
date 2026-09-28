<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Enums;

enum SeoLinkMapType: string
{
    case Internal = 'internal';
    case External = 'external';
    case WikiTrust = 'wiki_trust';
    case ManagedCrossSite = 'managed_cross_site';
    case Social = 'social';
    case Contact = 'contact';
    case NeedsReview = 'needs_review';

    /**
     * Whether this link type carries semantic value for keyword relationship graphs.
     * Social and Contact CTA links are excluded — they are not content relationships.
     */
    public function isSemanticEligible(): bool
    {
        return match ($this) {
            self::Internal,
            self::ManagedCrossSite,
            self::WikiTrust,
            self::External,
            self::NeedsReview => true,
            self::Social,
            self::Contact    => false,
        };
    }

    /**
     * Whether this type represents a CTA / non-content destination.
     */
    public function isCta(): bool
    {
        return match ($this) {
            self::Social,
            self::Contact => true,
            default       => false,
        };
    }

    /**
     * Human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Internal          => 'Internal',
            self::External          => 'External',
            self::WikiTrust         => 'Wiki / Reference',
            self::ManagedCrossSite  => 'Managed Cross-Site',
            self::Social            => 'Social / CTA',
            self::Contact           => 'Contact / CTA',
            self::NeedsReview       => 'Needs Review',
        };
    }

    /**
     * Safe fromValue that falls back to External when the stored value is not recognised.
     */
    public static function fromValue(string $value): self
    {
        return self::tryFrom($value) ?? self::External;
    }
}
