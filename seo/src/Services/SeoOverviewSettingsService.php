<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services;

use Omnichannel\Addons\Seo\Support\FaqHeadingMatcher;
use App\Models\WpOption;

final class SeoOverviewSettingsService
{
    /** @var array<string, mixed>|null */
    private ?array $inMemorySettings = null;

    public const OPTION_KEY = 'seo_overview_settings';

    public const KEY_FAQ_CATCH_KEYWORDS = 'faq_catch_keywords';

    public const KEY_OUTLINE_SKIP_WORDS = 'outline_skip_words';

    public const KEY_TEAM_CHAT_ALLOWED_EXTENSIONS = 'team_chat_allowed_extensions';

    public const KEY_TEAM_CHAT_MAX_FILE_SIZE_MB = 'team_chat_max_file_size_mb';

    /** @var list<string> */
    private const DEFAULT_TEAM_CHAT_ALLOWED_EXTENSIONS = [
        'jpg',
        'jpeg',
        'png',
        'webp',
        'gif',
        'pdf',
        'doc',
        'docx',
        'xls',
        'xlsx',
        'zip',
        'txt',
    ];

    private const DEFAULT_TEAM_CHAT_MAX_FILE_SIZE_MB = 10;

    /** @var list<string> */
    private const DEFAULT_OUTLINE_SKIP_WORDS = [
        'giới thiệu',
        'kết luận',
        'faq',
        'câu hỏi thường gặp',
    ];

    /** @var list<string> */
    private const DEFAULT_FAQ_CATCH_KEYWORDS = [
        'faq',
        'faqs',
        'frequently asked questions',
        'frequently asked question',
        'common questions',
        'common question',
        'questions and answers',
        'question and answer',
        'q&a',
        'q & a',
        'faq section',
        'faq questions',
        'common faq',
        'câu hỏi thường gặp',
        'các câu hỏi thường gặp',
        'hỏi đáp',
        'giải đáp',
        'giải đáp thắc mắc',
        'thắc mắc thường gặp',
        'câu hỏi',
    ];

    public static function withDefaults(): self
    {
        $service = new self();
        $service->inMemorySettings = $service->defaultSettings();

        return $service;
    }

    /**
     * @param  list<string>  $keywords
     */
    public static function withFaqCatchKeywords(array $keywords): self
    {
        $service = new self();
        $normalized = $service->normalizeKeywords($keywords);
        $service->inMemorySettings = [
            self::KEY_FAQ_CATCH_KEYWORDS => $normalized !== [] ? $normalized : self::DEFAULT_FAQ_CATCH_KEYWORDS,
        ];

        return $service;
    }

    /**
     * @return array{
     *     faq_catch_keywords: list<string>,
     *     outline_skip_words: list<string>,
     *     team_chat_allowed_extensions: list<string>,
     *     team_chat_max_file_size_mb: int,
     * }
     */
    public function getSettings(): array
    {
        if ($this->inMemorySettings !== null) {
            return $this->mergeTeamChatDefaults($this->inMemorySettings);
        }

        $data = WpOption::get(self::OPTION_KEY, []);

        if (! is_array($data)) {
            return $this->defaultSettings();
        }

        $keywords = $this->normalizeKeywords($data[self::KEY_FAQ_CATCH_KEYWORDS] ?? null);
        if ($keywords !== [] && $this->hasByteCorruptedUtf8Labels($keywords)) {
            $keywords = [];
        }

        $skipWords = array_key_exists(self::KEY_OUTLINE_SKIP_WORDS, $data)
            ? $this->normalizeKeywords($data[self::KEY_OUTLINE_SKIP_WORDS])
            : self::DEFAULT_OUTLINE_SKIP_WORDS;
        if ($skipWords !== [] && $this->hasByteCorruptedUtf8Labels($skipWords)) {
            $skipWords = self::DEFAULT_OUTLINE_SKIP_WORDS;
        }

        return $this->mergeTeamChatDefaults([
            self::KEY_FAQ_CATCH_KEYWORDS => $keywords !== [] ? $keywords : self::DEFAULT_FAQ_CATCH_KEYWORDS,
            self::KEY_OUTLINE_SKIP_WORDS => $skipWords,
            self::KEY_TEAM_CHAT_ALLOWED_EXTENSIONS => $data[self::KEY_TEAM_CHAT_ALLOWED_EXTENSIONS] ?? null,
            self::KEY_TEAM_CHAT_MAX_FILE_SIZE_MB => $data[self::KEY_TEAM_CHAT_MAX_FILE_SIZE_MB] ?? null,
        ]);
    }

    /**
     * @return list<string>
     */
    public function getFaqCatchKeywords(): array
    {
        $keywords = $this->getSettings()[self::KEY_FAQ_CATCH_KEYWORDS];

        return $this->normalizeKeywords($keywords);
    }

    public function faqHeadingMatcher(): FaqHeadingMatcher
    {
        return new FaqHeadingMatcher($this->getFaqCatchKeywords());
    }

    /**
     * Legacy skip-list stored with overview settings. Unused by active outline workflow
     * after cross-article heading comparison was retired. Kept so existing option JSON remains valid.
     *
     * @return list<string>
     */
    public function getOutlineSkipWords(): array
    {
        return $this->getSettings()[self::KEY_OUTLINE_SKIP_WORDS];
    }

    /**
     * @return list<string>
     */
    public function getTeamChatAllowedExtensions(): array
    {
        return $this->getSettings()[self::KEY_TEAM_CHAT_ALLOWED_EXTENSIONS];
    }

    public function getTeamChatMaxFileSizeMb(): int
    {
        return (int) $this->getSettings()[self::KEY_TEAM_CHAT_MAX_FILE_SIZE_MB];
    }

    public function extensionsToTextarea(array $extensions): string
    {
        return implode("\n", $this->normalizeExtensions($extensions));
    }

    /**
     * @return list<string>
     */
    public function extensionsFromTextarea(string $raw): array
    {
        $lines = preg_split('/\r\n|\r|\n|,/', $raw) ?: [];

        return $this->normalizeExtensions($lines);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function saveSettings(array $settings): void
    {
        $current = WpOption::get(self::OPTION_KEY, []);
        if (! is_array($current)) {
            $current = [];
        }

        $keywords = $this->normalizeKeywords($settings[self::KEY_FAQ_CATCH_KEYWORDS] ?? null);
        $skipWords = array_key_exists(self::KEY_OUTLINE_SKIP_WORDS, $settings)
            ? $this->normalizeKeywords($settings[self::KEY_OUTLINE_SKIP_WORDS])
            : ($current[self::KEY_OUTLINE_SKIP_WORDS] ?? self::DEFAULT_OUTLINE_SKIP_WORDS);

        $payload = [
            self::KEY_FAQ_CATCH_KEYWORDS => $keywords !== [] ? $keywords : self::DEFAULT_FAQ_CATCH_KEYWORDS,
            self::KEY_OUTLINE_SKIP_WORDS => is_array($skipWords) ? $skipWords : self::DEFAULT_OUTLINE_SKIP_WORDS,
            self::KEY_TEAM_CHAT_ALLOWED_EXTENSIONS => $current[self::KEY_TEAM_CHAT_ALLOWED_EXTENSIONS]
                ?? self::DEFAULT_TEAM_CHAT_ALLOWED_EXTENSIONS,
            self::KEY_TEAM_CHAT_MAX_FILE_SIZE_MB => $current[self::KEY_TEAM_CHAT_MAX_FILE_SIZE_MB]
                ?? self::DEFAULT_TEAM_CHAT_MAX_FILE_SIZE_MB,
        ];

        if (array_key_exists(self::KEY_TEAM_CHAT_ALLOWED_EXTENSIONS, $settings)) {
            $extensions = $this->normalizeExtensions($settings[self::KEY_TEAM_CHAT_ALLOWED_EXTENSIONS]);
            $payload[self::KEY_TEAM_CHAT_ALLOWED_EXTENSIONS] = $extensions !== []
                ? $extensions
                : self::DEFAULT_TEAM_CHAT_ALLOWED_EXTENSIONS;
        }

        if (array_key_exists(self::KEY_TEAM_CHAT_MAX_FILE_SIZE_MB, $settings)) {
            $payload[self::KEY_TEAM_CHAT_MAX_FILE_SIZE_MB] = $this->normalizeMaxFileSizeMb(
                $settings[self::KEY_TEAM_CHAT_MAX_FILE_SIZE_MB],
            );
        }

        WpOption::set(self::OPTION_KEY, $payload, 'no');

        $this->inMemorySettings = null;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function saveTeamChatSettings(array $settings): void
    {
        $current = $this->getSettings();

        $extensionsRaw = $settings[self::KEY_TEAM_CHAT_ALLOWED_EXTENSIONS] ?? null;
        $extensions = is_string($extensionsRaw)
            ? $this->extensionsFromTextarea($extensionsRaw)
            : $this->normalizeExtensions($extensionsRaw);

        $this->saveSettings([
            self::KEY_FAQ_CATCH_KEYWORDS => $current[self::KEY_FAQ_CATCH_KEYWORDS],
            self::KEY_OUTLINE_SKIP_WORDS => $current[self::KEY_OUTLINE_SKIP_WORDS],
            self::KEY_TEAM_CHAT_ALLOWED_EXTENSIONS => $extensions,
            self::KEY_TEAM_CHAT_MAX_FILE_SIZE_MB => $settings[self::KEY_TEAM_CHAT_MAX_FILE_SIZE_MB]
                ?? $current[self::KEY_TEAM_CHAT_MAX_FILE_SIZE_MB],
        ]);
    }

    /**
     * Chuyển textarea (mỗi dòng một từ khóa) thành danh sách đã chuẩn hóa.
     */
    public function keywordsFromTextarea(string $raw): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];

        return $this->normalizeKeywords($lines);
    }

    public function keywordsToTextarea(array $keywords): string
    {
        return implode("\n", $this->normalizeKeywords($keywords));
    }

    /**
     * @return array{
     *     faq_catch_keywords: list<string>,
     *     outline_skip_words: list<string>,
     *     team_chat_allowed_extensions: list<string>,
     *     team_chat_max_file_size_mb: int,
     * }
     */
    private function defaultSettings(): array
    {
        return [
            self::KEY_FAQ_CATCH_KEYWORDS => self::DEFAULT_FAQ_CATCH_KEYWORDS,
            self::KEY_OUTLINE_SKIP_WORDS => self::DEFAULT_OUTLINE_SKIP_WORDS,
            self::KEY_TEAM_CHAT_ALLOWED_EXTENSIONS => self::DEFAULT_TEAM_CHAT_ALLOWED_EXTENSIONS,
            self::KEY_TEAM_CHAT_MAX_FILE_SIZE_MB => self::DEFAULT_TEAM_CHAT_MAX_FILE_SIZE_MB,
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{
     *     faq_catch_keywords: list<string>,
     *     outline_skip_words: list<string>,
     *     team_chat_allowed_extensions: list<string>,
     *     team_chat_max_file_size_mb: int,
     * }
     */
    private function mergeTeamChatDefaults(array $settings): array
    {
        $extensions = $this->normalizeExtensions($settings[self::KEY_TEAM_CHAT_ALLOWED_EXTENSIONS] ?? null);

        return [
            self::KEY_FAQ_CATCH_KEYWORDS => $settings[self::KEY_FAQ_CATCH_KEYWORDS] ?? self::DEFAULT_FAQ_CATCH_KEYWORDS,
            self::KEY_OUTLINE_SKIP_WORDS => $settings[self::KEY_OUTLINE_SKIP_WORDS] ?? self::DEFAULT_OUTLINE_SKIP_WORDS,
            self::KEY_TEAM_CHAT_ALLOWED_EXTENSIONS => $extensions !== []
                ? $extensions
                : self::DEFAULT_TEAM_CHAT_ALLOWED_EXTENSIONS,
            self::KEY_TEAM_CHAT_MAX_FILE_SIZE_MB => $this->normalizeMaxFileSizeMb(
                $settings[self::KEY_TEAM_CHAT_MAX_FILE_SIZE_MB] ?? self::DEFAULT_TEAM_CHAT_MAX_FILE_SIZE_MB,
            ),
        ];
    }

    /**
     * @return list<string>
     */
    private function normalizeExtensions(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = preg_split('/\r\n|\r|\n|,/', $raw) ?: [];
        }

        if (! is_array($raw)) {
            return [];
        }

        $extensions = [];
        foreach ($raw as $item) {
            $value = strtolower(trim(is_string($item) ? $item : (string) $item));
            $value = ltrim($value, '.');
            if ($value === '' || ! preg_match('/^[a-z0-9]{1,12}$/', $value)) {
                continue;
            }

            if (! in_array($value, $extensions, true)) {
                $extensions[] = $value;
            }
        }

        return $extensions;
    }

    private function normalizeMaxFileSizeMb(mixed $value): int
    {
        $size = (int) $value;

        return max(1, min(100, $size > 0 ? $size : self::DEFAULT_TEAM_CHAT_MAX_FILE_SIZE_MB));
    }

    /**
     * @return list<string>
     */
    private function normalizeKeywords(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        }

        if (! is_array($raw)) {
            return [];
        }

        $keywords = [];
        foreach ($raw as $item) {
            $label = trim(is_string($item) ? $item : (string) ($item['keyword'] ?? $item['label'] ?? ''));
            if ($label === '') {
                continue;
            }

            $lower = mb_strtolower($label, 'UTF-8');
            if (in_array($lower, $keywords, true)) {
                continue;
            }

            $keywords[] = $lower;
        }

        return $keywords;
    }

    /**
     * Phát hiện chuỗi bị thay byte UTF-8 bằng '?' (vd. "câu hỏi" → "c??u h???i").
     *
     * @param  list<string>  $labels
     */
    private function hasByteCorruptedUtf8Labels(array $labels): bool
    {
        foreach ($labels as $label) {
            if (preg_match('/\?\?\?/', $label) === 1) {
                return true;
            }
        }

        return false;
    }
}
