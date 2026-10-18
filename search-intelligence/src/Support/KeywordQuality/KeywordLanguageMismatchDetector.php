<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Support\KeywordQuality;

use Omnichannel\Addons\Content\Support\ArticleLanguageCode;

/**
 * High-precision keyword language check. No model, no network.
 * Short, brand, acronym, and mixed phrases stay uncertain.
 */
final class KeywordLanguageMismatchDetector
{
    public const MATCH = 'match';

    public const MISMATCH = 'mismatch';

    public const UNCERTAIN = 'uncertain';

    private const VI_MARK = '/[àáảãạăằắẳẵặâầấẩẫậèéẻẽẹêềếểễệìíỉĩịòóỏõọôồốổỗộơờớởỡợùúủũụưừứửữựỳýỷỹỵđ]/u';

    /** @var list<string> */
    private const EN_FUNCTION = [
        'the', 'and', 'for', 'with', 'of', 'to', 'in', 'on', 'your', 'our', 'from', 'by', 'at',
        'how', 'what', 'where', 'when', 'who', 'why', 'this', 'that', 'these', 'those', 'into',
        'over', 'about', 'than', 'or', 'are', 'best',
    ];

    /** @var list<string> */
    private const VI_BARE = [
        'cho', 'cua', 'voi', 'trong', 'nhung', 'khong', 'duoc',
    ];

    public function verdict(string $phrase, string $inventoryLanguage): string
    {
        $inventory = $this->base($inventoryLanguage);
        if ($inventory === '' || trim($phrase) === '') {
            return self::UNCERTAIN;
        }

        $rawTokens = preg_split('/[^\p{L}\p{N}]+/u', trim($phrase), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($rawTokens) < 4) {
            return self::UNCERTAIN;
        }

        $folded = mb_strtolower($phrase);
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $folded, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($this->acronymHeavy($rawTokens)) {
            return self::UNCERTAIN;
        }

        $diacriticTokens = 0;
        $enFunction = 0;
        $asciiContent = 0;
        $viBare = 0;
        foreach ($tokens as $token) {
            if (preg_match(self::VI_MARK, $token) === 1) {
                $diacriticTokens++;
            }
            if (in_array($token, self::EN_FUNCTION, true)) {
                $enFunction++;
            }
            if (in_array($token, self::VI_BARE, true)) {
                $viBare++;
            }
            if (preg_match('/^[a-z]{3,}$/', $token) === 1 && ! in_array($token, self::EN_FUNCTION, true)) {
                $asciiContent++;
            }
        }

        $foreign = $this->foreignScript($phrase);
        $strongVi = $diacriticTokens >= 2 && $enFunction < 2 && $foreign === null;
        $strongEn = $diacriticTokens === 0
            && $enFunction >= 2
            && $asciiContent >= 2
            && $viBare === 0
            && $foreign === null;

        if ($strongVi && $strongEn) {
            return self::UNCERTAIN;
        }
        if ($strongVi) {
            return $inventory === 'vi' ? self::MATCH : self::MISMATCH;
        }
        if ($strongEn) {
            return $inventory === 'en' ? self::MATCH : self::MISMATCH;
        }
        if ($foreign !== null) {
            return $this->script($inventory) === $foreign ? self::MATCH : self::MISMATCH;
        }

        return self::UNCERTAIN;
    }

    /**
     * @param  list<string>  $rawTokens
     */
    private function acronymHeavy(array $rawTokens): bool
    {
        $acronyms = 0;
        foreach ($rawTokens as $token) {
            if (preg_match('/^[A-Z0-9]{2,6}$/', $token) === 1) {
                $acronyms++;
            }
        }

        return $acronyms > 0 && $acronyms * 2 >= count($rawTokens);
    }

    private function foreignScript(string $phrase): ?string
    {
        $latin = preg_match_all('/\p{Latin}/u', $phrase);
        if (is_int($latin) && $latin >= 4) {
            return null;
        }

        $checks = [
            'ko' => '/\p{Hangul}/u',
            'th' => '/\p{Thai}/u',
            'ar' => '/\p{Arabic}/u',
            'cyrl' => '/\p{Cyrillic}/u',
            'ja' => '/[\p{Hiragana}\p{Katakana}]/u',
            'han' => '/\p{Han}/u',
        ];
        foreach ($checks as $script => $pattern) {
            $count = preg_match_all($pattern, $phrase);
            $minimum = $script === 'ja' ? 3 : 4;
            if (is_int($count) && $count >= $minimum) {
                return $script;
            }
        }

        return null;
    }

    private function script(string $base): string
    {
        return match ($base) {
            'ja' => 'ja',
            'ko' => 'ko',
            'th' => 'th',
            'ar' => 'ar',
            'ru', 'uk', 'bg' => 'cyrl',
            'zh' => 'han',
            default => 'latin',
        };
    }

    private function base(string $code): string
    {
        $normalized = ArticleLanguageCode::normalize($code);
        if ($normalized === '') {
            return '';
        }

        $base = explode('-', $normalized)[0];

        return $base !== '' ? $base : '';
    }
}
