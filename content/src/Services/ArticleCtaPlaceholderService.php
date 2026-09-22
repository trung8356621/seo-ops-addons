<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use Omnichannel\Addons\Seo\Support\CtaLinkFormatter;
use App\Models\Site;
use Omnichannel\Addons\AiPrompt\Services\SiteDomainPromptContextService;

/**
 * Placeholder [[cta:zalo]] / legacy [zalo] trong nội dung bài — AI chọn loại/vị trí; app resolve URL/HTML.
 */
final class ArticleCtaPlaceholderService
{
    public const BLANK_PLACEHOLDER_CLASS = 'seo-cta-blank-placeholder';

    /** Soft target for prompt guidance only — not a hard insert quota. */
    public const CTA_LINK_TARGET = 4;

    /** Hard maximum resolved CTA hyperlinks per article body. */
    public const CTA_LINK_HARD_MAX = 6;

    /** Max linked occurrences of the same CTA type/destination. */
    public const CTA_LINK_MAX_PER_TYPE = 2;

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

    /** @var array<string, string> */
    public const SEMANTIC_ANCHOR_LABELS = [
        'zalo' => 'Zalo',
        'facebook' => 'Facebook',
        'phone' => '',
        'hotline' => '',
        'email' => '',
        'website' => '',
        'address' => '',
        'working_hours' => '',
    ];

    public function __construct(
        private readonly SiteDomainPromptContextService $promptContext,
    ) {}

    public function placeholderGuideForPrompt(): string
    {
        $lines = ['Available CTA placeholders:'];
        foreach (array_keys(self::PLACEHOLDER_TYPES) as $type) {
            $lines[] = '[[cta:'.$type.']]';
        }

        return implode("\n", $lines);
    }

    /**
     * Canonical AI token for a CTA type.
     */
    public static function ctaToken(string $type): string
    {
        return '[[cta:'.mb_strtolower(trim($type)).']]';
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
     * Tìm các placeholder CTA ([[cta:phone]], [phone], …) đang được dùng trong nội dung.
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

                if ($this->contentHasToken($content, $type)) {
                    $found[$type] = true;
                }
            }
        }

        return array_keys($found);
    }

    private function contentHasToken(string $content, string $type): bool
    {
        $quoted = preg_quote($type, '/');

        return preg_match('/\[\[\s*cta\s*:\s*'.$quoted.'\s*\]\]/iu', $content) === 1
            || preg_match('/\['.$quoted.'\]/iu', $content) === 1;
    }

    /**
     * Match [[cta:type]] first, then legacy [type].
     */
    private function tokenPattern(string $tokenType): string
    {
        $quoted = preg_quote($tokenType, '/');

        return '/(?:\[\[\s*cta\s*:\s*'.$quoted.'\s*\]\]|\['.$quoted.'\])/iu';
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

            $pattern = $this->tokenPattern($type);
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
            .'"[^>]*data-cta-type="([a-z_]+)"[^>]*>.*?<\/span>/iu',
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

        $siteModel = $site instanceof Site ? $site : Site::query()->find((int) $site);
        if ($siteModel === null) {
            return $html;
        }

        $values = $this->resolveValuesForSite($siteModel);
        $phonePool = $values['_phone_pool'] ?? [];
        $emailPool = $values['_email_pool'] ?? [];
        unset($values['_phone_pool'], $values['_email_pool']);

        $budget = $this->newBudgetState($html);

        if (is_array($phonePool) && $phonePool !== []) {
            /** @var list<string> $phonePool */
            $html = $this->replaceTokenWithBoundary($html, 'phone', function () use ($phonePool): string {
                return (string) $phonePool[array_rand($phonePool)];
            }, null, $budget, $siteModel);
        }

        if (is_array($emailPool) && $emailPool !== []) {
            /** @var list<string> $emailPool */
            $html = $this->replaceTokenWithBoundary($html, 'email', function () use ($emailPool): string {
                return (string) $emailPool[array_rand($emailPool)];
            }, null, $budget, $siteModel);
        }

        foreach (array_keys(self::PLACEHOLDER_TYPES) as $type) {
            if (in_array($type, ['phone', 'email'], true)) {
                continue;
            }

            $value = trim((string) ($values[$type] ?? ''));
            if ($value === '') {
                continue;
            }

            $html = $this->replaceTokenWithBoundary(
                $html,
                $type,
                static fn (): string => $value,
                null,
                $budget,
                $siteModel,
            );
        }

        foreach (SiteDomainPromptContextService::PHONE_SLOT_TYPES as $slot) {
            $value = trim((string) ($values[$slot] ?? ''));
            if ($value === '') {
                continue;
            }

            $html = $this->replaceTokenWithBoundary(
                $html,
                $slot,
                static fn (): string => $value,
                'phone',
                $budget,
                $siteModel,
            );
        }

        foreach (SiteDomainPromptContextService::EMAIL_SLOT_TYPES as $slot) {
            $value = trim((string) ($values[$slot] ?? ''));
            if ($value === '') {
                continue;
            }

            $html = $this->replaceTokenWithBoundary(
                $html,
                $slot,
                static fn (): string => $value,
                'email',
                $budget,
                $siteModel,
            );
        }

        return $html;
    }

    /**
     * @return array{total: int, by_type: array<string, int>}
     */
    private function newBudgetState(string $html): array
    {
        $existing = $this->countExistingCtaHyperlinks($html);

        return [
            'total' => $existing['total'],
            'by_type' => $existing['by_type'],
        ];
    }

    /**
     * @return array{total: int, by_type: array<string, int>}
     */
    public function countExistingCtaHyperlinks(string $html): array
    {
        $byType = [];
        $total = 0;
        if ($html === '' || ! preg_match_all('/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>.*?<\/a>/isu', $html, $matches, PREG_SET_ORDER)) {
            return ['total' => 0, 'by_type' => []];
        }

        foreach ($matches as $match) {
            $href = trim((string) ($match[1] ?? ''));
            $type = $this->classifyCtaHref($href);
            if ($type === null) {
                continue;
            }
            $total++;
            $byType[$type] = ($byType[$type] ?? 0) + 1;
        }

        return ['total' => $total, 'by_type' => $byType];
    }

    private function classifyCtaHref(string $href): ?string
    {
        $lower = mb_strtolower($href);
        if (str_starts_with($lower, 'tel:')) {
            return 'phone';
        }
        if (str_starts_with($lower, 'mailto:')) {
            return 'email';
        }
        if (str_contains($lower, 'zalo.me') || str_contains($lower, 'zalo.')) {
            return 'zalo';
        }
        if (str_contains($lower, 'facebook.com') || str_contains($lower, 'fb.com')) {
            return 'facebook';
        }

        return null;
    }

    /**
     * Expand [[cta:token]] / [token] with lexical spacing + CTA link budget.
     *
     * @param  callable(): string  $pickValue
     * @param  array{total: int, by_type: array<string, int>}|null  $budget
     */
    private function replaceTokenWithBoundary(
        string $html,
        string $tokenType,
        callable $pickValue,
        ?string $linkType = null,
        ?array &$budget = null,
        ?Site $site = null,
    ): string {
        $pattern = $this->tokenPattern($tokenType);
        $offset = 0;
        $out = '';

        while (preg_match($pattern, $html, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $matched = (string) $match[0][0];
            $start = (int) $match[0][1];
            $out .= substr($html, $offset, $start - $offset);

            $value = trim($pickValue());
            if ($value === '') {
                $out .= $matched;
                $offset = $start + strlen($matched);

                continue;
            }

            $renderType = $linkType ?? $tokenType;
            $allowLink = $this->shouldRenderAsLink($renderType)
                && $this->budgetAllowsLink($budget, $renderType);
            $replacement = $this->buildReplacement($renderType, $value, $site, $allowLink);
            if ($allowLink && is_array($budget)) {
                $budget['total']++;
                $budget['by_type'][$renderType] = ($budget['by_type'][$renderType] ?? 0) + 1;
            }
            $left = $this->charBefore($html, $start);
            $right = $this->charAfter($html, $start + strlen($matched));
            $visible = $this->semanticAnchorText($renderType, $value, $site);
            $out .= $this->withLexicalBoundary($left, $replacement, $visible, $right);
            $offset = $start + strlen($matched);
        }

        return $out.substr($html, $offset);
    }

    /**
     * @param  array{total: int, by_type: array<string, int>}|null  $budget
     */
    private function budgetAllowsLink(?array $budget, string $type): bool
    {
        if ($budget === null) {
            return true;
        }

        if ($budget['total'] >= self::CTA_LINK_HARD_MAX) {
            return false;
        }

        return ((int) ($budget['by_type'][$type] ?? 0)) < self::CTA_LINK_MAX_PER_TYPE;
    }

    private function semanticAnchorText(string $type, string $value, ?Site $site): string
    {
        $type = mb_strtolower(trim($type));

        return match ($type) {
            'zalo' => 'Zalo',
            'facebook' => 'Facebook',
            'website' => $this->websiteAnchorLabel($value, $site),
            default => $value,
        };
    }

    private function websiteAnchorLabel(string $value, ?Site $site): string
    {
        if ($site !== null) {
            $payload = $this->promptContext->getForSite($site);
            $identity = trim((string) ($payload['company_short_identity'] ?? ''));
            if ($identity !== '') {
                return 'website '.$identity;
            }
        }

        $domain = trim((string) (preg_replace('~^https?://~i', '', $value) ?? $value), '/');

        return $domain !== '' ? $domain : 'website';
    }

    private function withLexicalBoundary(string $left, string $replacement, string $value, string $right): string
    {
        $prefix = '';
        $suffix = '';
        $valueStart = mb_substr($value, 0, 1, 'UTF-8');
        $valueEnd = mb_substr($value, -1, 1, 'UTF-8');

        if ($this->isLexicalChar($left) && $this->isLexicalChar($valueStart)) {
            $prefix = ' ';
        }

        if ($this->isLexicalChar($valueEnd) && $this->isLexicalChar($right)) {
            $suffix = ' ';
        }

        return $prefix.$replacement.$suffix;
    }

    private function isLexicalChar(string $char): bool
    {
        if ($char === '') {
            return false;
        }

        return preg_match('/^[\p{L}\p{N}]$/u', $char) === 1;
    }

    private function charBefore(string $html, int $byteOffset): string
    {
        if ($byteOffset <= 0) {
            return '';
        }

        $before = substr($html, 0, $byteOffset);
        if ($before === '') {
            return '';
        }

        return mb_substr($before, -1, 1, 'UTF-8');
    }

    private function charAfter(string $html, int $byteOffset): string
    {
        $after = substr($html, $byteOffset);
        if ($after === '') {
            return '';
        }

        return mb_substr($after, 0, 1, 'UTF-8');
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

    private function buildReplacement(string $type, string $value, ?Site $site = null, bool $asLink = true): string
    {
        $label = $this->semanticAnchorText($type, $value, $site);
        $safeLabel = htmlspecialchars($label, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if (! $asLink || ! $this->shouldRenderAsLink($type)) {
            return $safeLabel;
        }

        $href = CtaLinkFormatter::format($type, $value);
        if ($href === '') {
            return $safeLabel;
        }

        return sprintf(
            '<a href="%s">%s</a>',
            htmlspecialchars($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            $safeLabel,
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
