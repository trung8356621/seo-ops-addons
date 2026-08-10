<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Support;

/**
 * Chuẩn hóa một dòng FAQ từ WP meta, REST hoặc panel editor.
 */
final class FaqRowNormalizer
{
    /**
     * @param  array<int, mixed>|null  $faqs
     * @return list<array{question: string, answer: string, more: string}>
     */
    public static function normalizeList(mixed $faqs): array
    {
        if (! is_array($faqs)) {
            return [];
        }

        $rows = [];

        foreach ($faqs as $faq) {
            $row = self::normalizeOne($faq);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @return array{question: string, answer: string, more: string}|null
     */
    public static function normalizeOne(mixed $faq): ?array
    {
        if (! is_array($faq)) {
            return null;
        }

        $question = self::pickString($faq, ['question', 'q', 'title', 'name', 'label', 'heading']);
        $answer = self::pickString($faq, ['answer', 'a', 'content', 'body', 'text', 'response', 'value']);
        $more = self::pickString($faq, ['more', 'see_more', 'seeMore', 'xem_them', 'intro', 'lead']);

        if ($question === '' && $answer === '') {
            return null;
        }

        if ($question === '') {
            $question = 'FAQ';
        }

        if ($answer === '' && $more !== '') {
            $answer = $more;
            $more = '';
        }

        if ($answer === '') {
            return null;
        }

        return [
            'question' => $question,
            'answer' => $answer,
            'more' => $more,
        ];
    }

    /**
     * @param  array<string, mixed>  $faq
     * @param  list<string>  $keys
     */
    private static function pickString(array $faq, array $keys): string
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $faq)) {
                continue;
            }

            $value = trim((string) $faq[$key]);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}
