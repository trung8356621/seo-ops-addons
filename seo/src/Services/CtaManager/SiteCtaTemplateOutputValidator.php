<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\CtaManager;

/**
 * Validates one AI append batch (exactly BATCH_SIZE new CTA templates).
 * AI slot numbers are treated as batch indices and remapped by the pool service.
 */
final class SiteCtaTemplateOutputValidator
{
    /**
     * @param  list<string>  $existingContents
     * @return array{ok: bool, templates: list<array{slot: int, intent: string, content: string}>, errors: list<string>}
     */
    public function validate(mixed $raw, array $existingContents = []): array
    {
        $decoded = $this->decode($raw);
        if ($decoded === null) {
            return ['ok' => false, 'templates' => [], 'errors' => ['invalid_json']];
        }

        $rows = $decoded['templates'] ?? null;
        if (! is_array($rows)) {
            return ['ok' => false, 'templates' => [], 'errors' => ['missing_templates']];
        }

        $existingNorm = [];
        foreach ($existingContents as $content) {
            $key = $this->normalizeContentKey((string) $content);
            if ($key !== '') {
                $existingNorm[$key] = true;
            }
        }

        $templates = [];
        $errors = [];
        $seenNorm = [];
        $index = 0;
        foreach ($rows as $rowIndex => $row) {
            if (! is_array($row)) {
                $errors[] = 'row_'.$rowIndex.'_not_object';
                continue;
            }
            $content = trim((string) ($row['content'] ?? ''));
            if ($content === '') {
                $errors[] = 'empty_content_row_'.$rowIndex;
                continue;
            }
            $norm = $this->normalizeContentKey($content);
            if (isset($existingNorm[$norm]) || isset($seenNorm[$norm])) {
                $errors[] = 'duplicate_content_row_'.$rowIndex;
                continue;
            }
            $seenNorm[$norm] = true;
            $index++;
            $templates[] = [
                'slot' => $index,
                'intent' => trim((string) ($row['intent'] ?? '')),
                'content' => $content,
            ];
        }

        if (count($templates) !== SiteCtaTemplatePoolService::BATCH_SIZE) {
            $errors[] = 'batch_size_'.count($templates).'_expected_'.SiteCtaTemplatePoolService::BATCH_SIZE;
        }

        if ($errors !== []) {
            return ['ok' => false, 'templates' => [], 'errors' => $errors];
        }

        return ['ok' => true, 'templates' => $templates, 'errors' => []];
    }

    private function normalizeContentKey(string $content): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $content) ?? $content));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(mixed $raw): ?array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (! is_string($raw)) {
            return null;
        }
        $text = trim($raw);
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/iu', '', $text) ?? $text;
        $decoded = json_decode(trim($text), true);

        return is_array($decoded) ? $decoded : null;
    }
}
