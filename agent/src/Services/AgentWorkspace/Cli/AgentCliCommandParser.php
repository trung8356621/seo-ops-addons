<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Cli;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectPublicRef;

/**
 * Parse CLI-style composer input into skill key + form inputs.
 * UX boundary only — does not execute capabilities.
 */
final class AgentCliCommandParser
{
    /**
     * @param  list<string>  $keywordContext  1-indexed keyword list from last /keyword-suggest
     * @return array{
     *   ok: bool,
     *   command?: string,
     *   skill_key?: string|null,
     *   inputs?: array<string, mixed>,
     *   error?: string,
     *   is_meta?: bool
     * }
     */
    public function parse(string $raw, array $keywordContext = []): array
    {
        $text = trim($raw);
        if ($text === '' || ! str_starts_with($text, '/')) {
            return ['ok' => false, 'error' => 'not_cli'];
        }

        $commandToken = strtolower(explode(' ', $text, 2)[0] ?? '');
        $definition = AgentCliCommandCatalog::get($commandToken);
        if ($definition === null) {
            return ['ok' => false, 'error' => 'unknown_command'];
        }

        $rest = trim(substr($text, strlen($commandToken)));
        $parsed = $this->parseFlagsAndPositionals($rest, $definition);

        if (! ($parsed['ok'] ?? false)) {
            return $parsed;
        }

        $inputs = is_array($parsed['inputs'] ?? null) ? $parsed['inputs'] : [];

        // Meta commands (no skill_key).
        if (($definition['skill_key'] ?? null) === null) {
            return [
                'ok' => true,
                'command' => $definition['command'],
                'skill_key' => null,
                'inputs' => $inputs,
                'is_meta' => true,
            ];
        }

        // Keyword positional resolution.
        if (isset($inputs['keywords_tokens']) && is_string($inputs['keywords_tokens'])) {
            $resolved = $this->resolveKeywordTokens($inputs['keywords_tokens'], $keywordContext);
            if (! ($resolved['ok'] ?? false)) {
                return $resolved;
            }
            $inputs['items_text'] = implode("\n", $resolved['keywords']);
            unset($inputs['keywords_tokens']);
        }

        // Map project id to opaque ref when user passes numeric id.
        if (isset($inputs['project_ref']) && is_string($inputs['project_ref'])) {
            $inputs['project_ref'] = $this->resolveProjectRef($inputs['project_ref']);
        }

        // Member stable ID — never use display name as canonical value.
        if (isset($inputs['assignee_ref']) && is_string($inputs['assignee_ref'])) {
            $member = $this->normalizeMemberRef($inputs['assignee_ref']);
            if (! ($member['ok'] ?? false)) {
                return $member;
            }
            $inputs['assignee_ref'] = $member['value'];
        }

        // /site-health --refresh → canonical refresh capability (not inline HTTP).
        if (($definition['command'] ?? '') === '/site-health' && $this->truthy($inputs['refresh'] ?? null)) {
            return [
                'ok' => true,
                'command' => '/site-refresh-snapshot',
                'skill_key' => 'site.refresh_snapshot',
                'inputs' => [],
                'is_meta' => false,
            ];
        }

        // /site-sync --force → force_snapshot boolean for site.sync.
        if (($definition['command'] ?? '') === '/site-sync' && $this->truthy($inputs['force_snapshot'] ?? null)) {
            $inputs['force_snapshot'] = true;
            $inputs['mode'] = 'snapshot';
        }

        return [
            'ok' => true,
            'command' => $definition['command'],
            'skill_key' => $definition['skill_key'],
            'inputs' => $inputs,
            'is_meta' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array{ok:bool,inputs?:array<string,mixed>,error?:string}
     */
    private function parseFlagsAndPositionals(string $rest, array $definition): array
    {
        $inputs = [];
        $positional = '';

        if ($rest !== '') {
            // Bare boolean flags: --refresh / --force (no =value).
            if (preg_match_all('/(?:^|\s)(--[a-z0-9-]+|-[a-z])(?!=)(?=\s|$)/i', $rest, $bareMatches) > 0) {
                foreach ($bareMatches[1] as $bareFlag) {
                    $key = $this->mapFlagToKey(strtolower((string) $bareFlag), $definition);
                    if ($key !== null) {
                        $argType = $this->argTypeForKey($key, $definition);
                        if ($argType === 'boolean') {
                            $inputs[$key] = true;
                            $rest = preg_replace('/(?:^|\s)'.preg_quote((string) $bareFlag, '/').'(?=\s|$)/i', ' ', $rest) ?? $rest;
                        }
                    }
                }
            }

            $segments = [];
            if (preg_match_all('/(?:--[a-z0-9-]+|-[a-z])=(?:"[^"]*"|\'[^\']*\'|\S+)/i', $rest, $matches) > 0) {
                $segments = $matches[0];
            }

            $cursor = $rest;
            foreach ($segments as $segment) {
                $trimmed = trim($segment);
                if (preg_match('/^(--[a-z0-9-]+|-[a-z])=(.+)$/i', $trimmed, $fm) === 1) {
                    $flag = strtolower($fm[1]);
                    $value = $this->stripQuotes((string) $fm[2]);
                    $key = $this->mapFlagToKey($flag, $definition);
                    if ($key !== null) {
                        $inputs[$key] = $value;
                    }
                }
                $cursor = str_replace($segment, ' ', $cursor);
            }

            $positional = trim(preg_replace('/\s+/', ' ', $cursor) ?? '');
        }

        // CLI placeholders like --site-id="" / --domain="" are absent, not present-empty.
        foreach ($inputs as $key => $value) {
            if (is_string($value) && trim($value) === '') {
                unset($inputs[$key]);
            }
        }

        foreach ($definition['args'] as $arg) {
            if ((bool) ($arg['positional'] ?? false)) {
                if ($positional !== '') {
                    $inputs[$arg['key']] = $positional;
                } elseif ((bool) ($arg['required'] ?? false)) {
                    return ['ok' => false, 'error' => 'missing_positional:'.$arg['key']];
                }

                continue;
            }

            $key = (string) ($arg['key'] ?? '');
            if ($key === '') {
                continue;
            }
            if ((bool) ($arg['required'] ?? false) && (! isset($inputs[$key]) || $inputs[$key] === '')) {
                return ['ok' => false, 'error' => 'missing_required:'.$key];
            }
        }

        if (($definition['command'] ?? '') === '/site-switch') {
            $hasSiteId = isset($inputs['site_id']) && trim((string) $inputs['site_id']) !== '';
            $hasDomain = isset($inputs['domain']) && trim((string) $inputs['domain']) !== '';
            if (! $hasSiteId && ! $hasDomain) {
                return ['ok' => false, 'error' => 'missing_required:site_id_or_domain'];
            }
        }

        return ['ok' => true, 'inputs' => $inputs];
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function argTypeForKey(string $key, array $definition): string
    {
        foreach ($definition['args'] as $arg) {
            if ((string) ($arg['key'] ?? '') === $key) {
                return (string) ($arg['type'] ?? 'string');
            }
        }

        return 'string';
    }

    /**
     * @return array{ok: bool, value?: string, error?: string}
     */
    private function normalizeMemberRef(string $raw): array
    {
        $value = trim($raw);
        if ($value === '') {
            return ['ok' => true, 'value' => ''];
        }
        // Prefer numeric ID; email allowed; reject display names.
        if (ctype_digit($value)) {
            return ['ok' => true, 'value' => $value];
        }
        if (str_contains($value, '@') && filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => true, 'value' => $value];
        }

        return [
            'ok' => false,
            'error' => 'member_ref_must_be_id_or_email',
        ];
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }
        if (! is_string($value)) {
            return false;
        }
        $t = strtolower(trim($value));

        return in_array($t, ['1', 'true', 'yes', 'y', 'on'], true);
    }

    /**
     * @param  list<string>  $keywordContext  1-indexed
     * @return array{ok:bool,keywords?:list<string>,error?:string}
     */
    public function resolveKeywordTokens(string $tokens, array $keywordContext): array
    {
        $parts = $this->splitKeywordTokenList($tokens);
        $out = [];

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            if (preg_match('/^"(.*)"$/s', $part, $m) === 1 || preg_match("/^'(.*)'$/s", $part, $m) === 1) {
                $out[] = (string) $m[1];
                continue;
            }

            if (preg_match('/^(\d+)-(\d+)$/', $part, $m) === 1) {
                if ($keywordContext === []) {
                    return [
                        'ok' => false,
                        'error' => 'no_keyword_context',
                    ];
                }

                $start = (int) $m[1];
                $end = (int) $m[2];
                if ($start > $end) {
                    [$start, $end] = [$end, $start];
                }
                for ($i = $start; $i <= $end; $i++) {
                    $kw = $keywordContext[$i] ?? null;
                    if (! is_string($kw) || $kw === '') {
                        return [
                            'ok' => false,
                            'error' => 'keyword_index_missing:'.$i,
                        ];
                    }
                    $out[] = $kw;
                }
                continue;
            }

            if (ctype_digit($part)) {
                if ($keywordContext === []) {
                    return [
                        'ok' => false,
                        'error' => 'no_keyword_context',
                    ];
                }

                $idx = (int) $part;
                $kw = $keywordContext[$idx] ?? null;
                if (! is_string($kw) || $kw === '') {
                    return [
                        'ok' => false,
                        'error' => 'keyword_index_missing:'.$idx,
                    ];
                }
                $out[] = $kw;
                continue;
            }

            $out[] = $part;
        }

        if ($out === []) {
            return ['ok' => false, 'error' => 'empty_keywords'];
        }

        return ['ok' => true, 'keywords' => array_values($out)];
    }

    /**
     * @return list<string>
     */
    private function splitKeywordTokenList(string $raw): array
    {
        $parts = [];
        $current = '';
        $inQuote = null;
        $len = strlen($raw);

        for ($i = 0; $i < $len; $i++) {
            $ch = $raw[$i];
            if ($inQuote !== null) {
                if ($ch === $inQuote) {
                    $current .= $ch;
                    $inQuote = null;
                    continue;
                }
                $current .= $ch;
                continue;
            }

            if ($ch === '"' || $ch === "'") {
                $inQuote = $ch;
                $current .= $ch;
                continue;
            }

            if ($ch === ',') {
                $parts[] = trim($current);
                $current = '';
                continue;
            }

            $current .= $ch;
        }

        if ($current !== '') {
            $parts[] = trim($current);
        }

        return $parts;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function mapFlagToKey(string $flag, array $definition): ?string
    {
        foreach ($definition['args'] as $arg) {
            foreach ($arg['flags'] as $f) {
                if (strtolower($f) === $flag) {
                    return (string) ($arg['key'] ?? null);
                }
            }
        }

        return null;
    }

    private function stripQuotes(string $value): string
    {
        $value = trim($value);
        if ((str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            return substr($value, 1, -1);
        }

        return $value;
    }

    private function resolveProjectRef(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (ctype_digit($value)) {
            return ContentProjectPublicRef::project((int) $value);
        }

        return $value;
    }
}
