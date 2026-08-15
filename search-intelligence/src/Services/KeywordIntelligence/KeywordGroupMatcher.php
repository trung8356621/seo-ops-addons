<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordNormalizer;

final class KeywordGroupMatcher
{
    public function __construct(
        private readonly KeywordNormalizer $normalizer,
    ) {}

    /**
     * @param  list<array{id?: int, key: string, label: string, rules: list<array{match_type?: string, type?: string, phrase: string, folded_phrase?: string}>}>  $groups
     * @return list<array{id: int, key: string, label: string}>
     */
    public function match(string $phrase, array $groups): array
    {
        $folded = $this->normalizer->normalize($phrase)['folded_text'];
        $hits = [];

        foreach ($groups as $group) {
            $key = trim((string) ($group['key'] ?? ''));
            if ($key === '') {
                continue;
            }
            foreach ($group['rules'] ?? [] as $rule) {
                if (! $this->ruleHits($folded, $rule)) {
                    continue;
                }
                $hits[] = [
                    'id' => (int) ($group['id'] ?? 0),
                    'key' => $key,
                    'label' => (string) ($group['label'] ?? $key),
                ];
                break;
            }
        }

        return $hits;
    }

    /**
     * @param  array{match_type?: string, type?: string, phrase: string, folded_phrase?: string}  $rule
     */
    public function ruleHits(string $foldedHaystack, array $rule): bool
    {
        $type = strtolower(trim((string) ($rule['match_type'] ?? $rule['type'] ?? 'contains')));
        $needle = trim((string) ($rule['folded_phrase'] ?? ''));
        if ($needle === '') {
            $needle = $this->normalizer->normalize((string) ($rule['phrase'] ?? ''))['folded_text'];
        }
        if ($needle === '' || $foldedHaystack === '') {
            return false;
        }

        return match ($type) {
            'exact' => $foldedHaystack === $needle,
            'prefix' => str_starts_with($foldedHaystack, $needle.' ') || $foldedHaystack === $needle,
            default => (bool) preg_match('/(?:^|\s)'.preg_quote($needle, '/').'(?:\s|$)/u', $foldedHaystack),
        };
    }
}
