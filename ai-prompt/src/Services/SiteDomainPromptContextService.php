<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use App\Models\Site;
use Omnichannel\Addons\Seo\Services\SeoDomainCtaGlobalSettingsService;

final class SiteDomainPromptContextService
{
    public const META_KEY = 'seo_domain_prompt_context';

    public const MAX_SHORT_DESCRIPTION_WORDS = 300;

    /** @var list<string> */
    public const PHONE_SLOT_TYPES = ['phone_1', 'phone_2', 'phone_3'];

    /** @var list<string> */
    public const EMAIL_SLOT_TYPES = ['email_1', 'email_2', 'email_3'];

    /**
     * @return list<string>
     */
    public static function contactSlotTypes(): array
    {
        return [...self::PHONE_SLOT_TYPES, ...self::EMAIL_SLOT_TYPES];
    }

    /**
     * @return list<string>
     */
    public static function reservedCtaTypes(): array
    {
        return [
            'phone',
            ...self::PHONE_SLOT_TYPES,
            'email',
            ...self::EMAIL_SLOT_TYPES,
            'website',
        ];
    }

    public static function isGlobalOnlyCtaType(string $type): bool
    {
        $type = mb_strtolower(trim($type));

        return in_array($type, self::globalOnlyCtaTypes(), true);
    }

    /**
     * @return list<string>
     */
    public static function globalOnlyCtaTypes(): array
    {
        return array_values(array_diff(
            array_keys(self::globalCtaFormTypeOptions()),
            array_keys(self::ctaFormTypeOptions()),
        ));
    }

    /** @var array<string, mixed>|null */
    private ?array $testSitePayload = null;

    public const DEFAULT_CTA_INTRO = 'Tạo một bảng so sánh hoặc danh sách liệt kê (bullet points) để tăng khả năng đạt Featured Snippet. Kêu gọi hành động (CTA) ở mỗi heading — chỉ dùng thông tin liên hệ đã resolve bên dưới, không bịa số điện thoại / email / mạng xã hội.';

    public const COMPANY_SHORT_IDENTITY_MAX = 80;

    /**
     * @param  array{
     *     tone?: string,
     *     company_short_identity?: string,
     *     short_description?: string,
     *     cta_intro?: string,
     *     phones?: list<array{value?: string}|string>,
     *     emails?: list<array{value?: string}|string>,
     *     socials?: list<array{network?: string, url?: string, value?: string}>,
     *     address?: string,
     *     cta?: list<array{type?: string, value?: string}>,
     *     links?: list<array{keyword?: string, link?: string}>,
     * }  $payload
     */
    public static function withTestPayload(array $payload): self
    {
        $service = new self;
        $service->testSitePayload = [
            'tone' => trim((string) ($payload['tone'] ?? '')),
            'company_short_identity' => trim((string) ($payload['company_short_identity'] ?? '')),
            'short_description' => trim((string) ($payload['short_description'] ?? '')),
            'cta_intro' => trim((string) ($payload['cta_intro'] ?? '')),
            'phones' => is_array($payload['phones'] ?? null) ? $payload['phones'] : [],
            'emails' => is_array($payload['emails'] ?? null) ? $payload['emails'] : [],
            'socials' => is_array($payload['socials'] ?? null) ? $payload['socials'] : [],
            'address' => trim((string) ($payload['address'] ?? '')),
            'cta' => is_array($payload['cta'] ?? null) ? $payload['cta'] : [],
            'links' => is_array($payload['links'] ?? null) ? $payload['links'] : [],
        ];

        return $service;
    }

    /**
     * @return array{
     *     tone: string,
     *     short_description: string,
     *     cta_intro: string,
     *     cta: list<array{type: string, value: string}>,
     *     links: list<array{keyword: string, link: string}>,
     * }
     */
    public function getForSite(Site|int $site): array
    {
        if ($this->testSitePayload !== null) {
            return $this->testSitePayload;
        }

        $payload = $this->getRawPayloadForSite($site);
        $payload['cta'] = $this->mergeGlobalCtaIntoRows($payload['cta']);

        return $payload;
    }

    /**
     * Payload domain thuần từ meta (không gộp CTA global).
     *
     * @return array{
     *     tone: string,
     *     short_description: string,
     *     cta_intro: string,
     *     cta: list<array{type: string, value: string}>,
     *     links: list<array{keyword: string, link: string}>,
     * }
     */
    public function getRawPayloadForSite(Site|int $site): array
    {
        $site = $site instanceof Site ? $site : Site::query()->findOrFail((int) $site);
        $site->loadMissing('metas');

        $raw = $site->getMeta(self::META_KEY);
        if (! is_string($raw) || trim($raw) === '') {
            return $this->emptyPayload();
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return $this->emptyPayload();
        }

        $cta = [];
        if (is_array($decoded['cta'] ?? null)) {
            foreach ($decoded['cta'] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $type = trim((string) ($row['type'] ?? ''));
                $value = trim((string) ($row['value'] ?? ''));
                if ($type === '' && $value === '') {
                    continue;
                }
                $cta[] = ['type' => $type, 'value' => $value];
            }
        }

        $cta = $this->normalizeCtaRows($cta);

        $links = [];
        if (is_array($decoded['links'] ?? null)) {
            foreach ($decoded['links'] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $keyword = trim((string) ($row['keyword'] ?? ''));
                $link = trim((string) ($row['link'] ?? ''));
                if ($keyword === '' && $link === '') {
                    continue;
                }
                $links[] = ['keyword' => $keyword, 'link' => $link];
            }
        }

        $ctaIntro = array_key_exists('cta_intro', $decoded)
            ? trim((string) ($decoded['cta_intro'] ?? ''))
            : '';

        if ($ctaIntro === '') {
            $ctaIntro = app(SeoDomainCtaGlobalSettingsService::class)->getDefaultCtaIntro();
        }

        $phones = $this->normalizeContactValueList($decoded['phones'] ?? null);
        $emails = $this->normalizeContactValueList($decoded['emails'] ?? null);
        $socials = $this->normalizeSocialList($decoded['socials'] ?? null);
        $address = trim((string) ($decoded['address'] ?? ''));
        if ($address === '') {
            $address = $this->ctaValueFromRows($cta, 'address');
        }

        // Legacy phone_1..3 / email_1..3 → lists when lists empty.
        if ($phones === []) {
            foreach (self::PHONE_SLOT_TYPES as $slot) {
                $value = $this->ctaValueFromRows($cta, $slot);
                if ($value !== '') {
                    $phones[] = ['value' => $value];
                }
            }
        }
        if ($emails === []) {
            foreach (self::EMAIL_SLOT_TYPES as $slot) {
                $value = $this->ctaValueFromRows($cta, $slot);
                if ($value !== '') {
                    $emails[] = ['value' => $value];
                }
            }
        }
        if ($socials === []) {
            foreach ($cta as $row) {
                $type = mb_strtolower(trim((string) ($row['type'] ?? '')));
                $value = trim((string) ($row['value'] ?? ''));
                if ($value === '') {
                    continue;
                }
                if (in_array($type, ['facebook', 'instagram', 'youtube', 'linkedin', 'tiktok', 'zalo', 'x', 'twitter', 'pinterest', 'threads'], true)) {
                    $socials[] = [
                        'network' => $type === 'twitter' ? 'x' : $type,
                        'url' => $value,
                    ];
                }
            }
        }

        return [
            'tone' => trim((string) ($decoded['tone'] ?? '')),
            'company_short_identity' => $this->clampCompanyShortIdentity(
                (string) ($decoded['company_short_identity'] ?? '')
            ),
            'short_description' => trim((string) ($decoded['short_description'] ?? '')),
            'cta_intro' => $ctaIntro,
            'phones' => $phones,
            'emails' => $emails,
            'socials' => $socials,
            'address' => $address,
            'cta' => $cta,
            'links' => $links,
        ];
    }

    /**
     * Gộp CTA global (vd. working_hours) vào danh sách domain — domain không cần cấu hình lẻ.
     *
     * @param  list<array{type?: string, value?: string}>  $domainCta
     * @param  list<array{type?: string, value?: string}>|null  $globalCta
     * @return list<array{type: string, value: string}>
     */
    public function mergeGlobalCtaIntoRows(array $domainCta, ?array $globalCta = null): array
    {
        $merged = $this->normalizeCtaRows($domainCta);
        $existingTypes = [];

        foreach ($merged as $row) {
            $type = mb_strtolower(trim((string) ($row['type'] ?? '')));
            $value = trim((string) ($row['value'] ?? ''));

            if ($type !== '' && $value !== '') {
                $existingTypes[$type] = true;
            }
        }

        $globalCta ??= app(SeoDomainCtaGlobalSettingsService::class)->getGlobalCta();

        foreach ($globalCta as $row) {
            if (! is_array($row)) {
                continue;
            }

            $type = mb_strtolower(trim((string) ($row['type'] ?? '')));
            $value = trim((string) ($row['value'] ?? ''));

            if ($type === '' || $value === '' || isset($existingTypes[$type])) {
                continue;
            }

            $merged[] = ['type' => $type, 'value' => $value];
            $existingTypes[$type] = true;
        }

        return $merged;
    }

    /**
     * Domain/site tone no longer participates in {{tone}} resolution.
     * Legacy persisted domain tone is ignored; callers must supply item/runtime tone.
     */
    public function resolveToneForSite(?Site $site, string $globalTone): string
    {
        return $globalTone;
    }

    /**
     * Dữ liệu hiển thị trên bảng danh sách domain.
     *
     * @return array{tone: string, short_description: string, cta_shortcodes: list<string>}
     */
    public function tableSummaryForSite(Site $site): array
    {
        $payload = $this->getForSite($site);
        $shortcodes = ['[website]'];

        if ($this->hasAnyPhoneSlot($payload['cta'])) {
            $shortcodes[] = '[phone]';
        }

        if ($this->hasAnyEmailSlot($payload['cta'])) {
            $shortcodes[] = '[email]';
        }

        foreach ($payload['cta'] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $type = mb_strtolower(trim((string) ($row['type'] ?? '')));
            if ($type === '' || in_array($type, self::reservedCtaTypes(), true)) {
                continue;
            }

            $shortcodes[] = '['.$type.']';
        }

        return [
            'tone' => trim((string) ($payload['tone'] ?? '')),
            'short_description' => trim((string) ($payload['short_description'] ?? '')),
            'cta_shortcodes' => array_values(array_unique($shortcodes)),
        ];
    }

    /**
     * @param  list<array{type?: string, value?: string}>  $cta
     */
    public function hasAnyPhoneSlot(array $cta): bool
    {
        foreach (self::PHONE_SLOT_TYPES as $slot) {
            if ($this->ctaValueFromRows($cta, $slot) !== '') {
                return true;
            }
        }

        return $this->ctaValueFromRows($cta, 'phone') !== '';
    }

    /**
     * @param  list<array{type?: string, value?: string}>  $cta
     */
    public function hasAnyEmailSlot(array $cta): bool
    {
        foreach (self::EMAIL_SLOT_TYPES as $slot) {
            if ($this->ctaValueFromRows($cta, $slot) !== '') {
                return true;
            }
        }

        return $this->ctaValueFromRows($cta, 'email') !== '';
    }

    /**
     * @param  list<array{type?: string, value?: string}>  $cta
     */
    public function ctaValueFromRows(array $cta, string $type): string
    {
        $type = mb_strtolower(trim($type));

        foreach ($cta as $row) {
            if (! is_array($row)) {
                continue;
            }

            if (mb_strtolower(trim((string) ($row['type'] ?? ''))) !== $type) {
                continue;
            }

            return trim((string) ($row['value'] ?? ''));
        }

        return '';
    }

    /**
     * @param  list<array{type?: string, value?: string}>  $cta
     * @return list<array{type: string, value: string}>
     */
    public function normalizeCtaRows(array $cta): array
    {
        $normalized = [];
        $legacyPhone = '';
        $legacyEmail = '';

        foreach ($cta as $row) {
            if (! is_array($row)) {
                continue;
            }

            $type = mb_strtolower(trim((string) ($row['type'] ?? '')));
            $value = trim((string) ($row['value'] ?? ''));

            if ($type === '' && $value === '') {
                continue;
            }

            if ($type === 'phone') {
                if ($legacyPhone === '' && $value !== '') {
                    $legacyPhone = $value;
                }

                continue;
            }

            if ($type === 'email') {
                if ($legacyEmail === '' && $value !== '') {
                    $legacyEmail = $value;
                }

                continue;
            }

            if ($type === 'website') {
                continue;
            }

            if (self::isGlobalOnlyCtaType($type)) {
                continue;
            }

            $normalized[] = ['type' => $type, 'value' => $value];
        }

        if ($legacyPhone !== '' && $this->ctaValueFromRows($normalized, 'phone_1') === '') {
            array_unshift($normalized, ['type' => 'phone_1', 'value' => $legacyPhone]);
        }

        if ($legacyEmail !== '' && $this->ctaValueFromRows($normalized, 'email_1') === '') {
            array_unshift($normalized, ['type' => 'email_1', 'value' => $legacyEmail]);
        }

        return $normalized;
    }

    /**
     * @param  array<string, string>  $slots
     * @param  list<array{type?: string, value?: string}>  $cta
     * @return list<array{type: string, value: string}>
     */
    public function mergeContactSlotsIntoCta(array $slots, array $cta): array
    {
        $cta = $this->normalizeCtaRows($cta);

        $withoutDedicated = array_values(array_filter(
            $cta,
            static fn (array $row): bool => ! in_array(
                mb_strtolower(trim((string) ($row['type'] ?? ''))),
                self::reservedCtaTypes(),
                true,
            ),
        ));

        $merged = [];

        foreach (self::contactSlotTypes() as $slot) {
            $value = trim((string) ($slots[$slot] ?? ''));
            if ($value === '') {
                continue;
            }

            $merged[] = ['type' => $slot, 'value' => $value];
        }

        return [...$merged, ...$withoutDedicated];
    }

    public function resolveEffectiveCtaIntro(string $domainCtaIntro): string
    {
        $domainCtaIntro = trim($domainCtaIntro);

        return $domainCtaIntro !== ''
            ? $domainCtaIntro
            : app(SeoDomainCtaGlobalSettingsService::class)->getDefaultCtaIntro();
    }

    /**
     * @param  array{
     *     tone?: string,
     *     company_short_identity?: string,
     *     short_description?: string,
     *     cta_intro?: string,
     *     phones?: list<array{value?: string}|string>,
     *     emails?: list<array{value?: string}|string>,
     *     socials?: list<array{network?: string, url?: string, value?: string}>,
     *     address?: string,
     *     phone_1?: string,
     *     phone_2?: string,
     *     phone_3?: string,
     *     email_1?: string,
     *     email_2?: string,
     *     email_3?: string,
     *     cta?: list<array{type?: string, value?: string}>,
     *     links?: list<array{keyword?: string, link?: string}>,
     * }  $payload
     */
    public function saveForSite(Site|int $site, array $payload): void
    {
        $site = $site instanceof Site ? $site : Site::query()->findOrFail((int) $site);

        $tone = trim((string) ($payload['tone'] ?? ''));
        $companyShort = $this->clampCompanyShortIdentity((string) ($payload['company_short_identity'] ?? ''));
        $shortDescription = trim((string) ($payload['short_description'] ?? ''));
        $ctaIntro = trim((string) ($payload['cta_intro'] ?? ''));
        $address = trim((string) ($payload['address'] ?? ''));

        if ($this->countWords($shortDescription) > self::MAX_SHORT_DESCRIPTION_WORDS) {
            throw new \InvalidArgumentException(
                'Mô tả ngắn tối đa '.self::MAX_SHORT_DESCRIPTION_WORDS.' từ (hiện tại: '
                .$this->countWords($shortDescription).').',
            );
        }

        $phones = $this->normalizeContactValueList($payload['phones'] ?? null);
        $emails = $this->normalizeContactValueList($payload['emails'] ?? null);
        $socials = $this->normalizeSocialList($payload['socials'] ?? null);

        // Legacy slot keys still accepted.
        if ($phones === []) {
            foreach (self::PHONE_SLOT_TYPES as $slot) {
                $value = trim((string) ($payload[$slot] ?? ''));
                if ($value !== '') {
                    $phones[] = ['value' => $value];
                }
            }
        }
        if ($emails === []) {
            foreach (self::EMAIL_SLOT_TYPES as $slot) {
                $value = trim((string) ($payload[$slot] ?? ''));
                if ($value !== '') {
                    $emails[] = ['value' => $value];
                }
            }
        }

        $contactSlots = [
            'phone_1' => $phones[0]['value'] ?? '',
            'phone_2' => $phones[1]['value'] ?? '',
            'phone_3' => $phones[2]['value'] ?? '',
            'email_1' => $emails[0]['value'] ?? '',
            'email_2' => $emails[1]['value'] ?? '',
            'email_3' => $emails[2]['value'] ?? '',
        ];

        $extraCta = is_array($payload['cta'] ?? null) ? $payload['cta'] : [];
        foreach (array_slice($phones, 3) as $row) {
            $extraCta[] = ['type' => 'phone', 'value' => $row['value']];
        }
        foreach (array_slice($emails, 3) as $row) {
            $extraCta[] = ['type' => 'email', 'value' => $row['value']];
        }
        foreach ($socials as $row) {
            $extraCta[] = [
                'type' => (string) ($row['network'] ?? ''),
                'value' => (string) ($row['url'] ?? ''),
            ];
        }
        if ($address !== '') {
            $extraCta[] = ['type' => 'address', 'value' => $address];
        }

        $cta = $this->mergeContactSlotsIntoCta($contactSlots, $extraCta);

        $links = [];
        foreach ($payload['links'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $keyword = trim((string) ($row['keyword'] ?? ''));
            $link = trim((string) ($row['link'] ?? ''));
            if ($keyword === '' || $link === '') {
                continue;
            }
            $links[] = ['keyword' => $keyword, 'link' => $link];
        }

        $json = json_encode([
            'tone' => $tone,
            'company_short_identity' => $companyShort,
            'short_description' => $shortDescription,
            'cta_intro' => $ctaIntro,
            'phones' => $phones,
            'emails' => $emails,
            'socials' => $socials,
            'address' => $address,
            'cta' => $cta,
            'links' => $links,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $site->metas()->updateOrCreate(
            ['meta_key' => self::META_KEY],
            ['meta_value' => $json],
        );
    }

    /**
     * Thêm các loại CTA còn thiếu vào cấu hình domain dưới dạng "biến trắng" (value rỗng),
     * để người dùng điền sau. Giữ nguyên các CTA đã có. Trả về danh sách type vừa thêm.
     *
     * @param  list<string>  $types
     * @return list<string>
     */
    public function addBlankCtaTypes(Site|int $site, array $types): array
    {
        $types = array_values(array_unique(array_filter(array_map(
            static fn ($type): string => mb_strtolower(trim((string) $type), 'UTF-8'),
            $types,
        ))));

        if ($types === []) {
            return [];
        }

        $site = $site instanceof Site ? $site : Site::query()->find((int) $site);
        if ($site === null) {
            return [];
        }

        $payload = $this->getRawPayloadForSite($site);

        $existing = [];
        foreach ($payload['cta'] as $row) {
            $existing[mb_strtolower(trim((string) ($row['type'] ?? '')), 'UTF-8')] = true;
        }

        $globalSettings = app(SeoDomainCtaGlobalSettingsService::class);

        $added = [];
        foreach ($types as $type) {
            if ($type === '' || isset($existing[$type])) {
                continue;
            }

            if (self::isGlobalOnlyCtaType($type)) {
                if ($globalSettings->globalCtaValue($type) !== '') {
                    continue;
                }

                continue;
            }

            if ($type === 'phone') {
                foreach (self::PHONE_SLOT_TYPES as $slot) {
                    if (isset($existing[$slot])) {
                        continue;
                    }

                    $payload['cta'][] = ['type' => $slot, 'value' => ''];
                    $existing[$slot] = true;
                    $added[] = $slot;
                }

                continue;
            }

            if ($type === 'email') {
                foreach (self::EMAIL_SLOT_TYPES as $slot) {
                    if (isset($existing[$slot])) {
                        continue;
                    }

                    $payload['cta'][] = ['type' => $slot, 'value' => ''];
                    $existing[$slot] = true;
                    $added[] = $slot;
                }

                continue;
            }

            $payload['cta'][] = ['type' => $type, 'value' => ''];
            $existing[$type] = true;
            $added[] = $type;
        }

        if ($added === []) {
            return [];
        }

        $this->writePayload($site, $payload);

        return $added;
    }

    /**
     * Ghi payload xuống meta domain, GIỮ LẠI cả CTA có value rỗng (biến trắng để điền sau).
     *
     * @param  array{
     *     tone?: string,
     *     short_description?: string,
     *     cta_intro?: string,
     *     cta?: list<array{type?: string, value?: string}>,
     *     links?: list<array{keyword?: string, link?: string}>,
     * }  $payload
     */
    private function writePayload(Site $site, array $payload): void
    {
        $cta = [];
        foreach ($payload['cta'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $type = trim((string) ($row['type'] ?? ''));
            $value = trim((string) ($row['value'] ?? ''));
            if ($type === '' && $value === '') {
                continue;
            }
            $cta[] = ['type' => $type, 'value' => $value];
        }

        $links = [];
        foreach ($payload['links'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $keyword = trim((string) ($row['keyword'] ?? ''));
            $link = trim((string) ($row['link'] ?? ''));
            if ($keyword === '' && $link === '') {
                continue;
            }
            $links[] = ['keyword' => $keyword, 'link' => $link];
        }

        $json = json_encode([
            'tone' => trim((string) ($payload['tone'] ?? '')),
            'short_description' => trim((string) ($payload['short_description'] ?? '')),
            'cta_intro' => trim((string) ($payload['cta_intro'] ?? '')),
            'cta' => $cta,
            'links' => $links,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $site->metas()->updateOrCreate(
            ['meta_key' => self::META_KEY],
            ['meta_value' => $json],
        );
    }

    /**
     * @return array<string, string> Biến gợi ý cho prompt (site_domain, site_short_description, site_cta)
     */
    public function promptVariablesForSite(?Site $site): array
    {
        if ($site === null) {
            return [
                'site_domain' => '',
                'site_company_short_identity' => '',
                'site_website_type' => '',
                'site_short_description' => '',
                'site_cta' => '',
                'site_links' => '',
            ];
        }

        $payload = $this->getForSite($site);
        $websiteType = trim((string) ($site->getMeta('seo_domain_type') ?? ''));

        $ctaText = $this->formatCtaForPrompt(
            $payload['cta'],
            $this->resolveEffectiveCtaIntro((string) ($payload['cta_intro'] ?? '')),
            $site,
        );

        return [
            'site_domain' => trim((string) $site->domain),
            'site_company_short_identity' => (string) ($payload['company_short_identity'] ?? ''),
            'site_website_type' => $websiteType,
            'site_short_description' => $payload['short_description'],
            'site_cta' => $ctaText,
            // Link list không đưa vào prompt (tiết kiệm token); dùng DomainLinkListKeywordSyncService + gợi ý editor.
            'site_links' => '',
        ];
    }

    /**
     * Final CTA context for AI — resolved contacts only, never raw [phone]/[email] placeholders.
     *
     * @param  list<array{type: string, value: string}>  $items
     */
    public function formatCtaForPrompt(array $items, string $intro = '', Site|int|null $site = null): string
    {
        $intro = trim($intro);
        // Writing guidance must not leak unresolved placeholders into AI prompts.
        $intro = $this->stripCtaPlaceholders($intro);
        $resolved = $this->resolveContactContextForPrompt($items, $site);

        $parts = [];
        if ($intro !== '') {
            $parts[] = $intro;
        }
        if ($resolved !== '') {
            $parts[] = "Resolved Contact Context:\n".$resolved;
        }

        $text = trim(implode("\n\n", $parts));
        app(\Omnichannel\Addons\SearchFoundation\Services\SiteMcp\SiteMcpContextAssembler::class)
            ->assertNoUnresolvedPlaceholders($text);

        return $text;
    }

    public function stripCtaPlaceholders(string $text): string
    {
        foreach (\Omnichannel\Addons\SearchFoundation\Services\SiteMcp\SiteMcpContextAssembler::PLACEHOLDER_PATTERN_TYPES as $type) {
            $text = (string) preg_replace('/\['.preg_quote($type, '/').'\]/iu', '', $text);
        }

        return trim(preg_replace('/[ \t]{2,}/u', ' ', $text) ?? $text);
    }

    /**
     * @param  list<array{type?: string, value?: string}>  $items
     */
    public function resolveContactContextForPrompt(array $items, Site|int|null $site = null): string
    {
        $lines = [];
        $phonePool = [];
        $emailPool = [];
        $seen = [];

        foreach ($items as $row) {
            if (! is_array($row)) {
                continue;
            }
            $type = mb_strtolower(trim((string) ($row['type'] ?? '')));
            $value = trim((string) ($row['value'] ?? ''));
            if ($type === '' || $value === '') {
                continue;
            }
            if (in_array($type, self::PHONE_SLOT_TYPES, true) || $type === 'phone' || $type === 'hotline') {
                $phonePool[] = $value;
                continue;
            }
            if (in_array($type, self::EMAIL_SLOT_TYPES, true) || $type === 'email') {
                $emailPool[] = $value;
                continue;
            }
            if ($type === 'website') {
                continue;
            }
            if (isset($seen[$type])) {
                continue;
            }
            $seen[$type] = true;
            $lines[] = $type.': '.$value;
        }

        $phonePool = array_values(array_unique($phonePool));
        $emailPool = array_values(array_unique($emailPool));
        if ($phonePool !== []) {
            $lines[] = 'phone: '.$phonePool[array_rand($phonePool)];
        }
        if ($emailPool !== []) {
            $lines[] = 'email: '.$emailPool[array_rand($emailPool)];
        }

        if ($site !== null) {
            $siteModel = $site instanceof Site ? $site : Site::query()->find((int) $site);
            $domain = trim((string) ($siteModel?->domain ?? ''));
            if ($domain !== '') {
                $lines[] = 'website: '.$domain;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{value: string}>
     */
    public function phonesFromPayload(array $payload): array
    {
        $phones = $this->normalizeContactValueList($payload['phones'] ?? null);
        if ($phones !== []) {
            return $phones;
        }
        foreach (self::PHONE_SLOT_TYPES as $slot) {
            $value = $this->ctaValueFromRows($payload['cta'] ?? [], $slot);
            if ($value !== '') {
                $phones[] = ['value' => $value];
            }
        }

        return $phones;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{value: string}>
     */
    public function emailsFromPayload(array $payload): array
    {
        $emails = $this->normalizeContactValueList($payload['emails'] ?? null);
        if ($emails !== []) {
            return $emails;
        }
        foreach (self::EMAIL_SLOT_TYPES as $slot) {
            $value = $this->ctaValueFromRows($payload['cta'] ?? [], $slot);
            if ($value !== '') {
                $emails[] = ['value' => $value];
            }
        }

        return $emails;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{network: string, url: string}>
     */
    public function socialsFromPayload(array $payload): array
    {
        $socials = $this->normalizeSocialList($payload['socials'] ?? null);
        if ($socials !== []) {
            return $socials;
        }
        foreach ($payload['cta'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $type = mb_strtolower(trim((string) ($row['type'] ?? '')));
            $value = trim((string) ($row['value'] ?? ''));
            if ($value === '') {
                continue;
            }
            if (in_array($type, ['facebook', 'instagram', 'youtube', 'linkedin', 'tiktok', 'zalo', 'x', 'twitter', 'pinterest', 'threads'], true)) {
                $socials[] = [
                    'network' => $type === 'twitter' ? 'x' : $type,
                    'url' => $value,
                ];
            }
        }

        return $socials;
    }

    /**
     * @param  mixed  $raw
     * @return list<array{value: string}>
     */
    public function normalizeContactValueList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $row) {
            if (is_string($row)) {
                $value = trim($row);
            } elseif (is_array($row)) {
                $value = trim((string) ($row['value'] ?? ''));
            } else {
                continue;
            }
            if ($value === '') {
                continue;
            }
            $out[] = ['value' => $value];
        }

        return $out;
    }

    /**
     * @param  mixed  $raw
     * @return list<array{network: string, url: string}>
     */
    public function normalizeSocialList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }
            $network = mb_strtolower(trim((string) ($row['network'] ?? $row['type'] ?? '')));
            $url = trim((string) ($row['url'] ?? $row['value'] ?? ''));
            if ($network === '' || $url === '') {
                continue;
            }
            if ($network === 'twitter') {
                $network = 'x';
            }
            $out[] = ['network' => $network, 'url' => $url];
        }

        return $out;
    }

    public function clampCompanyShortIdentity(string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        if (mb_strlen($value) <= self::COMPANY_SHORT_IDENTITY_MAX) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, self::COMPANY_SHORT_IDENTITY_MAX));
    }

    /**
     * @param  list<array{keyword: string, link: string}>  $items
     */
    public function formatLinksForPrompt(array $items): string
    {
        if ($items === []) {
            return '';
        }

        $lines = [];
        foreach ($items as $item) {
            $keyword = trim((string) ($item['keyword'] ?? ''));
            $link = trim((string) ($item['link'] ?? ''));
            if ($keyword === '' || $link === '') {
                continue;
            }
            $lines[] = "{$keyword} → {$link}";
        }

        return implode("\n", $lines);
    }

    public function countWords(string $text): int
    {
        $text = trim($text);
        if ($text === '') {
            return 0;
        }

        $parts = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        return is_array($parts) ? count($parts) : 0;
    }

    /**
     * @return array<string, string>
     */
    public static function ctaFormTypeOptions(): array
    {
        return [
            'zalo' => 'Zalo',
            'address' => 'Address',
            'facebook' => 'Facebook',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function globalCtaFormTypeOptions(): array
    {
        return [
            ...self::ctaFormTypeOptions(),
            'working_hours' => 'Working hours',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function phoneSlotFormLabels(): array
    {
        return [
            'phone_1' => 'Phone 1',
            'phone_2' => 'Phone 2',
            'phone_3' => 'Phone 3',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function emailSlotFormLabels(): array
    {
        return [
            'email_1' => 'Email 1',
            'email_2' => 'Email 2',
            'email_3' => 'Email 3',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function ctaTypeOptions(): array
    {
        return [
            'phone' => 'Số điện thoại',
            'hotline' => 'Hotline',
            'email' => 'Email',
            'address' => 'Địa chỉ',
            'zalo' => 'Zalo',
            'website' => 'Website',
            'facebook' => 'Facebook',
            'working_hours' => 'Giờ làm việc',
            'other' => 'Khác',
        ];
    }

    /**
     * @return array{
     *     short_description: string,
     *     cta_intro: string,
     *     cta: list<array{type: string, value: string}>,
     *     links: list<array{keyword: string, link: string}>,
     * }
     */
    private function emptyPayload(): array
    {
        return [
            'tone' => '',
            'company_short_identity' => '',
            'short_description' => '',
            'cta_intro' => app(SeoDomainCtaGlobalSettingsService::class)->getDefaultCtaIntro(),
            'phones' => [],
            'emails' => [],
            'socials' => [],
            'address' => '',
            'cta' => [],
            'links' => [],
        ];
    }
}
