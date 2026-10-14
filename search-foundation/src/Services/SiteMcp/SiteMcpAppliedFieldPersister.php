<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Services\SiteMcp;

use App\Models\Site;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Omnichannel\Addons\AiPrompt\Services\SiteDomainPromptContextService;
use Omnichannel\Addons\SearchFoundation\Support\DomainListPresentation;

/**
 * Persists only MCP fields already accepted by SiteMcpFormApplicator.
 * Stored prompt-context siblings and other site metas stay untouched.
 */
final class SiteMcpAppliedFieldPersister
{
    /** @var list<string> */
    public const PROMPT_KEYS = [
        'company_short_identity',
        'short_description',
        'phones',
        'emails',
        'socials',
    ];

    public function __construct(
        private readonly SiteDomainPromptContextService $promptContext = new SiteDomainPromptContextService,
    ) {}

    /**
     * @param  list<string>  $applied
     * @param  array<string, mixed>  $formState
     */
    public function persist(Site $site, array $applied, array $formState): int
    {
        $stored = $this->promptContext->getRawPayloadForSite($site);
        $plan = $this->writePlan(
            $applied,
            $formState,
            $stored,
            $site->getMeta('seo_domain_type'),
        );

        if ($plan['count'] === 0 || ($plan['prompt'] === null && $plan['domain_type'] === null)) {
            return $plan['count'];
        }

        DB::connection($site->getConnectionName())->transaction(function () use ($site, $plan): void {
            if (is_array($plan['prompt'])) {
                $this->promptContext->saveForSite($site, $plan['prompt']);
            }

            if (is_string($plan['domain_type'])) {
                $site->metas()->updateOrCreate(
                    ['meta_key' => 'seo_domain_type'],
                    ['meta_value' => $plan['domain_type']],
                );
            }
        });

        $site->unsetRelation('metas');

        return $plan['count'];
    }

    /**
     * @param  list<string>  $applied
     * @param  array<string, mixed>  $formState
     * @param  array<string, mixed>  $storedPrompt
     * @return array{prompt: array<string, mixed>|null, domain_type: string|null, count: int}
     */
    public function writePlan(
        array $applied,
        array $formState,
        array $storedPrompt,
        mixed $storedDomainType,
    ): array {
        $appliedLookup = array_fill_keys($applied, true);
        $ctx = is_array($formState['promptContext'] ?? null) ? $formState['promptContext'] : [];
        $next = $storedPrompt;
        $promptChanged = false;
        $count = 0;

        foreach (self::PROMPT_KEYS as $key) {
            if (! isset($appliedLookup[$key])) {
                continue;
            }

            $value = $this->validatedPromptValue($key, $ctx);
            $count++;
            if (! $this->samePromptValue($key, $storedPrompt[$key] ?? null, $value)) {
                $next[$key] = $value;
                $promptChanged = true;
            }
        }

        $domainType = null;
        if (isset($appliedLookup['seo_domain_type'])) {
            $type = (string) ($formState['seo_domain_type'] ?? '');
            if (! array_key_exists($type, DomainListPresentation::websiteTypeFormOptions())) {
                throw new InvalidArgumentException('Loại website MCP không hợp lệ.');
            }

            $count++;
            if ((string) ($storedDomainType ?? '') !== $type) {
                $domainType = $type;
            }
        }

        return [
            'prompt' => $promptChanged ? $next : null,
            'domain_type' => $domainType,
            'count' => $count,
        ];
    }

    private function validatedPromptValue(string $key, array $ctx): mixed
    {
        return match ($key) {
            'company_short_identity' => $this->identity((string) ($ctx['company_short_identity'] ?? '')),
            'short_description' => $this->description((string) ($ctx['short_description'] ?? '')),
            'phones' => $this->phones($ctx['phones'] ?? null),
            'emails' => $this->emails($ctx['emails'] ?? null),
            'socials' => $this->socials($ctx['socials'] ?? null),
            default => throw new InvalidArgumentException('Trường MCP không được phép lưu.'),
        };
    }

    private function identity(string $raw): string
    {
        $value = $this->promptContext->clampCompanyShortIdentity($raw);
        if ($value === '') {
            throw new InvalidArgumentException('Định danh ngắn từ MCP không hợp lệ.');
        }

        return $value;
    }

    private function description(string $raw): string
    {
        $value = trim($raw);
        if ($value === '' || mb_strlen($value) > 8000) {
            throw new InvalidArgumentException('Mô tả ngắn từ MCP không hợp lệ.');
        }

        if ($this->promptContext->countWords($value) > SiteDomainPromptContextService::MAX_SHORT_DESCRIPTION_WORDS) {
            throw new InvalidArgumentException(
                'Mô tả ngắn tối đa '.SiteDomainPromptContextService::MAX_SHORT_DESCRIPTION_WORDS.' từ.',
            );
        }

        return $value;
    }

    /**
     * @return list<array{value: string}>
     */
    private function phones(mixed $raw): array
    {
        $phones = [];
        foreach ($this->promptContext->normalizeContactValueList($raw) as $row) {
            $value = $row['value'];
            if (mb_strlen($value) > 50) {
                throw new InvalidArgumentException('Số điện thoại MCP không hợp lệ.');
            }
            $phones[] = ['value' => $value];
        }

        if ($phones === []) {
            throw new InvalidArgumentException('Số điện thoại MCP không hợp lệ.');
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
            $value = $row['value'];
            if (mb_strlen($value) > 255 || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                throw new InvalidArgumentException('Email MCP không hợp lệ.');
            }
            $emails[] = ['value' => $value];
        }

        if ($emails === []) {
            throw new InvalidArgumentException('Email MCP không hợp lệ.');
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
            $network = $row['network'];
            $url = $row['url'];
            if (! isset($allowed[$network]) || mb_strlen($url) > 2000 || filter_var($url, FILTER_VALIDATE_URL) === false) {
                throw new InvalidArgumentException('Mạng xã hội MCP không hợp lệ.');
            }
            $socials[] = ['network' => $network, 'url' => $url];
        }

        if ($socials === []) {
            throw new InvalidArgumentException('Mạng xã hội MCP không hợp lệ.');
        }

        return $socials;
    }

    private function samePromptValue(string $key, mixed $stored, mixed $next): bool
    {
        if ($key === 'phones' || $key === 'emails') {
            return $this->promptContext->normalizeContactValueList($stored)
                === $this->promptContext->normalizeContactValueList($next);
        }

        if ($key === 'socials') {
            return $this->promptContext->normalizeSocialList($stored)
                === $this->promptContext->normalizeSocialList($next);
        }

        if ($key === 'company_short_identity') {
            return $this->promptContext->clampCompanyShortIdentity((string) $stored) === (string) $next;
        }

        return trim((string) $stored) === (string) $next;
    }
}
