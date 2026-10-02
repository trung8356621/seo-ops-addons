<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Services\MatchRules;

final class MatchRuleMatcher
{
    /** @param list<array<string, mixed>> $entries @return list<string> */
    public function matchingEntries(array $entries, string $phrase): array
    {
        $matches = [];
        foreach ($entries as $entry) {
            $canonical = trim((string) ($entry['canonical'] ?? ''));
            $values = array_values(array_unique([$canonical, ...array_map('strval', (array) ($entry['aliases'] ?? []))]));
            $mode = (string) ($entry['match_mode'] ?? 'phrase');
            foreach ($values as $value) {
                if ($value !== '' && $this->matchesValue($phrase, $value, $mode)) {
                    $matches[] = $canonical !== '' ? $canonical : $value;
                    break;
                }
            }
        }

        return array_values(array_unique($matches));
    }

    /** @param list<array<string, mixed>> $entries */
    public function matches(array $entries, string $phrase): bool
    {
        return $this->matchingEntries($entries, $phrase) !== [];
    }

    private function matchesValue(string $phrase, string $value, string $mode): bool
    {
        $sensitive = in_array($mode, ['exact', 'token', 'phrase', 'prefix', 'accent_sensitive'], true);
        $haystack = $sensitive ? mb_strtolower($phrase) : $this->fold($phrase);
        $needle = $sensitive ? mb_strtolower($value) : $this->fold($value);

        return match ($mode) {
            'exact' => trim($haystack) === trim($needle),
            'prefix' => str_starts_with(trim($haystack), trim($needle)),
            'token', 'accent_sensitive' => preg_match('/(?<![\p{L}\p{N}_])'.preg_quote(trim($needle), '/').'(?![\p{L}\p{N}_])/u', $haystack) === 1,
            default => str_contains(' '.preg_replace('/\s+/u', ' ', trim($haystack)).' ', ' '.preg_replace('/\s+/u', ' ', trim($needle)).' '),
        };
    }

    private function fold(string $value): string
    {
        return mb_strtolower(str_replace(['đ', 'Đ'], ['d', 'D'], \Illuminate\Support\Str::ascii($value)));
    }
}
