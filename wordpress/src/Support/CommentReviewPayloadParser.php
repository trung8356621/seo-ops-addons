<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Support;

/**
 * Parse bình luận/review từ output AI.
 * Ưu tiên format theo dòng: "Họ và tên | Email | Nội dung bình luận" trước,
 * sau đó mới fallback JSON.
 */
final class CommentReviewPayloadParser
{
    /**
     * @return list<array<string, mixed>>
     */
    public function parse(string $raw): array
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return [];
        }

        $splitLines = $this->parseSplitLines($trimmed);
        if ($splitLines !== []) {
            return $splitLines;
        }

        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $trimmed, $matches)) {
            $trimmed = trim($matches[1]);
        }

        $decoded = json_decode($trimmed, true);
        if (! is_array($decoded)) {
            return [];
        }

        if ($this->isListOfItems($decoded)) {
            return $this->normalizeList($decoded);
        }

        foreach (['comments', 'reviews', 'items', 'data'] as $key) {
            if (isset($decoded[$key]) && is_array($decoded[$key]) && $this->isListOfItems($decoded[$key])) {
                return $this->normalizeList($decoded[$key]);
            }
        }

        return [];
    }

    /**
     * Parse từng dòng dạng: author | email | content (| rating).
     *
     * @return list<array<string, mixed>>
     */
    private function parseSplitLines(string $raw): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $items = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            // Bỏ bullet / số thứ tự phổ biến.
            $line = preg_replace('/^\s*(?:[-*•]\s+|\d+[.)]\s+)/u', '', $line) ?? $line;
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (! str_contains($line, '|')) {
                continue;
            }

            $parts = array_values(array_filter(array_map(
                static fn (string $part): string => trim($part),
                explode('|', trim($line, " \t|")),
            ), static fn (string $part): bool => $part !== ''));

            if (count($parts) < 3) {
                continue;
            }

            [$author, $email, $content] = array_slice($parts, 0, 3);
            $authorLower = mb_strtolower($author);
            $emailLower = mb_strtolower($email);
            $contentLower = mb_strtolower($content);

            // Bỏ dòng tiêu đề cột.
            if (
                str_contains($authorLower, 'họ') && str_contains($authorLower, 'tên')
                && str_contains($emailLower, 'email')
                && (str_contains($contentLower, 'nội dung') || str_contains($contentLower, 'bình luận'))
            ) {
                continue;
            }

            if ($content === '') {
                continue;
            }

            $item = [
                'content' => $content,
                'author' => $author !== '' ? $author : 'Khách',
                'email' => $email,
            ];

            if (isset($parts[3]) && is_numeric($parts[3])) {
                $item['rating'] = max(1, min(5, (int) $parts[3]));
            }

            $items[] = $item;
        }

        return $items;
    }

    /**
     * @param  array<int, mixed>  $list
     * @return list<array<string, mixed>>
     */
    private function normalizeList(array $list): array
    {
        $items = [];

        foreach ($list as $row) {
            if (! is_array($row)) {
                continue;
            }

            $content = trim((string) (
                $row['comment']
                ?? $row['content']
                ?? $row['review']
                ?? $row['noi_dung']
                ?? ''
            ));

            if ($content === '') {
                continue;
            }

            $author = trim((string) (
                $row['Họ và tên']
                ?? $row['ho_va_ten']
                ?? $row['author']
                ?? $row['author_name']
                ?? $row['name']
                ?? 'Khách'
            ));

            $email = trim((string) (
                $row['Email']
                ?? $row['email']
                ?? $row['author_email']
                ?? ''
            ));

            $rating = null;
            foreach (['star_ranking', 'rating', 'stars', 'star'] as $ratingKey) {
                if (isset($row[$ratingKey]) && is_numeric($row[$ratingKey])) {
                    $rating = (int) $row[$ratingKey];
                    break;
                }
            }

            $item = [
                'content' => $content,
                'author' => $author,
                'email' => $email,
            ];

            if ($rating !== null) {
                $item['rating'] = max(1, min(5, $rating));
            }

            $items[] = $item;
        }

        return $items;
    }

    /**
     * @param  array<int, mixed>  $list
     */
    private function isListOfItems(array $list): bool
    {
        if ($list === []) {
            return false;
        }

        return array_is_list($list);
    }
}
