<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Services\MatchResearch;

/**
 * Stable language-neutral industry resource keys from source-locale identity.
 * Never derive from translated display text alone.
 */
final class IndustryMatchResearchKeyDeriver
{
    public function forEntity(string $group, string $canonical, string $sourceLocale): string
    {
        $normalized = $this->normalize($canonical);
        $suffix = substr(hash('sha256', $sourceLocale.'|'.$group.'|'.$normalized.'|'.$canonical), 0, 8);

        return 'industry.'.$group.'.'.$normalized.'.'.$suffix;
    }

    public function forAmbiguity(string $term, string $sourceLocale): string
    {
        $normalized = $this->normalize($term);
        $suffix = substr(hash('sha256', $sourceLocale.'|ambiguities|'.$normalized.'|'.$term), 0, 8);

        return 'industry.ambiguities.'.$normalized.'.'.$suffix;
    }

    public function normalize(string $value): string
    {
        $folded = mb_strtolower(str_replace(['đ', 'Đ'], ['d', 'D'], \Illuminate\Support\Str::ascii($value)));
        $folded = preg_replace('/[^a-z0-9]+/u', '_', $folded) ?? '';
        $folded = trim($folded, '_');

        return $folded !== '' ? $folded : 'entry';
    }
}
