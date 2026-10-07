<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping;

/**
 * Deterministic input hash for semantic Topic analysis staleness checks.
 *
 * Mirrors seo-ops-semantic compute_input_hash:
 * site_ref + language + keywords[{ref, normalized text}] sorted by ref.
 */
final class TopicGroupingInputHasher
{
    /**
     * @param  list<array{ref: string, text: string}>  $keywords
     */
    public function hash(string $siteRef, ?string $language, array $keywords): string
    {
        $rows = [];
        foreach ($keywords as $keyword) {
            $ref = trim((string) ($keyword['ref'] ?? ''));
            $text = $this->normalizeText((string) ($keyword['text'] ?? ''));
            if ($ref === '' || $text === '') {
                continue;
            }
            $rows[] = ['ref' => $ref, 'text' => $text];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($a['ref'], $b['ref']));

        $payload = [
            'keywords' => $rows,
            'language' => $language,
            'site_ref' => $siteRef,
        ];

        return hash('sha256', $this->canonicalJson($payload));
    }

    public function hashFromGroupingInput(TopicGroupingInput $input): string
    {
        $keywords = [];
        foreach ($input->candidates as $candidate) {
            $keywords[] = [
                'ref' => (string) $candidate->keywordRef,
                'text' => $candidate->text,
            ];
        }

        return $this->hash((string) $input->siteRef, $input->language, $keywords);
    }

    public function normalizeText(string $text): string
    {
        $value = \Normalizer::normalize($text, \Normalizer::FORM_C);
        if (! is_string($value)) {
            $value = $text;
        }
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function canonicalJson(array $payload): string
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new \RuntimeException('failed_to_encode_topic_input_hash');
        }

        return $encoded;
    }
}
