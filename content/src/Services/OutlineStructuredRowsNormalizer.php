<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeArtifactSplitter;

/**
 * Normalize Outline markdown → structured rows (once at Outline completion).
 * Authority for MULTIPLE_PASS planning — not for SINGLE_PASS Writing input.
 */
final class OutlineStructuredRowsNormalizer
{
    public const KIND_INTRO = 'intro';

    public const KIND_H2 = 'h2';

    public const KIND_H3 = 'h3';

    public const KIND_CONCLUSION = 'conclusion';

    public const KIND_FAQ = 'faq';

    public const KIND_OTHER = 'other';

    private const INTRO_MARKER = '[MỞ BÀI — KHÔNG HEADING]';

    /**
     * @return list<array{
     *   id: string,
     *   order: int,
     *   level: int,
     *   title: string,
     *   note: string,
     *   parent_id: ?string,
     *   kind: string
     * }>
     */
    public function normalize(string $markdown): array
    {
        $parts = (new SectionedFreeArtifactSplitter())->split($markdown);
        $body = trim($parts['outline_markdown']);
        if ($body === '') {
            return [];
        }

        $lines = preg_split('/\R/u', $body) ?: [];
        /** @var list<array{id: string, order: int, level: int, title: string, note: string, parent_id: ?string, kind: string}> $rows */
        $rows = [];
        $order = 0;
        $current = null;
        $currentH2Id = null;

        $flush = function () use (&$current, &$rows): void {
            if ($current === null) {
                return;
            }
            $current['note'] = trim((string) $current['note']);
            $rows[] = $current;
            $current = null;
        };

        foreach ($lines as $rawLine) {
            $line = rtrim((string) $rawLine);
            $trimmed = trim($line);
            if ($trimmed === '') {
                if ($current !== null && $current['note'] !== '') {
                    $current['note'] .= "\n";
                }
                continue;
            }

            if ($this->isIntroMarker($trimmed)) {
                $flush();
                $order++;
                $id = 'row_'.$order;
                $current = [
                    'id' => $id,
                    'order' => $order,
                    'level' => 0,
                    'title' => '',
                    'note' => '',
                    'parent_id' => null,
                    'kind' => self::KIND_INTRO,
                ];
                $currentH2Id = null;
                continue;
            }

            if (preg_match('/^(#{1,6})\s+(.*)$/u', $trimmed, $m) === 1) {
                $flush();
                $level = strlen($m[1]);
                $title = trim(str_replace(['**', '__'], '', (string) $m[2]));
                $order++;
                $id = 'row_'.$order;
                $kind = $this->classifyKind($title, $level);
                $parentId = null;
                if ($level >= 3 && $currentH2Id !== null && ! in_array($kind, [self::KIND_FAQ, self::KIND_CONCLUSION, self::KIND_INTRO], true)) {
                    $parentId = $currentH2Id;
                    $kind = self::KIND_H3;
                }
                if ($level === 2 && $kind === self::KIND_OTHER) {
                    $kind = self::KIND_H2;
                }
                if ($level === 2 && in_array($kind, [self::KIND_H2, self::KIND_FAQ, self::KIND_CONCLUSION, self::KIND_INTRO], true)) {
                    $currentH2Id = $kind === self::KIND_H2 ? $id : null;
                } elseif ($level === 2) {
                    $currentH2Id = $id;
                }

                $current = [
                    'id' => $id,
                    'order' => $order,
                    'level' => $level,
                    'title' => $title,
                    'note' => '',
                    'parent_id' => $parentId,
                    'kind' => $kind,
                ];
                continue;
            }

            if ($current === null) {
                $order++;
                $id = 'row_'.$order;
                $current = [
                    'id' => $id,
                    'order' => $order,
                    'level' => 0,
                    'title' => '',
                    'note' => $trimmed,
                    'parent_id' => null,
                    'kind' => self::KIND_INTRO,
                ];
                continue;
            }

            $current['note'] .= ($current['note'] === '' ? '' : "\n").$trimmed;
        }

        $flush();

        return $rows;
    }

    private function isIntroMarker(string $line): bool
    {
        $normalized = mb_strtoupper(trim($line));
        $marker = mb_strtoupper(self::INTRO_MARKER);

        return $normalized === $marker
            || str_contains($normalized, 'MỞ BÀI')
            || str_contains($normalized, 'MO BAI');
    }

    private function classifyKind(string $title, int $level): string
    {
        $h = mb_strtolower($title);
        if (
            str_contains($h, 'faq')
            || str_contains($h, 'câu hỏi thường gặp')
            || str_contains($h, 'hoi dap')
            || str_contains($h, 'hỏi đáp')
        ) {
            return self::KIND_FAQ;
        }
        if (
            str_contains($h, 'conclusion')
            || str_contains($h, 'kết luận')
            || str_contains($h, 'ket luan')
            || str_contains($h, 'tóm tắt')
            || str_contains($h, 'tom tat')
        ) {
            return self::KIND_CONCLUSION;
        }
        if (
            str_contains($h, 'intro')
            || str_contains($h, 'mở đầu')
            || str_contains($h, 'mo dau')
            || str_contains($h, 'giới thiệu')
            || str_contains($h, 'gioi thieu')
        ) {
            return self::KIND_INTRO;
        }
        if ($level === 1) {
            return self::KIND_INTRO;
        }
        if ($level === 2) {
            return self::KIND_H2;
        }
        if ($level >= 3) {
            return self::KIND_H3;
        }

        return self::KIND_OTHER;
    }
}
