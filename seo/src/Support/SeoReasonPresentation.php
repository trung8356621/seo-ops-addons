<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Support;

/**
 * Concrete SEO reason metrics + safe locale presentation (no snake_case leak).
 */
final class SeoReasonPresentation
{
    /** Soft recommendation: ~6 ảnh cho bài ~1.150 từ. */
    public const TARGET_WORDS_PER_IMAGE = 200;

    /**
     * @return array{
     *   current_image_count: int,
     *   recommended_image_count: int,
     *   missing_image_count: int,
     *   current_word_count: int,
     *   target_words_per_image: int,
     *   base_score: int,
     *   missing_alt: int
     * }
     */
    public static function imageRatioMetrics(string $htmlContent): array
    {
        $eligibleHtml = (string) preg_replace(
            [
                '/<figcaption\b[^>]*>[\s\S]*?<\/figcaption>/iu',
                '/<figure\b[^>]*>[\s\S]*?<\/figure>/iu',
                '/<img\b[^>]*>/iu',
                '/\s(?:alt|title|aria-label|data-filename|data-slug)\s*=\s*(["\'])[\s\S]*?\1/iu',
            ],
            ' ',
            $htmlContent,
        );
        $textContent = preg_replace('/\s+/u', ' ', trim(strip_tags($eligibleHtml))) ?? '';
        if ($textContent === '') {
            $wordCount = 0;
        } elseif (preg_match_all('/[\p{L}][\p{L}\p{N}\-]*/u', $textContent, $matches) > 0) {
            $wordCount = count($matches[0]);
        } else {
            $wordCount = 0;
        }

        $validImageCount = 0;
        $missingAlt = 0;

        if (trim($htmlContent) !== '') {
            $dom = new \DOMDocument;
            libxml_use_internal_errors(true);
            $dom->loadHTML(
                mb_convert_encoding($htmlContent, 'HTML-ENTITIES', 'UTF-8'),
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
            );
            libxml_clear_errors();

            foreach ($dom->getElementsByTagName('img') as $img) {
                $src = trim((string) $img->getAttribute('src'));
                if ($src === '' || preg_match('/placeholder/i', $src) === 1) {
                    continue;
                }
                $validImageCount++;
                if (trim((string) $img->getAttribute('alt')) === '') {
                    $missingAlt++;
                }
            }
        }

        $recommended = $wordCount > 0
            ? max(1, (int) ceil($wordCount / self::TARGET_WORDS_PER_IMAGE))
            : 0;
        $missing = max(0, $recommended - $validImageCount);

        $baseScore = 0;
        if ($wordCount >= 10 && $validImageCount > 0) {
            $baseScore = match (true) {
                $missing === 0 => 15,
                $missing === 1 => 12,
                $missing === 2 => 8,
                $missing >= 3 => 3,
                default => 3,
            };
        }

        return [
            'current_image_count' => $validImageCount,
            'recommended_image_count' => $recommended,
            'missing_image_count' => $missing,
            'current_word_count' => $wordCount,
            'target_words_per_image' => self::TARGET_WORDS_PER_IMAGE,
            'base_score' => $baseScore,
            'missing_alt' => $missingAlt,
        ];
    }

    /**
     * @return array{
     *   current_word_count: int,
     *   recommended_word_count: int,
     *   missing_word_count: int
     * }
     */
    public static function contentLengthMetrics(int $currentWordCount, int $recommendedWordCount): array
    {
        $current = max(0, $currentWordCount);
        $recommended = max(1, $recommendedWordCount);

        return [
            'current_word_count' => $current,
            'recommended_word_count' => $recommended,
            'missing_word_count' => max(0, $recommended - $current),
        ];
    }

    /**
     * @param  array<string, int|float|string>  $vars
     */
    public static function interpolate(string $template, array $vars): string
    {
        return (string) preg_replace_callback(
            '/:([a-zA-Z0-9_]+)/',
            static function (array $matches) use ($vars): string {
                $key = $matches[1];

                return array_key_exists($key, $vars) ? (string) $vars[$key] : '';
            },
            $template,
        );
    }

    public static function formatCount(int|float $value, string $locale = 'vi'): string
    {
        $number = (int) $value;

        if (class_exists(\NumberFormatter::class)) {
            $formatter = new \NumberFormatter(
                $locale === 'en' ? 'en_US' : 'vi_VN',
                \NumberFormatter::DECIMAL,
            );
            $formatted = $formatter->format($number);
            if (is_string($formatted) && $formatted !== '') {
                return $formatted;
            }
        }

        return $locale === 'en'
            ? number_format($number)
            : number_format($number, 0, ',', '.');
    }

    /**
     * Pure PHPUnit may bind `app()` to a bare Container without getLocale().
     */
    private static function canUseLaravelLocale(): bool
    {
        if (! function_exists('app')) {
            return false;
        }

        try {
            $app = app();
        } catch (\Throwable) {
            return false;
        }

        return is_object($app) && method_exists($app, 'getLocale') && method_exists($app, 'setLocale');
    }

    /**
     * Built-in metric templates — used when lang file/cache still has legacy strings.
     *
     * @return array<string, string>
     */
    public static function defaultMetricTemplates(string $locale = 'vi'): array
    {
        if (str_starts_with($locale, 'en')) {
            return [
                'content_length_low' => 'Add about :missing more words.',
                'content_length_low_label' => 'Content is too short',
                'content_length_low_detail' => 'Current length: :current words; recommended minimum: about :recommended words.',
                'image_ratio_missing' => 'Currently :current images; about :recommended recommended for a :words-word article.',
                'image_ratio_missing_label' => 'No content images yet',
                'image_ratio_missing_detail' => 'Currently :current images; about :recommended recommended for a :words-word article.',
                'image_ratio_poor' => 'Currently :current images; about :recommended recommended for a :words-word article.',
                'image_ratio_poor_label' => 'Image density can improve',
                'image_ratio_poor_detail' => 'Currently :current images; about :recommended recommended for a :words-word article.',
                'image_ratio_low' => 'Currently :current images; about :recommended recommended for a :words-word article.',
                'image_ratio_low_label' => 'Image density suggestion',
                'image_ratio_low_detail' => 'Currently :current images; about :recommended recommended for a :words-word article.',
                'image_ratio_suboptimal' => 'Currently :current images; about :recommended recommended for a :words-word article.',
                'image_ratio_suboptimal_label' => 'Slightly below image suggestion',
                'image_ratio_suboptimal_detail' => 'Currently :current images; about :recommended recommended for a :words-word article.',
            ];
        }

        return [
            'content_length_low' => 'Thiếu khoảng :missing từ.',
            'content_length_low_label' => 'Nội dung còn ngắn',
            'content_length_low_detail' => 'Bài viết hiện có :current từ; đề xuất tối thiểu khoảng :recommended từ.',
            'image_ratio_missing' => 'Hiện có :current ảnh; đề xuất khoảng :recommended ảnh cho bài viết :words từ.',
            'image_ratio_missing_label' => 'Chưa có ảnh nội dung',
            'image_ratio_missing_detail' => 'Hiện có :current ảnh; đề xuất khoảng :recommended ảnh cho bài viết :words từ.',
            'image_ratio_poor' => 'Hiện có :current ảnh; đề xuất khoảng :recommended ảnh cho bài viết :words từ.',
            'image_ratio_poor_label' => 'Nên thêm ảnh nội dung',
            'image_ratio_poor_detail' => 'Hiện có :current ảnh; đề xuất khoảng :recommended ảnh cho bài viết :words từ.',
            'image_ratio_low' => 'Hiện có :current ảnh; đề xuất khoảng :recommended ảnh cho bài viết :words từ.',
            'image_ratio_low_label' => 'Gợi ý mật độ ảnh',
            'image_ratio_low_detail' => 'Hiện có :current ảnh; đề xuất khoảng :recommended ảnh cho bài viết :words từ.',
            'image_ratio_suboptimal' => 'Hiện có :current ảnh; đề xuất khoảng :recommended ảnh cho bài viết :words từ.',
            'image_ratio_suboptimal_label' => 'Thiếu khoảng 1 ảnh so với đề xuất',
            'image_ratio_suboptimal_detail' => 'Hiện có :current ảnh; đề xuất khoảng :recommended ảnh cho bài viết :words từ.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function loadSeoRulesLines(string $locale = 'vi'): array
    {
        $normalizedLocale = str_starts_with($locale, 'en') ? 'en' : 'vi';
        $candidates = [
            dirname(__DIR__, 4).DIRECTORY_SEPARATOR.'lang'.DIRECTORY_SEPARATOR.$normalizedLocale.DIRECTORY_SEPARATOR.'seo_rules.php',
        ];

        if (function_exists('base_path')) {
            try {
                $candidates[] = base_path('lang/'.$normalizedLocale.'/seo_rules.php');
            } catch (\Throwable) {
                // ignore — pure PHPUnit / incomplete app bootstrap
            }
        }

        foreach ($candidates as $path) {
            if (! is_string($path) || $path === '' || ! is_file($path)) {
                continue;
            }

            /** @var mixed $data */
            $data = require $path;
            if (! is_array($data)) {
                continue;
            }

            $lines = [];
            foreach ($data as $key => $value) {
                if (is_string($key) && is_string($value) && $value !== '') {
                    $lines[$key] = $value;
                }
            }

            if ($lines !== []) {
                return $lines;
            }
        }

        return [];
    }

    /**
     * File/lang lines merged with metric defaults. Prefer template that still has `:missing`
     * when metrics presentation needs concrete counts (legacy lang cache may omit placeholders).
     *
     * @return array<string, string>
     */
    public static function resolvedSeoRulesLines(string $locale = 'vi'): array
    {
        $defaults = self::defaultMetricTemplates($locale);
        $fileLines = self::loadSeoRulesLines($locale);
        $merged = array_merge($defaults, $fileLines);

        foreach ($defaults as $key => $defaultTemplate) {
            $current = (string) ($merged[$key] ?? '');
            $needsPlaceholder = str_contains($defaultTemplate, ':missing')
                || str_contains($defaultTemplate, ':current')
                || str_contains($defaultTemplate, ':recommended');
            if ($needsPlaceholder && ($current === '' || ! preg_match('/:(missing|current|recommended|words)\b/', $current))) {
                $merged[$key] = $defaultTemplate;
            }
        }

        return $merged;
    }

    /**
     * @param  array<string, int|float|string>  $metrics
     * @return array{code: string, label: string, summary: string, detail: string}
     */
    public static function present(string $code, array $metrics = [], ?string $locale = null): array
    {
        $normalized = preg_replace('/^seo_rules\./', '', $code) ?? $code;
        $locale = $locale !== null && $locale !== ''
            ? $locale
            : (self::canUseLaravelLocale() ? (string) app()->getLocale() : 'vi');

        $lines = self::resolvedSeoRulesLines($locale);
        $summaryRaw = (string) ($lines[$normalized] ?? '');
        $labelRaw = (string) ($lines[$normalized.'_label'] ?? '');
        $detailRaw = (string) ($lines[$normalized.'_detail'] ?? '');

        $vars = [];
        foreach ($metrics as $key => $value) {
            $vars[$key] = is_numeric($value)
                ? self::formatCount((float) $value, str_starts_with($locale, 'en') ? 'en' : 'vi')
                : (string) $value;
        }

        if (isset($metrics['missing_image_count'])) {
            $vars['missing'] = self::formatCount((int) $metrics['missing_image_count'], str_starts_with($locale, 'en') ? 'en' : 'vi');
        }
        if (isset($metrics['current_image_count'])) {
            $vars['current'] = self::formatCount((int) $metrics['current_image_count'], str_starts_with($locale, 'en') ? 'en' : 'vi');
        }
        if (isset($metrics['recommended_image_count'])) {
            $vars['recommended'] = self::formatCount((int) $metrics['recommended_image_count'], str_starts_with($locale, 'en') ? 'en' : 'vi');
        }
        if (isset($metrics['current_word_count'])) {
            $vars['words'] = self::formatCount((int) $metrics['current_word_count'], str_starts_with($locale, 'en') ? 'en' : 'vi');
            $vars['current'] = $vars['current'] ?? $vars['words'];
        }
        if (isset($metrics['recommended_word_count'])) {
            $vars['recommended'] = $vars['recommended'] ?? self::formatCount((int) $metrics['recommended_word_count'], str_starts_with($locale, 'en') ? 'en' : 'vi');
        }
        if (isset($metrics['missing_word_count'])) {
            $vars['missing'] = $vars['missing'] ?? self::formatCount((int) $metrics['missing_word_count'], str_starts_with($locale, 'en') ? 'en' : 'vi');
        }

        $looksLikeCode = static fn (string $value): bool => (bool) preg_match('/^[a-z0-9]+(?:_[a-z0-9]+)+$/i', trim($value));

        $summary = $summaryRaw !== '' && $summaryRaw !== 'seo_rules.'.$normalized
            ? self::interpolate($summaryRaw, $vars)
            : '';
        $label = $labelRaw !== '' && $labelRaw !== 'seo_rules.'.$normalized.'_label'
            ? self::interpolate($labelRaw, $vars)
            : '';
        $detail = $detailRaw !== '' && $detailRaw !== 'seo_rules.'.$normalized.'_detail'
            ? self::interpolate($detailRaw, $vars)
            : '';

        if ($summary === '' || $looksLikeCode($summary) || $summary === $normalized) {
            $summary = self::safeFallback($normalized, $locale);
        }
        if ($label === '' || $looksLikeCode($label)) {
            $label = $summary;
        }
        if ($detail === '' || $looksLikeCode($detail)) {
            $detail = $summary;
        }

        return [
            'code' => $normalized,
            'label' => $label,
            'summary' => $summary,
            'detail' => $detail,
        ];
    }

    public static function safeFallback(string $key, string $locale = 'vi'): string
    {
        $isEn = str_starts_with($locale, 'en');

        return match ($key) {
            'content_length_low' => $isEn
                ? 'Content is below the recommended length'
                : 'Nội dung chưa đạt độ dài đề xuất',
            'image_ratio_low', 'image_ratio_poor', 'image_ratio_missing', 'image_ratio_suboptimal' => $isEn
                ? 'Image ratio is below recommendation'
                : 'Tỷ lệ hình ảnh chưa đạt đề xuất',
            'missing_focus_keyword' => $isEn
                ? 'Focus keyword is missing'
                : 'Thiếu từ khóa chính',
            default => $isEn
                ? 'SEO check needs attention'
                : 'Cần kiểm tra tiêu chí SEO',
        };
    }
}
