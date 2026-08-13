<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use App\Models\WpOption;

final class SeoPromptSettingsService
{
    /** @var array<string, mixed>|null */
    private ?array $inMemorySettings = null;

    public const OPTION_KEY = 'seo_prompt_settings';

    public const KEY_TONE_OF_VOICE = 'tone_of_voice';

    /** @deprecated Dùng {@see KEY_FEATURED_SNIPPET_ROWS_MAX}; giữ để tương thích wp_options cũ. */
    public const KEY_FEATURED_SNIPPET_MIN_ROWS = 'featured_snippet_min_rows';

    /** Ngưỡng dòng dữ liệu — trung bình (điểm một phần). */
    public const KEY_FEATURED_SNIPPET_ROWS_MIN = 'featured_snippet_rows_min';

    /** Ngưỡng dòng dữ liệu — tốt. */
    public const KEY_FEATURED_SNIPPET_ROWS_RANGE = 'featured_snippet_rows_range';

    /** Ngưỡng dòng dữ liệu — rất tốt (đủ 10 điểm). */
    public const KEY_FEATURED_SNIPPET_ROWS_MAX = 'featured_snippet_rows_max';

    public const KEY_FEATURED_SNIPPET_MIN_COLUMNS = 'featured_snippet_min_columns';

    public const KEY_FEATURED_SNIPPET_MAX_COLUMNS = 'featured_snippet_max_columns';

    /** Prompt sinh Featured Snippet trên editor bài viết (biến {{input}} = từ khóa chính). */
    public const KEY_FEATURED_SNIPPET_PROMPT_ID = 'featured_snippet_prompt_id';

    /** Nội dung chèn vào prompt qua {{tone}}. */
    public const KEY_TONE_TEXT = 'tone_text';

    /** Độ dài bài (số từ) — product: {{article_length_product}}, còn lại: {{article_length_default}}; runtime: {{article_length}}. */
    public const KEY_ARTICLE_LENGTH_PRODUCT = 'article_length_product';

    public const KEY_ARTICLE_LENGTH_DEFAULT = 'article_length_default';

    /** Mật độ từ khóa — product: {{keyword_density_product}}, còn lại: {{keyword_density_default}}; runtime: {{keyword_density}}. */
    public const KEY_KEYWORD_DENSITY_PRODUCT = 'keyword_density_product';

    public const KEY_KEYWORD_DENSITY_DEFAULT = 'keyword_density_default';

    /** Ngôn ngữ mặc định cho prompt khi site không dùng Polylang (slug, vd. vi). */
    public const KEY_DEFAULT_PROMPT_LANGUAGE = 'default_prompt_language';

    private const DEFAULT_PROMPT_LANGUAGE = 'vi';

    private const DEFAULT_TONE_TEXT = 'Viết bằng giọng văn chuyên nghiệp, rõ ràng, phù hợp đối tượng đọc tại Việt Nam.';

    private const DEFAULT_ARTICLE_LENGTH_PRODUCT = '1000';

    private const DEFAULT_ARTICLE_LENGTH_DEFAULT = '2000';

    private const DEFAULT_KEYWORD_DENSITY_PRODUCT = 'Mật độ từ khóa tự nhiên; ưu tiên mô tả sản phẩm, thông số và lợi ích, tránh nhồi nhét.';

    private const DEFAULT_KEYWORD_DENSITY_DEFAULT = 'Mật độ từ khóa chính tự nhiên (khoảng 0,8–1,2%), phân bổ ở tiêu đề, đoạn mở, các heading H2/H3 và thân bài.';

    /** @var list<string> */
    private const DEFAULT_TONES = [
        'Chuyên nghiệp',
        'Thân thiện, gần gũi',
        'Trang trọng, uy tín',
        'Năng động, trẻ trung',
        'Khách quan, trung lập',
        'Thuyết phục, bán hàng',
        'Giáo dục, hướng dẫn',
        'Hài hước nhẹ nhàng',
        'Cao cấp (premium)',
        'Địa phương, thuần Việt',
    ];

    /**
     * @return array{
     *     tone_of_voice: list<string>,
     *     featured_snippet_min_rows: int,
     *     featured_snippet_min_columns: int,
     *     featured_snippet_max_columns: int,
     * }
     */
    /**
     * Dùng trong unit test — không truy vấn wp_options.
     */
    public static function withDefaults(): self
    {
        $service = new self;
        $service->inMemorySettings = $service->defaultSettings();

        return $service;
    }

    public function getSettings(): array
    {
        if ($this->inMemorySettings !== null) {
            return $this->inMemorySettings;
        }

        $data = WpOption::get(self::OPTION_KEY, []);

        if (! is_array($data)) {
            return $this->defaultSettings();
        }

        $tones = $this->normalizeTones($data[self::KEY_TONE_OF_VOICE] ?? null);
        if ($tones !== [] && $this->hasByteCorruptedUtf8Labels($tones)) {
            $tones = [];
        }

        $toneText = $this->normalizeText(
            $data[self::KEY_TONE_TEXT] ?? null,
            self::DEFAULT_TONE_TEXT,
        );
        if ($this->isByteCorruptedUtf8Label($toneText)) {
            $toneText = self::DEFAULT_TONE_TEXT;
        }

        $keywordDensityProduct = $this->normalizeText(
            $data[self::KEY_KEYWORD_DENSITY_PRODUCT] ?? null,
            self::DEFAULT_KEYWORD_DENSITY_PRODUCT,
        );
        if ($this->isByteCorruptedUtf8Label($keywordDensityProduct)) {
            $keywordDensityProduct = self::DEFAULT_KEYWORD_DENSITY_PRODUCT;
        }

        $keywordDensityDefault = $this->normalizeText(
            $data[self::KEY_KEYWORD_DENSITY_DEFAULT] ?? null,
            self::DEFAULT_KEYWORD_DENSITY_DEFAULT,
        );
        if ($this->isByteCorruptedUtf8Label($keywordDensityDefault)) {
            $keywordDensityDefault = self::DEFAULT_KEYWORD_DENSITY_DEFAULT;
        }

        return [
            self::KEY_TONE_OF_VOICE => $tones !== [] ? $tones : self::DEFAULT_TONES,
            self::KEY_TONE_TEXT => $toneText,
            self::KEY_ARTICLE_LENGTH_PRODUCT => $this->normalizeText(
                $data[self::KEY_ARTICLE_LENGTH_PRODUCT] ?? null,
                self::DEFAULT_ARTICLE_LENGTH_PRODUCT,
            ),
            self::KEY_ARTICLE_LENGTH_DEFAULT => $this->normalizeText(
                $data[self::KEY_ARTICLE_LENGTH_DEFAULT] ?? null,
                self::DEFAULT_ARTICLE_LENGTH_DEFAULT,
            ),
            self::KEY_KEYWORD_DENSITY_PRODUCT => $keywordDensityProduct,
            self::KEY_KEYWORD_DENSITY_DEFAULT => $keywordDensityDefault,
            ...$this->normalizeFeaturedSnippetRowTiers($data),
            self::KEY_FEATURED_SNIPPET_MIN_COLUMNS => $this->intInRange(
                $data[self::KEY_FEATURED_SNIPPET_MIN_COLUMNS] ?? null,
                1,
                10,
                2,
            ),
            self::KEY_FEATURED_SNIPPET_MAX_COLUMNS => $this->intInRange(
                $data[self::KEY_FEATURED_SNIPPET_MAX_COLUMNS] ?? null,
                1,
                10,
                5,
            ),
            self::KEY_FEATURED_SNIPPET_PROMPT_ID => $this->positiveIntOrNull(
                $data[self::KEY_FEATURED_SNIPPET_PROMPT_ID] ?? null,
            ),
            self::KEY_DEFAULT_PROMPT_LANGUAGE => $this->normalizeLanguageSlug(
                $data[self::KEY_DEFAULT_PROMPT_LANGUAGE] ?? null,
                self::DEFAULT_PROMPT_LANGUAGE,
            ),
        ];
    }

    public function getDefaultPromptLanguageSlug(): string
    {
        return $this->getSettings()[self::KEY_DEFAULT_PROMPT_LANGUAGE];
    }

    public function getFeaturedSnippetPromptId(): ?int
    {
        return $this->getSettings()[self::KEY_FEATURED_SNIPPET_PROMPT_ID];
    }

    /**
     * Biến global cho prompt (SEO → Tùy chỉnh → Prompt).
     *
     * @return array<string, string>
     */
    public function promptVariables(?string $postType = null): array
    {
        $settings = $this->getSettings();
        $isProduct = self::isProductPostType($postType);

        return [
            'tone' => $settings[self::KEY_TONE_TEXT],
            // Luôn số thuần (từ) — hook input type=integer + prompt {{article_length}}.
            'article_length' => (string) $this->resolveArticleLengthTarget($postType),
            'keyword_density' => $isProduct
                ? $settings[self::KEY_KEYWORD_DENSITY_PRODUCT]
                : $settings[self::KEY_KEYWORD_DENSITY_DEFAULT],
            'article_length_product' => (string) $this->resolveArticleLengthTarget('product'),
            'article_length_default' => (string) $this->resolveArticleLengthTarget('article'),
            'keyword_density_product' => $settings[self::KEY_KEYWORD_DENSITY_PRODUCT],
            'keyword_density_default' => $settings[self::KEY_KEYWORD_DENSITY_DEFAULT],
        ];
    }

    public static function isProductPostType(?string $postType): bool
    {
        return trim((string) $postType) === 'product';
    }

    public function resolveArticleLengthTarget(?string $postType = null): int
    {
        $settings = $this->getSettings();
        $isProduct = self::isProductPostType($postType);
        $raw = $isProduct
            ? (string) $settings[self::KEY_ARTICLE_LENGTH_PRODUCT]
            : (string) $settings[self::KEY_ARTICLE_LENGTH_DEFAULT];
        $fallback = $isProduct
            ? (int) self::DEFAULT_ARTICLE_LENGTH_PRODUCT
            : (int) self::DEFAULT_ARTICLE_LENGTH_DEFAULT;

        return self::parseArticleLengthTarget($raw, $fallback);
    }

    public static function parseArticleLengthTarget(string $raw, int $fallback = 2000): int
    {
        if (preg_match('/(\d+)/', $raw, $matches) === 1) {
            return max(1, (int) $matches[1]);
        }

        return max(1, $fallback);
    }

    /**
     * @return list<string>
     */
    public function getToneOfVoiceOptions(): array
    {
        return $this->getSettings()[self::KEY_TONE_OF_VOICE];
    }

    /**
     * Options cho Select giọng văn domain (SEO → Tùy chỉnh → Prompt → Tone of voice).
     *
     * @return array<string, string>
     */
    public function toneOfVoiceSelectOptions(?string $includeStoredValue = null): array
    {
        $options = [];

        foreach ($this->getToneOfVoiceOptions() as $tone) {
            $options[$tone] = $tone;
        }

        $stored = trim((string) $includeStoredValue);
        if ($stored !== '' && ! isset($options[$stored])) {
            $options[$stored] = $stored;
        }

        return $options;
    }

    /**
     * @return array{
     *     rows_min: int,
     *     rows_range: int,
     *     rows_max: int,
     *     min_rows: int,
     *     min_columns: int,
     *     max_columns: int,
     * }
     */
    public function getFeaturedSnippetThresholds(): array
    {
        $settings = $this->getSettings();
        $minCols = $settings[self::KEY_FEATURED_SNIPPET_MIN_COLUMNS];
        $maxCols = max($minCols, $settings[self::KEY_FEATURED_SNIPPET_MAX_COLUMNS]);
        $rowsMax = $settings[self::KEY_FEATURED_SNIPPET_ROWS_MAX];

        return [
            'rows_min' => $settings[self::KEY_FEATURED_SNIPPET_ROWS_MIN],
            'rows_range' => $settings[self::KEY_FEATURED_SNIPPET_ROWS_RANGE],
            'rows_max' => $rowsMax,
            'min_rows' => $rowsMax,
            'min_columns' => $minCols,
            'max_columns' => $maxCols,
        ];
    }

    /**
     * Số hàng markdown được đếm (gồm header) tối thiểu để đạt mức rất tốt Featured Snippet.
     */
    public function featuredSnippetMinMarkdownRowCount(): int
    {
        return $this->getFeaturedSnippetThresholds()['rows_max'] + 1;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function saveSettings(array $settings): void
    {
        $tones = $this->normalizeTones($settings[self::KEY_TONE_OF_VOICE] ?? null);
        $minCols = $this->intInRange(
            $settings[self::KEY_FEATURED_SNIPPET_MIN_COLUMNS] ?? null,
            1,
            10,
            2,
        );
        $maxCols = $this->intInRange(
            $settings[self::KEY_FEATURED_SNIPPET_MAX_COLUMNS] ?? null,
            1,
            10,
            5,
        );

        if ($maxCols < $minCols) {
            $maxCols = $minCols;
        }

        WpOption::set(self::OPTION_KEY, [
            self::KEY_TONE_OF_VOICE => $tones !== [] ? $tones : self::DEFAULT_TONES,
            self::KEY_TONE_TEXT => $this->normalizeText(
                $settings[self::KEY_TONE_TEXT] ?? null,
                self::DEFAULT_TONE_TEXT,
            ),
            self::KEY_ARTICLE_LENGTH_PRODUCT => $this->normalizeText(
                $settings[self::KEY_ARTICLE_LENGTH_PRODUCT] ?? null,
                self::DEFAULT_ARTICLE_LENGTH_PRODUCT,
            ),
            self::KEY_ARTICLE_LENGTH_DEFAULT => $this->normalizeText(
                $settings[self::KEY_ARTICLE_LENGTH_DEFAULT] ?? null,
                self::DEFAULT_ARTICLE_LENGTH_DEFAULT,
            ),
            self::KEY_KEYWORD_DENSITY_PRODUCT => $this->normalizeText(
                $settings[self::KEY_KEYWORD_DENSITY_PRODUCT] ?? null,
                self::DEFAULT_KEYWORD_DENSITY_PRODUCT,
            ),
            self::KEY_KEYWORD_DENSITY_DEFAULT => $this->normalizeText(
                $settings[self::KEY_KEYWORD_DENSITY_DEFAULT] ?? null,
                self::DEFAULT_KEYWORD_DENSITY_DEFAULT,
            ),
            ...$this->normalizeFeaturedSnippetRowTiers($settings),
            self::KEY_FEATURED_SNIPPET_MIN_COLUMNS => $minCols,
            self::KEY_FEATURED_SNIPPET_MAX_COLUMNS => $maxCols,
            self::KEY_FEATURED_SNIPPET_PROMPT_ID => $this->positiveIntOrNull(
                $settings[self::KEY_FEATURED_SNIPPET_PROMPT_ID] ?? null,
            ),
            self::KEY_DEFAULT_PROMPT_LANGUAGE => $this->normalizeLanguageSlug(
                $settings[self::KEY_DEFAULT_PROMPT_LANGUAGE] ?? null,
                self::DEFAULT_PROMPT_LANGUAGE,
            ),
        ], 'no');
    }

    private function normalizeLanguageSlug(mixed $value, string $default): string
    {
        $slug = strtolower(trim((string) ($value ?? '')));

        return $slug !== '' ? mb_substr($slug, 0, 16) : $default;
    }

    /**
     * @return array{
     *     tone_of_voice: list<string>,
     *     featured_snippet_min_rows: int,
     *     featured_snippet_min_columns: int,
     *     featured_snippet_max_columns: int,
     * }
     */
    private function defaultSettings(): array
    {
        return [
            self::KEY_TONE_OF_VOICE => self::DEFAULT_TONES,
            self::KEY_TONE_TEXT => self::DEFAULT_TONE_TEXT,
            self::KEY_ARTICLE_LENGTH_PRODUCT => self::DEFAULT_ARTICLE_LENGTH_PRODUCT,
            self::KEY_ARTICLE_LENGTH_DEFAULT => self::DEFAULT_ARTICLE_LENGTH_DEFAULT,
            self::KEY_KEYWORD_DENSITY_PRODUCT => self::DEFAULT_KEYWORD_DENSITY_PRODUCT,
            self::KEY_KEYWORD_DENSITY_DEFAULT => self::DEFAULT_KEYWORD_DENSITY_DEFAULT,
            self::KEY_FEATURED_SNIPPET_ROWS_MIN => 6,
            self::KEY_FEATURED_SNIPPET_ROWS_RANGE => 8,
            self::KEY_FEATURED_SNIPPET_ROWS_MAX => 10,
            self::KEY_FEATURED_SNIPPET_MIN_ROWS => 10,
            self::KEY_FEATURED_SNIPPET_MIN_COLUMNS => 2,
            self::KEY_FEATURED_SNIPPET_MAX_COLUMNS => 5,
            self::KEY_FEATURED_SNIPPET_PROMPT_ID => null,
            self::KEY_DEFAULT_PROMPT_LANGUAGE => self::DEFAULT_PROMPT_LANGUAGE,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{
     *     featured_snippet_rows_min: int,
     *     featured_snippet_rows_range: int,
     *     featured_snippet_rows_max: int,
     *     featured_snippet_min_rows: int,
     * }
     */
    private function normalizeFeaturedSnippetRowTiers(array $data): array
    {
        $rowsMax = $this->intInRange(
            $data[self::KEY_FEATURED_SNIPPET_ROWS_MAX]
                ?? $data[self::KEY_FEATURED_SNIPPET_MIN_ROWS]
                ?? null,
            1,
            50,
            10,
        );
        $rowsRange = $this->intInRange(
            $data[self::KEY_FEATURED_SNIPPET_ROWS_RANGE] ?? null,
            1,
            50,
            max(1, $rowsMax - 2),
        );
        $rowsMin = $this->intInRange(
            $data[self::KEY_FEATURED_SNIPPET_ROWS_MIN] ?? null,
            1,
            50,
            max(1, $rowsMax - 4),
        );

        if ($rowsMin > $rowsRange) {
            $rowsMin = $rowsRange;
        }
        if ($rowsRange > $rowsMax) {
            $rowsRange = $rowsMax;
        }
        if ($rowsMin > $rowsRange) {
            $rowsMin = $rowsRange;
        }

        return [
            self::KEY_FEATURED_SNIPPET_ROWS_MIN => $rowsMin,
            self::KEY_FEATURED_SNIPPET_ROWS_RANGE => $rowsRange,
            self::KEY_FEATURED_SNIPPET_ROWS_MAX => $rowsMax,
            self::KEY_FEATURED_SNIPPET_MIN_ROWS => $rowsMax,
        ];
    }

    private function normalizeText(mixed $value, string $default): string
    {
        $text = trim((string) ($value ?? ''));

        return $text !== '' ? $text : $default;
    }

    /**
     * @return list<string>
     */
    private function normalizeTones(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $tones = [];
        foreach ($raw as $item) {
            if (is_string($item)) {
                $label = trim($item);
            } elseif (is_array($item)) {
                $label = trim((string) ($item['label'] ?? $item['tone'] ?? ''));
            } else {
                continue;
            }

            if ($label === '' || in_array($label, $tones, true)) {
                continue;
            }

            $tones[] = $label;
        }

        return $tones;
    }

    private function intInRange(mixed $value, int $min, int $max, int $default): int
    {
        if ($value === null || $value === '') {
            return $default;
        }

        $int = (int) $value;

        return max($min, min($max, $int));
    }

    private function positiveIntOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    /**
     * Phát hiện chuỗi bị thay byte UTF-8 bằng '?' (vd. "Chuyên nghiệp" → "Chuy??n nghi???p").
     *
     * @param  list<string>  $labels
     */
    private function hasByteCorruptedUtf8Labels(array $labels): bool
    {
        foreach ($labels as $label) {
            if ($this->isByteCorruptedUtf8Label($label)) {
                return true;
            }
        }

        return false;
    }

    private function isByteCorruptedUtf8Label(string $label): bool
    {
        return preg_match('/\?\?\?/', $label) === 1;
    }
}
