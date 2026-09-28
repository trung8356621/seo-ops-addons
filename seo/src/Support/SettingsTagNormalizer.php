<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Support;

use Closure;
use Filament\Forms\Components\TagsInput;
use Omnichannel\Addons\SearchFoundation\Support\DomainHostNormalizer;

/**
 * Shared normalizer and UI interaction helper for Settings TagsInput fields.
 * Handles canonicalization before/when tags are accepted into visible state,
 * as well as backend validation and deduplication.
 */
final class SettingsTagNormalizer
{
    public const TYPE_TRUSTED_DOMAIN = 'trusted_domain';
    public const TYPE_STRICT_DOMAIN = 'strict_domain';
    public const TYPE_EXTENSION = 'extension';
    public const TYPE_PHRASE = 'phrase';

    /**
     * Normalize a single trusted domain or wildcard pattern (*.gov, *.edu, *.example.com).
     * Returns null if invalid.
     */
    public static function normalizeTrustedDomainTag(?string $tag): ?string
    {
        if ($tag === null) {
            return null;
        }

        return DomainHostNormalizer::normalizeTrustedPattern($tag);
    }

    /**
     * Normalize a single domain in strict mode (no wildcards).
     * Returns null if invalid.
     */
    public static function normalizeStrictDomainTag(?string $tag): ?string
    {
        if ($tag === null) {
            return null;
        }

        return DomainHostNormalizer::normalizeStrict($tag);
    }

    /**
     * Normalize a single file extension (lowercase, strip leading dots).
     * Returns null if invalid.
     */
    public static function normalizeExtensionTag(?string $tag): ?string
    {
        if ($tag === null) {
            return null;
        }

        $clean = strtolower(ltrim(trim($tag), '.'));
        if ($clean === '' || ! preg_match('/^[a-z0-9]{1,12}$/', $clean)) {
            return null;
        }

        return $clean;
    }

    /**
     * Normalize a phrase (preserve internal words/spaces, lowercase, trim).
     * Returns null if empty.
     */
    public static function normalizePhraseTag(?string $tag): ?string
    {
        if ($tag === null) {
            return null;
        }

        $clean = trim($tag);
        if ($clean === '') {
            return null;
        }

        $clean = mb_strtolower($clean);
        $clean = (string) preg_replace('/\s+/u', ' ', $clean);

        return $clean !== '' ? $clean : null;
    }

    /**
     * @param  iterable<mixed>  $tags
     * @return list<string>
     */
    public static function normalizeTrustedDomainTags(iterable $tags): array
    {
        return DomainHostNormalizer::normalizeTrustedPatternList($tags);
    }

    /**
     * @param  iterable<mixed>  $tags
     * @return list<string>
     */
    public static function normalizeStrictDomainTags(iterable $tags): array
    {
        return DomainHostNormalizer::normalizeStrictList($tags);
    }

    /**
     * @param  iterable<mixed>  $tags
     * @return list<string>
     */
    public static function normalizeExtensionTags(iterable $tags): array
    {
        $normalized = [];
        foreach ($tags as $tag) {
            $clean = self::normalizeExtensionTag(is_string($tag) ? $tag : (string) $tag);
            if ($clean !== null && ! in_array($clean, $normalized, true)) {
                $normalized[] = $clean;
            }
        }

        return $normalized;
    }

    /**
     * @param  iterable<mixed>  $tags
     * @return list<string>
     */
    public static function normalizePhraseTags(iterable $tags): array
    {
        $normalized = [];
        foreach ($tags as $tag) {
            $clean = self::normalizePhraseTag(is_string($tag) ? $tag : (string) $tag);
            if ($clean !== null && ! in_array($clean, $normalized, true)) {
                $normalized[] = $clean;
            }
        }

        return $normalized;
    }

    /**
     * Alpine.js attributes to intercept Filament's tagsInputFormComponent.
     * Hooks createTag() so that when a tag is added (via Enter, blur, or paste),
     * it is normalized BEFORE being pushed into this.state.
     * Also canonicalizes initial state and rejects invalid tags immediately.
     *
     * @return array<string, string>
     */
    public static function alpineTagInterceptor(string $type): array
    {
        $jsNormalizer = match ($type) {
            self::TYPE_TRUSTED_DOMAIN => <<<'JS'
(function(raw) {
    if (!raw || typeof raw !== 'string') return null;
    var val = raw.trim();
    if (!val || /\s/.test(val)) return null;
    var sm = val.match(/^[a-zA-Z][a-zA-Z0-9+.-]*:/);
    if (sm) {
        var s = sm[0].toLowerCase();
        if (s !== 'http:' && s !== 'https:') return null;
    }
    if (!val.includes('://') && !val.startsWith('//') && val.includes('@')) return null;
    var parseTarget = val;
    if (parseTarget.startsWith('//')) {
        parseTarget = 'https:' + parseTarget;
    } else if (parseTarget.includes('://') || (parseTarget.includes('/') && !parseTarget.startsWith('*.'))) {
        if (!parseTarget.includes('://')) parseTarget = 'https://' + parseTarget;
    }
    if (parseTarget.includes('://')) {
        try {
            var u = new URL(parseTarget);
            if (u.protocol !== 'http:' && u.protocol !== 'https:') return null;
            val = u.hostname;
        } catch (e) {
            return null;
        }
    }
    val = val.toLowerCase().replace(/\.+$/, '');
    if (val.startsWith('*.')) {
        var suffix = val.slice(2).replace(/\.+$/, '');
        if (!suffix || /[*\/#?]/.test(suffix)) return null;
        if (!/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/.test(suffix)) return null;
        if (!suffix.includes('.') && suffix.length < 2) return null;
        return '*.' + suffix;
    }
    if (val.includes('*')) return null;
    if (val.startsWith('www.')) val = val.slice(4);
    val = val.replace(/\.+$/, '');
    if (!/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/.test(val)) return null;
    return val;
})
JS,
            self::TYPE_STRICT_DOMAIN => <<<'JS'
(function(raw) {
    if (!raw || typeof raw !== 'string') return null;
    var val = raw.trim();
    if (!val || /\s/.test(val)) return null;
    var sm = val.match(/^[a-zA-Z][a-zA-Z0-9+.-]*:/);
    if (sm) {
        var s = sm[0].toLowerCase();
        if (s !== 'http:' && s !== 'https:') return null;
    }
    if (!val.includes('://') && !val.startsWith('//') && val.includes('@')) return null;
    var parseTarget = val;
    if (parseTarget.startsWith('//')) {
        parseTarget = 'https:' + parseTarget;
    } else if (parseTarget.includes('://') || parseTarget.includes('/')) {
        if (!parseTarget.includes('://')) parseTarget = 'https://' + parseTarget;
    }
    if (parseTarget.includes('://')) {
        try {
            var u = new URL(parseTarget);
            if (u.protocol !== 'http:' && u.protocol !== 'https:') return null;
            val = u.hostname;
        } catch (e) {
            return null;
        }
    }
    val = val.toLowerCase().replace(/\.+$/, '');
    if (val.includes('*')) return null;
    if (val.startsWith('www.')) val = val.slice(4);
    val = val.replace(/\.+$/, '');
    if (!/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/.test(val)) return null;
    return val;
})
JS,
            self::TYPE_EXTENSION => <<<'JS'
(function(raw) {
    if (!raw || typeof raw !== 'string') return null;
    var clean = raw.trim().toLowerCase().replace(/^\.+/, '');
    if (!clean || !/^[a-z0-9]{1,12}$/.test(clean)) return null;
    return clean;
})
JS,
            self::TYPE_PHRASE => <<<'JS'
(function(raw) {
    if (!raw || typeof raw !== 'string') return null;
    var clean = raw.trim().toLowerCase().replace(/\s+/g, ' ');
    return clean || null;
})
JS,
        };

        $jsOneLine = str_replace(["\r", "\n"], ' ', $jsNormalizer);
        $initCode = "(() => { const _n = {$jsOneLine}; const _orig = this.createTag.bind(this); this.createTag = () => { const norm = _n(this.newTag); if (!norm) { this.newTag = ''; return; } this.newTag = norm; _orig(); }; if (Array.isArray(this.state)) { const cleaned = []; for (const t of this.state) { const norm = _n(t); if (norm && !cleaned.includes(norm)) cleaned.push(norm); } this.state = cleaned; } })()";

        return [
            'x-init' => $initCode,
        ];
    }
}
