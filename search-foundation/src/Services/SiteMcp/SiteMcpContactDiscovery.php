<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Services\SiteMcp;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Throwable;

/**
 * Deterministic contact discovery from HTML — NO AI.
 *
 * Sources: JSON-LD Organization/LocalBusiness/Corporation, tel:, mailto:, sameAs, direct social links.
 */
final class SiteMcpContactDiscovery
{
    /** @var list<string> */
    public const SOCIAL_NETWORKS = [
        'facebook',
        'instagram',
        'youtube',
        'linkedin',
        'tiktok',
        'zalo',
        'x',
        'twitter',
        'pinterest',
        'threads',
    ];

    /** @var array<string, float> */
    private const METHOD_CONFIDENCE = [
        'jsonld_organization' => 0.95,
        'jsonld_contact_point' => 0.93,
        'jsonld_sameAs' => 0.95,
        'tel_href' => 0.90,
        'mailto_href' => 0.90,
        'social_href' => 0.80,
        'official_slot' => 0.50,
        'official_list' => 0.50,
        'official_cta' => 0.50,
    ];

    /**
     * @return array{
     *     phones: list<array<string, mixed>>,
     *     emails: list<array<string, mixed>>,
     *     socials: list<array<string, mixed>>
     * }
     */
    public function parse(string $html, string $sourceUrl = ''): array
    {
        $phones = [];
        $emails = [];
        $socials = [];

        if (trim($html) === '') {
            return ['phones' => [], 'emails' => [], 'socials' => []];
        }

        try {
            $domParsed = $this->parseViaDom($html, $sourceUrl);
            $phones = $domParsed['phones'];
            $emails = $domParsed['emails'];
            $socials = $domParsed['socials'];
        } catch (Throwable) {
            $phones = [];
            $emails = [];
            $socials = [];
        }

        // Always merge regex fallback with keep-strongest — covers DOM gaps / malformed markup.
        $this->parseViaRegexFallback($html, $sourceUrl, $phones, $emails, $socials);

        return [
            'phones' => array_values($phones),
            'emails' => array_values($emails),
            'socials' => array_values($socials),
        ];
    }

    /**
     * @return array{
     *     phones: array<string, array<string, mixed>>,
     *     emails: array<string, array<string, mixed>>,
     *     socials: array<string, array<string, mixed>>
     * }
     */
    private function parseViaDom(string $html, string $sourceUrl): array
    {
        $phones = [];
        $emails = [];
        $socials = [];

        $previous = libxml_use_internal_errors(true);
        try {
            $dom = new DOMDocument;
            $loaded = @$dom->loadHTML(
                '<?xml encoding="UTF-8">'.$html,
                LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET,
            );
            if (! $loaded) {
                throw new \RuntimeException('DOM loadHTML failed');
            }

            $xpath = new DOMXPath($dom);

            foreach ($xpath->query('//script') ?: [] as $script) {
                if (! $script instanceof DOMElement) {
                    continue;
                }
                $type = mb_strtolower(trim($script->getAttribute('type')));
                if (! str_contains($type, 'ld+json')) {
                    continue;
                }
                $raw = trim($script->textContent ?? '');
                if ($raw === '') {
                    continue;
                }
                $decoded = json_decode(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5), true);
                if (! is_array($decoded)) {
                    continue;
                }
                if (array_is_list($decoded)) {
                    foreach ($decoded as $item) {
                        if (is_array($item)) {
                            $this->collectFromJsonLd($item, $sourceUrl, $phones, $emails, $socials);
                        }
                    }
                    continue;
                }
                $this->collectFromJsonLd($decoded, $sourceUrl, $phones, $emails, $socials);
            }

            foreach ($xpath->query('//a[@href]') ?: [] as $anchor) {
                if (! $anchor instanceof DOMElement) {
                    continue;
                }
                $href = trim($anchor->getAttribute('href'));
                if ($href === '') {
                    continue;
                }
                $lower = mb_strtolower($href);
                if (str_starts_with($lower, 'tel:')) {
                    $this->pushPhone($phones, substr($href, 4), $sourceUrl, 'tel_href', self::METHOD_CONFIDENCE['tel_href']);
                    continue;
                }
                if (str_starts_with($lower, 'mailto:')) {
                    $email = preg_replace('/\?.*$/', '', substr($href, 7)) ?? substr($href, 7);
                    $this->pushEmail($emails, urldecode($email), $sourceUrl, 'mailto_href', self::METHOD_CONFIDENCE['mailto_href']);
                    continue;
                }
                $network = $this->detectSocialNetwork($href);
                if ($network !== null) {
                    $this->pushSocial($socials, $network, $href, $sourceUrl, 'social_href', self::METHOD_CONFIDENCE['social_href']);
                }
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return compact('phones', 'emails', 'socials');
    }

    /**
     * @param  array<string, array<string, mixed>>  $phones
     * @param  array<string, array<string, mixed>>  $emails
     * @param  array<string, array<string, mixed>>  $socials
     */
    private function parseViaRegexFallback(
        string $html,
        string $sourceUrl,
        array &$phones,
        array &$emails,
        array &$socials,
    ): void {
        foreach ($this->extractJsonLdBlocks($html) as $block) {
            $this->collectFromJsonLd($block, $sourceUrl, $phones, $emails, $socials);
        }

        if (preg_match_all('/href\s*=\s*["\']\s*tel:([^"\']+)["\']/iu', $html, $m) > 0) {
            foreach ($m[1] as $raw) {
                $this->pushPhone($phones, (string) $raw, $sourceUrl, 'tel_href', self::METHOD_CONFIDENCE['tel_href']);
            }
        }

        if (preg_match_all('/href\s*=\s*["\']\s*mailto:([^"\']+)["\']/iu', $html, $m) > 0) {
            foreach ($m[1] as $raw) {
                $email = preg_replace('/\?.*$/', '', (string) $raw) ?? (string) $raw;
                $this->pushEmail($emails, urldecode($email), $sourceUrl, 'mailto_href', self::METHOD_CONFIDENCE['mailto_href']);
            }
        }

        if (preg_match_all('/href\s*=\s*["\']([^"\']+)["\']/iu', $html, $m) > 0) {
            foreach ($m[1] as $href) {
                $network = $this->detectSocialNetwork((string) $href);
                if ($network !== null) {
                    $this->pushSocial($socials, $network, (string) $href, $sourceUrl, 'social_href', self::METHOD_CONFIDENCE['social_href']);
                }
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function extractJsonLdBlocks(string $html): array
    {
        $blocks = [];
        // Attributes in any order; case-insensitive type; whitespace around value.
        if (preg_match_all(
            '/<script\b([^>]*)>(.*?)<\/script>/is',
            $html,
            $matches,
            PREG_SET_ORDER
        ) === 0) {
            return [];
        }

        foreach ($matches as $match) {
            $attrs = (string) ($match[1] ?? '');
            if (preg_match('/type\s*=\s*["\']\s*application\/ld\+json\s*["\']/i', $attrs) !== 1
                && preg_match('/type\s*=\s*application\/ld\+json\b/i', $attrs) !== 1) {
                continue;
            }
            $raw = trim((string) ($match[2] ?? ''));
            if ($raw === '') {
                continue;
            }
            $decoded = json_decode(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5), true);
            if (! is_array($decoded)) {
                continue; // invalid block — skip, keep others
            }
            if (array_is_list($decoded)) {
                foreach ($decoded as $item) {
                    if (is_array($item)) {
                        $blocks[] = $item;
                    }
                }
                continue;
            }
            $blocks[] = $decoded;
        }

        return $blocks;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, array<string, mixed>>  $phones
     * @param  array<string, array<string, mixed>>  $emails
     * @param  array<string, array<string, mixed>>  $socials
     */
    private function collectFromJsonLd(
        array $node,
        string $sourceUrl,
        array &$phones,
        array &$emails,
        array &$socials,
    ): void {
        $types = $this->jsonLdTypes($node);
        $isOrg = $types !== [] && (
            in_array('Organization', $types, true)
            || in_array('LocalBusiness', $types, true)
            || in_array('Corporation', $types, true)
        );

        if ($isOrg) {
            foreach ($this->stringList($node['telephone'] ?? null) as $phone) {
                $this->pushPhone($phones, $phone, $sourceUrl, 'jsonld_organization', self::METHOD_CONFIDENCE['jsonld_organization']);
            }
            foreach ($this->stringList($node['email'] ?? null) as $email) {
                $this->pushEmail($emails, $email, $sourceUrl, 'jsonld_organization', self::METHOD_CONFIDENCE['jsonld_organization']);
            }
            foreach ($this->stringList($node['sameAs'] ?? null) as $url) {
                $network = $this->detectSocialNetwork($url);
                if ($network !== null) {
                    $this->pushSocial($socials, $network, $url, $sourceUrl, 'jsonld_sameAs', self::METHOD_CONFIDENCE['jsonld_sameAs']);
                }
            }
        }

        // contactPoint may appear on Organization or standalone.
        $contactPoints = $node['contactPoint'] ?? null;
        if (is_array($contactPoints)) {
            $points = array_is_list($contactPoints) ? $contactPoints : [$contactPoints];
            foreach ($points as $point) {
                if (! is_array($point)) {
                    continue;
                }
                foreach ($this->stringList($point['telephone'] ?? null) as $phone) {
                    $this->pushPhone($phones, $phone, $sourceUrl, 'jsonld_contact_point', self::METHOD_CONFIDENCE['jsonld_contact_point']);
                }
                foreach ($this->stringList($point['email'] ?? null) as $email) {
                    $this->pushEmail($emails, $email, $sourceUrl, 'jsonld_contact_point', self::METHOD_CONFIDENCE['jsonld_contact_point']);
                }
            }
        }

        foreach ([
            '@graph',
            'department',
            'subOrganization',
            'parentOrganization',
            'publisher',
            'provider',
        ] as $nestedKey) {
            $nested = $node[$nestedKey] ?? null;
            if (! is_array($nested)) {
                continue;
            }
            $items = array_is_list($nested) ? $nested : [$nested];
            foreach ($items as $child) {
                if (is_array($child)) {
                    $this->collectFromJsonLd($child, $sourceUrl, $phones, $emails, $socials);
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $node
     * @return list<string>
     */
    private function jsonLdTypes(array $node): array
    {
        $type = $node['@type'] ?? null;
        if (is_string($type) && $type !== '') {
            // Strip schema.org URL prefix if present.
            $type = preg_replace('#^https?://schema\.org/#i', '', $type) ?? $type;

            return [trim($type)];
        }
        if (! is_array($type)) {
            return [];
        }
        $out = [];
        foreach ($type as $item) {
            if (is_string($item) && $item !== '') {
                $item = preg_replace('#^https?://schema\.org/#i', '', $item) ?? $item;
                $out[] = trim($item);
            }
        }

        return $out;
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = trim($value);

            return $value !== '' ? [$value] : [];
        }
        if (! is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            }
        }

        return $out;
    }

    /**
     * @param  array<string, array<string, mixed>>  $phones
     */
    private function pushPhone(array &$phones, string $raw, string $sourceUrl, string $method, float $confidence): void
    {
        $value = $this->normalizePhone($raw);
        if ($value === '') {
            return;
        }
        $key = preg_replace('/\D+/', '', $value) ?? mb_strtolower($value);
        if ($key === '') {
            $key = mb_strtolower($value);
        }
        $this->keepStrongest($phones, $key, [
            'value' => $value,
            'source_url' => $sourceUrl,
            'source_method' => $method,
            'confidence' => $confidence,
        ]);
    }

    /**
     * @param  array<string, array<string, mixed>>  $emails
     */
    private function pushEmail(array &$emails, string $raw, string $sourceUrl, string $method, float $confidence): void
    {
        $value = mb_strtolower(trim($raw));
        if ($value === '' || ! filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        $this->keepStrongest($emails, $value, [
            'value' => $value,
            'source_url' => $sourceUrl,
            'source_method' => $method,
            'confidence' => $confidence,
        ]);
    }

    /**
     * @param  array<string, array<string, mixed>>  $socials
     */
    private function pushSocial(
        array &$socials,
        string $network,
        string $url,
        string $sourceUrl,
        string $method,
        float $confidence,
    ): void {
        $url = trim($url);
        $network = mb_strtolower(trim($network));
        if ($url === '' || $network === '') {
            return;
        }
        if ($network === 'twitter') {
            $network = 'x';
        }
        $key = $network.'|'.mb_strtolower(rtrim($url, '/'));
        $this->keepStrongest($socials, $key, [
            'network' => $network,
            'url' => $url,
            'value' => $url,
            'source_url' => $sourceUrl,
            'source_method' => $method,
            'confidence' => $confidence,
        ]);
    }

    /**
     * @param  array<string, array<string, mixed>>  $bucket
     * @param  array<string, mixed>  $row
     */
    private function keepStrongest(array &$bucket, string $key, array $row): void
    {
        if (! isset($bucket[$key])) {
            $bucket[$key] = $row;

            return;
        }
        $existing = (float) ($bucket[$key]['confidence'] ?? 0);
        $incoming = (float) ($row['confidence'] ?? 0);
        if ($incoming > $existing) {
            $bucket[$key] = $row;
        }
    }

    private function normalizePhone(string $raw): string
    {
        $raw = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5);
        $raw = urldecode($raw);
        $raw = preg_replace('/^tel:/i', '', $raw) ?? $raw;
        // Drop query string / fragments.
        $raw = preg_replace('/[?#].*$/', '', $raw) ?? $raw;
        // NBSP and odd whitespace.
        $raw = str_replace(["\xc2\xa0", "\u{00A0}"], ' ', $raw);
        $raw = trim(preg_replace('/\s+/u', ' ', $raw) ?? $raw);
        // Strip common extension suffixes for validation, keep base.
        $raw = preg_replace('/\s*(?:ext\.?|extension|x)\s*\d+\s*$/iu', '', $raw) ?? $raw;
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        // Must contain enough digits.
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if (strlen($digits) < 7) {
            return '';
        }

        return $raw;
    }

    private function detectSocialNetwork(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || ! preg_match('#^https?://#i', $url)) {
            return null;
        }

        $host = mb_strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        $path = mb_strtolower((string) (parse_url($url, PHP_URL_PATH) ?? ''));
        if ($host === '') {
            return null;
        }

        // Reject share/intent/action URLs.
        if ($this->isShareOrIntentUrl($host, $path)) {
            return null;
        }

        $network = $this->matchSocialHost($host);

        return $network;
    }

    private function isShareOrIntentUrl(string $host, string $path): bool
    {
        $path = '/'.ltrim($path, '/');

        return (bool) preg_match(
            '#/(sharer|shareArticle|intent|share\.php|dialog/share)(/|\?|$)#i',
            $path
        );
    }

    private function matchSocialHost(string $host): ?string
    {
        // Exact host or subdomain only — reject notfacebook.com / facebook.com.evil.org
        $map = [
            'facebook.com' => 'facebook',
            'fb.com' => 'facebook',
            'instagram.com' => 'instagram',
            'youtube.com' => 'youtube',
            'youtu.be' => 'youtube',
            'linkedin.com' => 'linkedin',
            'tiktok.com' => 'tiktok',
            'zalo.me' => 'zalo',
            'zaloapp.com' => 'zalo',
            'twitter.com' => 'x',
            'x.com' => 'x',
            'pinterest.com' => 'pinterest',
            'threads.net' => 'threads',
        ];

        foreach ($map as $base => $network) {
            if ($host === $base || str_ends_with($host, '.'.$base)) {
                return $network;
            }
        }

        // Regional pinterest.*.
        if (preg_match('/(^|\.)pinterest\.[a-z.]+$/', $host) === 1) {
            return 'pinterest';
        }

        return null;
    }
}
