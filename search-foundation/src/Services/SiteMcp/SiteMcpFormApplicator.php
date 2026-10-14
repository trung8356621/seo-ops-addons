<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Services\SiteMcp;

use Illuminate\Support\Str;
use Omnichannel\Addons\AiPrompt\Services\SiteDomainPromptContextService;

/**
 * Maps a presented Site MCP preview onto Domain Edit form state.
 * Does not save, sync WordPress, or touch unmapped fields.
 */
final class SiteMcpFormApplicator
{
    public function __construct(
        private readonly SiteDomainPromptContextService $promptContext = new SiteDomainPromptContextService,
    ) {}

    /**
     * @param  array<string, mixed>  $preview
     * @param  array<string, mixed>  $formState
     * @return array{state: array<string, mixed>, applied: list<string>}
     */
    public function plan(array $preview, array $formState): array
    {
        if (($preview['has_draft'] ?? false) !== true) {
            return ['state' => $formState, 'applied' => []];
        }

        $next = $formState;
        $prompt = is_array($next['promptContext'] ?? null) ? $next['promptContext'] : [];
        $site = is_array($preview['site'] ?? null) ? $preview['site'] : [];
        $contact = is_array($preview['contact'] ?? null) ? $preview['contact'] : [];
        $applied = [];

        $identity = $this->promptContext->clampCompanyShortIdentity(
            (string) ($site['company_short_identity'] ?? ''),
        );
        if ($identity !== '') {
            $prompt['company_short_identity'] = $identity;
            $applied[] = 'company_short_identity';
        }

        $description = trim((string) ($site['short_description'] ?? ''));
        if ($description !== ''
            && mb_strlen($description) <= 8000
            && $this->promptContext->countWords($description) <= SiteDomainPromptContextService::MAX_SHORT_DESCRIPTION_WORDS
        ) {
            $prompt['short_description'] = $description;
            $applied[] = 'short_description';
        }

        $websiteType = $this->websiteType((string) ($site['website_type'] ?? ''));
        if ($websiteType !== null) {
            $next['seo_domain_type'] = $websiteType;
            $applied[] = 'seo_domain_type';
        }

        $phones = $this->phones($contact['phones'] ?? null);
        if ($phones !== []) {
            $prompt['phones'] = $this->repeaterState($phones);
            $applied[] = 'phones';
        }

        $emails = $this->emails($contact['emails'] ?? null);
        if ($emails !== []) {
            $prompt['emails'] = $this->repeaterState($emails);
            $applied[] = 'emails';
        }

        $socials = $this->socials($contact['socials'] ?? null);
        if ($socials !== []) {
            $prompt['socials'] = $this->repeaterState($socials);
            $applied[] = 'socials';
        }

        $next['promptContext'] = $prompt;

        return ['state' => $next, 'applied' => $applied];
    }

    private function websiteType(string $raw): ?string
    {
        return match (mb_strtolower(trim($raw))) {
            'news' => 'news',
            'production', 'manufacturer' => 'production',
            'e-commerce', 'ecommerce', 'e_commerce' => 'e-commerce',
            default => null,
        };
    }

    /**
     * @return list<array{value: string}>
     */
    private function phones(mixed $raw): array
    {
        $phones = [];
        foreach ($this->promptContext->normalizeContactValueList($raw) as $row) {
            $value = trim((string) ($row['value'] ?? ''));
            if ($value === '' || mb_strlen($value) > 50) {
                continue;
            }
            $phones[] = ['value' => $value];
        }

        return $phones;
    }

    /**
     * @return list<array{value: string}>
     */
    private function emails(mixed $raw): array
    {
        $emails = [];
        foreach ($this->promptContext->normalizeContactValueList($raw) as $row) {
            $value = trim((string) ($row['value'] ?? ''));
            if ($value === '' || mb_strlen($value) > 255 || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }
            $emails[] = ['value' => $value];
        }

        return $emails;
    }

    /**
     * @return list<array{network: string, url: string}>
     */
    private function socials(mixed $raw): array
    {
        $allowed = array_fill_keys(SiteMcpContactDiscovery::SOCIAL_NETWORKS, true);
        $socials = [];
        foreach ($this->promptContext->normalizeSocialList($raw) as $row) {
            $network = (string) ($row['network'] ?? '');
            $url = trim((string) ($row['url'] ?? ''));
            if (! isset($allowed[$network]) || $url === '' || mb_strlen($url) > 2000) {
                continue;
            }
            if (filter_var($url, FILTER_VALIDATE_URL) === false) {
                continue;
            }
            $socials[] = ['network' => $network, 'url' => $url];
        }

        return $socials;
    }

    /**
     * @param  list<array<string, string>>  $items
     * @return array<string, array<string, string>>
     */
    private function repeaterState(array $items): array
    {
        $state = [];
        foreach ($items as $item) {
            $state[(string) Str::uuid()] = $item;
        }

        return $state;
    }
}
