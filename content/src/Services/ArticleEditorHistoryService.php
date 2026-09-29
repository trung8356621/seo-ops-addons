<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use App\Models\WpOption;

class ArticleEditorHistoryService
{
    public const OPTION_KEY = 'seo_article_editor_settings';

    public const RECONCILIATION_FINGERPRINT_OPTION_KEY = 'seo_link_classification_trusted_domains_fingerprint';

    public const DEFAULT_HISTORY_STEP = 20;

    /** Local browser draft interval (localStorage) — not server/database autosave. */
    public const DEFAULT_AUTOSAVE_INTERVAL_SECONDS = 2;

    /** @var list<string> */
    public const DEFAULT_WIKI_TRUST_DOMAINS = [
        'wikipedia.org',
        '*.gov',
        '*.edu',
    ];

    /**
     * @return array{
     *     history_step: int,
     *     autosave_interval_seconds: int,
     *     wiki_trust_domains: list<string>
     * }
     */
    public function getSettings(): array
    {
        $data = WpOption::get(self::OPTION_KEY, []);

        if (! is_array($data)) {
            return [
                'history_step' => self::DEFAULT_HISTORY_STEP,
                'autosave_interval_seconds' => self::DEFAULT_AUTOSAVE_INTERVAL_SECONDS,
                'wiki_trust_domains' => self::DEFAULT_WIKI_TRUST_DOMAINS,
            ];
        }

        $steps = (int) ($data['history_step'] ?? self::DEFAULT_HISTORY_STEP);
        $autosave = (int) ($data['autosave_interval_seconds'] ?? self::DEFAULT_AUTOSAVE_INTERVAL_SECONDS);

        return [
            'history_step' => max(1, min(100, $steps)),
            'autosave_interval_seconds' => max(0, min(30, $autosave)),
            'wiki_trust_domains' => $this->normalizeWikiTrustDomains($data['wiki_trust_domains'] ?? null),
        ];
    }

    public function getHistoryStep(): int
    {
        return $this->getSettings()['history_step'];
    }

    /**
     * @return list<string>
     */
    public function getWikiTrustDomains(): array
    {
        return $this->getSettings()['wiki_trust_domains'];
    }

    /**
     * @param  list<string>|null  $domains
     */
    public function domainsToTextarea(?array $domains): string
    {
        if (! is_array($domains) || $domains === []) {
            return implode("\n", self::DEFAULT_WIKI_TRUST_DOMAINS);
        }

        return implode("\n", $domains);
    }

    /**
     * @return list<string>
     */
    public function textareaToDomains(string $textarea): array
    {
        $lines = preg_split('/\R/u', $textarea) ?: [];

        return $this->normalizeWikiTrustDomains($lines);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function saveSettings(array $settings): void
    {
        $current = $this->getSettings();
        $steps = (int) ($settings['history_step'] ?? $current['history_step']);
        $autosave = (int) ($settings['autosave_interval_seconds'] ?? $current['autosave_interval_seconds']);
        $hasDomainPayload = array_key_exists('wiki_trust_domains', $settings);
        $wikiTrustDomains = $hasDomainPayload
            ? $this->normalizeWikiTrustDomains($settings['wiki_trust_domains'])
            : $current['wiki_trust_domains'];

        $domainsChanged = false;
        if ($hasDomainPayload) {
            $oldDomains = $current['wiki_trust_domains'];
            sort($oldDomains, SORT_STRING);
            $newDomains = $wikiTrustDomains;
            sort($newDomains, SORT_STRING);
            $domainsChanged = (array_values($oldDomains) !== array_values($newDomains));
        }

        $storedFingerprint = $this->getReconciliationFingerprint();
        $currentFingerprint = $this->computeTrustedDomainsFingerprint($wikiTrustDomains);

        $needsReconciliation = $hasDomainPayload && (
            $domainsChanged
            || $storedFingerprint === null
            || $storedFingerprint !== $currentFingerprint
        );

        WpOption::set(self::OPTION_KEY, [
            'history_step' => max(1, min(100, $steps)),
            'autosave_interval_seconds' => max(0, min(30, $autosave)),
            'wiki_trust_domains' => $wikiTrustDomains,
        ], 'no');

        if ($needsReconciliation) {
            app(\Omnichannel\Addons\SearchFoundation\Services\LinkClassificationReconciliationService::class)->reconcile();
            $this->setReconciliationFingerprint($currentFingerprint);
        }
    }

    /**
     * Deterministic canonical fingerprint representing only classification-affecting trusted domain configuration.
     * Raw form input variations, trailing slashes, scheme, and ordering are normalized before hashing.
     */
    public function computeTrustedDomainsFingerprint(mixed $domains): string
    {
        $normalized = $this->normalizeWikiTrustDomains($domains);
        sort($normalized, SORT_STRING);
        $canonical = array_values(array_unique($normalized));

        return hash('sha256', (string) json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function getReconciliationFingerprint(): ?string
    {
        $val = WpOption::get(self::RECONCILIATION_FINGERPRINT_OPTION_KEY);

        return is_string($val) && $val !== '' ? $val : null;
    }

    public function setReconciliationFingerprint(string $fingerprint): void
    {
        WpOption::set(self::RECONCILIATION_FINGERPRINT_OPTION_KEY, $fingerprint, 'no');
    }

    /**
     * @param  mixed  $raw
     * @return list<string>
     */
    public function normalizeWikiTrustDomains(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = preg_split('/\R/u', $raw) ?: [];
        }

        if (! is_array($raw)) {
            return self::DEFAULT_WIKI_TRUST_DOMAINS;
        }

        $domains = \Omnichannel\Addons\SearchFoundation\Support\DomainHostNormalizer::normalizeTrustedPatternList($raw);

        return $domains !== [] ? $domains : self::DEFAULT_WIKI_TRUST_DOMAINS;
    }
}
