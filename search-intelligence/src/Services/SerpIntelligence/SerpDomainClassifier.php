<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence;

use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Enums\SerpDomainType;

/**
 * Classify domain — manual override wins.
 */
final class SerpDomainClassifier
{
    public function __construct(
        private readonly SerpOwnDomainDetector $ownDomainDetector,
    ) {}

    /**
     * @param  array<string, mixed>  $context  manual_type?, site_domains?, competitor_domains?
     */
    public function classify(string $domain, array $context = []): SerpDomainType
    {
        $manual = $context['manual_type'] ?? null;
        if (is_string($manual) && $manual !== '') {
            $enum = SerpDomainType::tryFrom(mb_strtolower($manual, 'UTF-8'));

            return $enum ?? SerpDomainType::Other;
        }

        $normalized = mb_strtolower(trim($domain), 'UTF-8');
        $siteDomains = is_array($context['site_domains'] ?? null) ? $context['site_domains'] : [];
        if ($this->ownDomainDetector->isOwnDomain($normalized, $siteDomains)) {
            return SerpDomainType::Own;
        }

        $competitors = is_array($context['competitor_domains'] ?? null) ? $context['competitor_domains'] : [];
        foreach ($competitors as $competitor) {
            if (! is_string($competitor)) {
                continue;
            }
            if ($normalized === mb_strtolower(trim($competitor), 'UTF-8')) {
                return SerpDomainType::DirectCompetitor;
            }
        }

        foreach ($this->domainRules() as $rule) {
            if ($this->matchesRule($normalized, $rule['patterns'])) {
                return $rule['type'];
            }
        }

        return SerpDomainType::Other;
    }

    /**
     * @param  list<string>  $patterns
     */
    private function matchesRule(string $domain, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (! is_string($pattern) || $pattern === '') {
                continue;
            }

            $pattern = mb_strtolower($pattern, 'UTF-8');
            if ($domain === $pattern || str_ends_with($domain, '.'.$pattern)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{type: SerpDomainType, patterns: list<string>}> */
    private function domainRules(): array
    {
        $defaults = [
            ['type' => SerpDomainType::Marketplace, 'patterns' => ['amazon.', 'shopee.', 'lazada.', 'tiki.vn', 'ebay.', 'etsy.com']],
            ['type' => SerpDomainType::Forum, 'patterns' => ['reddit.com', 'voz.vn', 'quora.com', 'stackoverflow.com']],
            ['type' => SerpDomainType::Social, 'patterns' => ['facebook.com', 'instagram.com', 'linkedin.com', 'twitter.com', 'x.com', 'tiktok.com']],
            ['type' => SerpDomainType::VideoPlatform, 'patterns' => ['youtube.com', 'youtu.be', 'vimeo.com']],
            ['type' => SerpDomainType::Publisher, 'patterns' => ['medium.com', 'wiki', 'wikipedia.org', 'news', 'baomoi.com', 'vnexpress.net']],
            ['type' => SerpDomainType::Government, 'patterns' => ['.gov', '.gov.vn']],
            ['type' => SerpDomainType::Education, 'patterns' => ['.edu', '.ac.vn', '.edu.vn']],
        ];

        if (! function_exists('config')) {
            return $defaults;
        }

        try {
            $custom = config('seo-content-ai.serp_intelligence.domain_rules', []);
            if (! is_array($custom)) {
                return $defaults;
            }

            foreach ($custom as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $type = SerpDomainType::tryFrom((string) ($row['type'] ?? ''));
                $patterns = is_array($row['patterns'] ?? null) ? $row['patterns'] : [];
                if ($type !== null && $patterns !== []) {
                    $defaults[] = ['type' => $type, 'patterns' => array_map('strval', $patterns)];
                }
            }
        } catch (\Throwable) {
            // keep defaults
        }

        return $defaults;
    }
}
