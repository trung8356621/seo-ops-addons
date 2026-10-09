<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services\CtaAutomation;

/**
 * Rejects model output that would publish raw contacts, extra shortcodes, or unknown placements.
 */
final class CtaOutputValidator
{
    /**
     * @param  list<array{placement_id: string, section_id: string, alias: ?string}>  $expected
     * @param  list<array{placement_id?: string, section_id?: string, text?: string}>  $ctas
     * @return array{ok: bool, errors: list<array{placement_id: string, code: string}>, ctas: list<array{placement_id: string, section_id: string, text: string}>}
     */
    public function validate(array $expected, array $ctas, string $language): array
    {
        $errors = [];
        $byId = [];
        foreach ($ctas as $row) {
            if (! is_array($row)) {
                $errors[] = ['placement_id' => '', 'code' => 'invalid_row'];
                continue;
            }
            $id = trim((string) ($row['placement_id'] ?? ''));
            $byId[$id] = $row;
        }
        $expectedIds = array_map(static fn (array $row): string => $row['placement_id'], $expected);
        foreach (array_keys($byId) as $id) {
            if (! in_array($id, $expectedIds, true)) {
                $errors[] = ['placement_id' => $id, 'code' => 'unexpected_placement'];
            }
        }

        $clean = [];
        $seenText = [];
        foreach ($expected as $row) {
            $id = $row['placement_id'];
            $source = $byId[$id] ?? null;
            if ($source === null) {
                $errors[] = ['placement_id' => $id, 'code' => 'missing_placement'];
                continue;
            }
            if (trim((string) ($source['section_id'] ?? '')) !== $row['section_id']) {
                $errors[] = ['placement_id' => $id, 'code' => 'section_mismatch'];
            }
            $text = trim((string) ($source['text'] ?? ''));
            $textErrors = $this->textErrors($text, $row['alias'] ?? null, $language);
            foreach ($textErrors as $code) {
                $errors[] = ['placement_id' => $id, 'code' => $code];
            }
            $fingerprint = mb_strtolower(mb_substr($text, 0, 48));
            if ($fingerprint !== '' && isset($seenText[$fingerprint])) {
                $errors[] = ['placement_id' => $id, 'code' => 'duplicate_promo'];
            }
            $seenText[$fingerprint] = true;
            if ($textErrors === []) {
                $clean[] = [
                    'placement_id' => $id,
                    'section_id' => $row['section_id'],
                    'text' => $text,
                ];
            }
        }

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'ctas' => $clean,
        ];
    }

    /**
     * @return list<string>
     */
    private function textErrors(string $text, ?string $alias, string $language): array
    {
        $errors = [];
        $length = mb_strlen($text);
        if ($length < 12 || $length > 420) {
            $errors[] = 'length';
        }
        if (preg_match('#https?://|www\.#i', $text) === 1) {
            $errors[] = 'raw_url';
        }
        if (preg_match('/[\w.+-]+@[\w.-]+\.[a-z]{2,}/i', $text) === 1) {
            $errors[] = 'raw_email';
        }
        if (preg_match('/\+?\d[\d\s.\-]{7,}\d/u', $text) === 1) {
            $errors[] = 'raw_phone';
        }
        preg_match_all('/\[([a-z]+)\]/i', $text, $matches);
        $aliases = array_map(static fn (string $name): string => strtolower($name), $matches[1] ?? []);
        if (count($aliases) > 1) {
            $errors[] = 'multiple_shortcodes';
        }
        foreach ($aliases as $found) {
            if (! in_array($found, CtaShortcodeRegistry::ALIASES, true)) {
                $errors[] = 'unsupported_shortcode';
            }
            if ($alias === null || $found !== $alias) {
                $errors[] = 'shortcode_mismatch';
            }
        }
        if ($alias !== null && ! in_array($alias, $aliases, true)) {
            $errors[] = 'missing_shortcode';
        }
        if (str_starts_with($language, 'vi') && $length > 20 && preg_match('/[ăâêôơưáàảãạấầẩẫậéèẻẽẹíìỉĩịóòỏõọúùủũụýỳỷỹỵđ]/iu', $text) !== 1) {
            $errors[] = 'language';
        }

        return array_values(array_unique($errors));
    }
}
