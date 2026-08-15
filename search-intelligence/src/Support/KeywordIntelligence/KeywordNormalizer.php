<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence;

use Normalizer;

final class KeywordNormalizer
{
    /**
     * @return array{raw_text: string, normalized_text: string, folded_text: string}
     */
    public function normalize(string $raw): array
    {
        $rawText = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $rawText = str_replace("\xC2\xA0", ' ', $rawText);
        $rawText = $this->unicodeFormC($rawText);
        $rawText = trim($rawText);

        $normalized = preg_replace('/[\p{Zs}\t\n\r]+/u', ' ', $rawText) ?? $rawText;
        $normalized = preg_replace('/\s*([,;:!?…]+)\s*/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
        $normalized = trim($normalized);
        $normalized = mb_strtolower($this->unicodeFormC($normalized), 'UTF-8');

        return [
            'raw_text' => $rawText,
            'normalized_text' => $normalized,
            'folded_text' => $this->fold($normalized),
        ];
    }

    public function fold(string $value): string
    {
        $folded = mb_strtolower($this->unicodeFormC($value), 'UTF-8');
        $map = [
            'à' => 'a', 'á' => 'a', 'ả' => 'a', 'ã' => 'a', 'ạ' => 'a',
            'ă' => 'a', 'ằ' => 'a', 'ắ' => 'a', 'ẳ' => 'a', 'ẵ' => 'a', 'ặ' => 'a',
            'â' => 'a', 'ầ' => 'a', 'ấ' => 'a', 'ẩ' => 'a', 'ẫ' => 'a', 'ậ' => 'a',
            'è' => 'e', 'é' => 'e', 'ẻ' => 'e', 'ẽ' => 'e', 'ẹ' => 'e',
            'ê' => 'e', 'ề' => 'e', 'ế' => 'e', 'ể' => 'e', 'ễ' => 'e', 'ệ' => 'e',
            'ì' => 'i', 'í' => 'i', 'ỉ' => 'i', 'ĩ' => 'i', 'ị' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ỏ' => 'o', 'õ' => 'o', 'ọ' => 'o',
            'ô' => 'o', 'ồ' => 'o', 'ố' => 'o', 'ổ' => 'o', 'ỗ' => 'o', 'ộ' => 'o',
            'ơ' => 'o', 'ờ' => 'o', 'ớ' => 'o', 'ở' => 'o', 'ỡ' => 'o', 'ợ' => 'o',
            'ù' => 'u', 'ú' => 'u', 'ủ' => 'u', 'ũ' => 'u', 'ụ' => 'u',
            'ư' => 'u', 'ừ' => 'u', 'ứ' => 'u', 'ử' => 'u', 'ữ' => 'u', 'ự' => 'u',
            'ỳ' => 'y', 'ý' => 'y', 'ỷ' => 'y', 'ỹ' => 'y', 'ỵ' => 'y',
            'đ' => 'd',
        ];

        return strtr($folded, $map);
    }

    private function unicodeFormC(string $value): string
    {
        if (! class_exists(Normalizer::class)) {
            return $value;
        }

        $nfc = Normalizer::normalize($value, Normalizer::FORM_C);

        return is_string($nfc) && $nfc !== '' ? $nfc : $value;
    }
}
