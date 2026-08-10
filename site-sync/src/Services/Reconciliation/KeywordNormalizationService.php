<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Reconciliation;

/**
 * Normalize provider/workspace keyword phrases without AI.
 */
final class KeywordNormalizationService
{
    public function normalize(string $phrase): string
    {
        $value = html_entity_decode($phrase, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = str_replace("\xC2\xA0", ' ', $value);
        $value = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = str_replace(['|', ';', '•'], ',', $value);
        $value = trim($value);

        return mb_substr($value, 0, 255);
    }

    /**
     * @param  list<string>  $phrases
     * @return list<array{phrase: string, display: string}>
     */
    public function dedupeCaseInsensitive(array $phrases): array
    {
        $seen = [];
        $out = [];
        foreach ($phrases as $raw) {
            $display = $this->normalize($raw);
            if ($display === '') {
                continue;
            }
            $key = mb_strtolower($display);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = ['phrase' => $display, 'display' => $display];
        }

        return $out;
    }
}
