<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use Omnichannel\Addons\Seo\Support\CtaLinkFormatter;
use App\Models\Site;
use Omnichannel\Addons\AiPrompt\Services\SiteDomainPromptContextService;

/**
 * Placeholder [phone], [website], … trong nội dung bài — AI dùng thay vì tự đặt tên random.
 */
final class ArticleCtaPlaceholderService
{
    public const BLANK_PLACEHOLDER_CLASS = 'seo-cta-blank-placeholder';

    /** @var array<string, string> */
    public const PLACEHOLDER_TYPES = [
        'phone' => 'Số điện thoại',
        'hotline' => 'Hotline',
        'email' => 'Email',
        'zalo' => 'Zalo',
        'address' => 'Địa chỉ',
        'website' => 'Website',
        'facebook' => 'Facebook',
        'working_hours' => 'Giờ làm việc',
    ];

    public function __construct(
        private readonly SiteDomainPromptContextService $promptContext,
    ) {}

    public function placeholderGuideForPrompt(): string
    {
        $lines = [];
        foreach (array_keys(self::PLACEHOLDER_TYPES) as $type) {
            $lines[] = "[{$type}]";
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<string, string>
     */
    public function resolveValuesForSite(Site|int|null $site): array
    {
        if ($site === null) {
            return [];
        }

        $siteModel = $site instanceof Site ? $site : Site::query()->find((int) $site);
        if ($siteModel === null) {
            return [];
        }

        $values = [];

        foreach ($this->promptContext->getForSite($siteModel)['cta'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $type = mb_strtolower(trim((string) ($row['type'] ?? '')));
            $value = trim((string) ($row['value'] ?? ''));

            if ($type === '' || $value === '' || isset($values[$type])) {
                continue;
            }

            $values[$type] = $value;
        }

        $values['website'] = trim((string) $siteModel->domain);

        $phones = $this->collectSlotValues($values, SiteDomainPromptContextService::PHONE_SLOT_TYPES, 'phone');
        if ($phones !== []) {
            $values['_phone_pool'] = $phones;
            $values['phone'] = $phones[0];
        }

        $emails = $this->collectSlotValues($values, SiteDomainPromptContextService::EMAIL_SLOT_TYPES, 'email');
        if ($emails !== []) {
            $values['_email_pool'] = $emails;
            $values['email'] = $emails[0];
        }

        if (! isset($values['hotline']) && isset($values['phone'])) {
            $values['hotline'] = $values['phone'];
        }

        if (! isset($values['phone']) && isset($values['hotline'])) {
            $values['phone'] = $values['hotline'];
        }

        return $values;
    }

    /**
     * @param  array<string, string>  $values
     * @param  list<string>  $slots
     * @return list<string>
     */
    private function collectSlotValues(array $values, array $slots, string $legacyType): array
    {
        $collected = [];

        foreach ($slots as $slot) {
            $value = trim((string) ($values[$slot] ?? ''));
            if ($value !== '') {
                $collected[] = $value;
            }
        }

        $legacyValue = trim((string) ($values[$legacyType] ?? ''));
        if ($legacyValue !== '' && ! in_array($legacyValue, $collected, true)) {
            $collected[] = $legacyValue;
        }

        return array_values(array_unique($collected));
    }

    /**
     * @param  array<string, string>  $values
     * @return list<string>
     */
    private function collectPhoneValues(array $values): array
    {
        return $this->collectSlotValues(
            $values,
            SiteDomainPromptContextService::PHONE_SLOT_TYPES,
            'phone',
        );
    }

    /**
     * @param  array<string, string>  $values
     * @return list<string>
     */
    private function collectEmailValues(array $values): array
    {
        return $this->collectSlotValues(
            $values,
            SiteDomainPromptContextService::EMAIL_SLOT_TYPES,
            'email',
        );
    }

    /**
     * Tìm các placeholder CTA ([phone], [address], …) đang được dùng trong nội dung.
     *
     * @return list<string>
     */
    public function detectPlaceholderTypes(string ...$contents): array
    {
        $found = [];

        foreach ($contents as $content) {
            if ($content === '') {
                continue;
            }

            foreach (array_keys(self::PLACEHOLDER_TYPES) as $type) {
                if (isset($found[$type])) {
                    continue;
                }

                if (preg_match('/\['.preg_quote($type, '/').'\]/iu', $content) === 1) {
                    $found[$type] = true;
                }
            }
        }

        return array_keys($found);
    }

    /**
     * Xử lý CTA khi đăng / convert bài:
     *  - Placeholder có giá trị trên domain → thay bằng giá trị thật (link/text).
     *  - Placeholder CHƯA có giá trị → tự thêm "biến trắng" vào CTA domain (để điền sau),
     *    placeholder được giữ nguyên cho tới khi có giá trị.
     *
     * @param  list<array<string, mixed>>  $faqs
     * @return array{html: string, faqs: list<array<string, mixed>>, added_blank_types: list<string>}
     */
    public function applyForPublish(Site|int|null $site, string $html, array $faqs = []): array
    {
        if ($site === null) {
            return ['html' => $html, 'faqs' => $faqs, 'added_blank_types' => []];
        }

        $faqText = [];
        foreach ($faqs as $faq) {
            if (! is_array($faq)) {
                continue;
            }
            foreach (['question', 'answer', 'more'] as $field) {
                $faqText[] = (string) ($faq[$field] ?? '');
            }
        }

        $usedTypes = $this->detectPlaceholderTypes($html, ...$faqText);

        $added = [];
        if ($usedTypes !== []) {
            $values = $this->resolveValuesForSite($site);
            unset($values['_phone_pool'], $values['_email_pool']);

            $missing = [];
            foreach ($usedTypes as $type) {
                if ($type === 'website') {
                    continue;
                }

                if ($type === 'phone') {
                    if ($this->collectPhoneValues($values) === []) {
                        $missing[] = 'phone';
                    }

                    continue;
                }

                if ($type === 'email') {
                    if ($this->collectEmailValues($values) === []) {
                        $missing[] = 'email';
                    }

                    continue;
                }

                if (SiteDomainPromptContextService::isGlobalOnlyCtaType($type)) {
                    continue;
                }

                if (trim((string) ($values[$type] ?? '')) === '') {
                    $missing[] = $type;
                }
            }

            if ($missing !== []) {
                $added = $this->promptContext->addBlankCtaTypes($site, $missing);
            }
        }

        $html = $this->replaceInHtml($html, $site);
        $faqs = $this->replaceInFaqs($faqs, $site);

        return [
            'html' => $this->highlightBlankPlaceholdersInHtml($html, $site),
            'faqs' => $this->highlightBlankPlaceholdersInFaqs($faqs, $site),
            'added_blank_types' => $added,
        ];
    }

    /**
     * Bọc [phone], [website], … chưa có giá trị domain bằng span đỏ trong editor.
     */
    public function highlightBlankPlaceholdersInHtml(string $html, Site|int|null $site): string
    {
        if (trim($html) === '' || $site === null) {
            return $html;
        }

        $html = $this->stripBlankPlaceholderMarkup($html);
        $values = $this->resolveValuesForSite($site);
        unset($values['_phone_pool'], $values['_email_pool']);

        foreach (array_keys(self::PLACEHOLDER_TYPES) as $type) {
            if ($type === 'website') {
                continue;
            }

            if ($type === 'phone') {
                if ($this->collectPhoneValues($values) !== []) {
                    continue;
                }
            }

            if ($type === 'email') {
                if ($this->collectEmailValues($values) !== []) {
                    continue;
                }
            }

            if (trim((string) ($values[$type] ?? '')) !== '') {
                continue;
            }

            $pattern = '/\['.preg_quote($type, '/').'\]/iu';
            $replacement = sprintf(
                '<span class="%s" data-cta-type="%s">[%s]</span>',
                self::BLANK_PLACEHOLDER_CLASS,
                htmlspecialchars($type, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                htmlspecialchars($type, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            );

            $html = (string) preg_replace($pattern, $replacement, $html);
        }

        return $html;
    }

    /**
     * Gỡ span highlight editor → giữ [type] thuần (lưu DB / đồng bộ WP).
     */
    public function stripBlankPlaceholderMarkup(string $html): string
    {
        if ($html === '' || ! str_contains($html, self::BLANK_PLACEHOLDER_CLASS)) {
            return $html;
        }

        return (string) preg_replace(
            '/<span\s+class="'.preg_quote(self::BLANK_PLACEHOLDER_CLASS, '/')
            .'"[^>]*data-cta-type="([a-z_]+)"[^>]*>\[\1\]<\/span>/iu',
            '[$1]',
            $html,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $faqs
     * @return list<array<string, mixed>>
     */
    public function highlightBlankPlaceholdersInFaqs(array $faqs, Site|int|null $site): array
    {
        if ($faqs === [] || $site === null) {
            return $faqs;
        }

        foreach ($faqs as $index => $faq) {
            if (! is_array($faq)) {
                continue;
            }

            foreach (['question', 'answer', 'more'] as $field) {
                if (! isset($faq[$field]) || ! is_string($faq[$field])) {
                    continue;
                }

                $faqs[$index][$field] = $this->highlightBlankPlaceholdersInHtml($faq[$field], $site);
            }
        }

        return $faqs;
    }

    public function replaceInHtml(string $html, Site|int|null $site): string
    {
        if (trim($html) === '' || $site === null) {
            return $html;
        }

        $values = $this->resolveValuesForSite($site);
        $phonePool = $values['_phone_pool'] ?? [];
        $emailPool = $values['_email_pool'] ?? [];
        unset($values['_phone_pool'], $values['_email_pool']);

        if ($phonePool !== []) {
            $html = (string) preg_replace_callback(
                '/\[phone\]/iu',
                function () use ($phonePool): string {
                    $picked = $phonePool[array_rand($phonePool)];

                    return $this->buildReplacement('phone', $picked);
                },
                $html,
            );
        }

        if ($emailPool !== []) {
            $html = (string) preg_replace_callback(
                '/\[email\]/iu',
                function () use ($emailPool): string {
                    $picked = $emailPool[array_rand($emailPool)];

                    return $this->buildReplacement('email', $picked);
                },
                $html,
            );
        }

        foreach (array_keys(self::PLACEHOLDER_TYPES) as $type) {
            if (in_array($type, ['phone', 'email'], true)) {
                continue;
            }

            $value = trim((string) ($values[$type] ?? ''));
            if ($value === '') {
                continue;
            }

            $pattern = '/\['.preg_quote($type, '/').'\]/iu';
            $replacement = $this->buildReplacement($type, $value);

            $html = (string) preg_replace($pattern, $replacement, $html);
        }

        foreach (SiteDomainPromptContextService::PHONE_SLOT_TYPES as $slot) {
            $value = trim((string) ($values[$slot] ?? ''));
            if ($value === '') {
                continue;
            }

            $pattern = '/\['.preg_quote($slot, '/').'\]/iu';
            $replacement = $this->buildReplacement('phone', $value);

            $html = (string) preg_replace($pattern, $replacement, $html);
        }

        foreach (SiteDomainPromptContextService::EMAIL_SLOT_TYPES as $slot) {
            $value = trim((string) ($values[$slot] ?? ''));
            if ($value === '') {
                continue;
            }

            $pattern = '/\['.preg_quote($slot, '/').'\]/iu';
            $replacement = $this->buildReplacement('email', $value);

            $html = (string) preg_replace($pattern, $replacement, $html);
        }

        return $html;
    }

    /**
     * @param  list<array<string, mixed>>  $faqs
     * @return list<array<string, mixed>>
     */
    public function replaceInFaqs(array $faqs, Site|int|null $site): array
    {
        if ($faqs === [] || $site === null) {
            return $faqs;
        }

        foreach ($faqs as $index => $faq) {
            if (! is_array($faq)) {
                continue;
            }

            foreach (['question', 'answer', 'more'] as $field) {
                if (! isset($faq[$field]) || ! is_string($faq[$field])) {
                    continue;
                }

                $faqs[$index][$field] = $this->replaceInHtml($faq[$field], $site);
            }
        }

        return $faqs;
    }

    private function buildReplacement(string $type, string $value): string
    {
        if (! $this->shouldRenderAsLink($type)) {
            return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $href = CtaLinkFormatter::format($type, $value);
        if ($href === '') {
            return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return sprintf(
            '<a href="%s">%s</a>',
            htmlspecialchars($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        );
    }

    private function shouldRenderAsLink(string $type): bool
    {
        if (CtaLinkFormatter::isPlainTextType($type)) {
            return false;
        }

        return in_array($type, ['phone', 'hotline', 'email', 'zalo', 'website', 'facebook'], true);
    }
}
