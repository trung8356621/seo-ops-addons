<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence;

final class KeywordSourceNormalizer
{
    public const MANUAL = 'manual';

    public const SITE_SYNC = 'site_sync';

    public const ANCHOR_TEXT = 'anchor_text';

    public const SEARCH_CONSOLE = 'search_console';

    public const KEYWORD_DISCOVERY = 'keyword_discovery';

    public const CONTENT_PROJECT = 'content_project';

    public const IMPORT = 'import';

    public const AI_GENERATED = 'ai_generated';

    public const OTHER = 'other';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::MANUAL,
            self::SITE_SYNC,
            self::ANCHOR_TEXT,
            self::SEARCH_CONSOLE,
            self::KEYWORD_DISCOVERY,
            self::CONTENT_PROJECT,
            self::IMPORT,
            self::AI_GENERATED,
            self::OTHER,
        ];
    }

    public static function label(string $source): string
    {
        return match ($source) {
            self::MANUAL => __('seo-content-ai::filament.keyword.source_manual'),
            self::SITE_SYNC => __('seo-content-ai::filament.keyword.source_site_sync'),
            self::ANCHOR_TEXT => __('seo-content-ai::filament.keyword.source_anchor_text'),
            self::SEARCH_CONSOLE => __('seo-content-ai::filament.keyword.source_search_console'),
            self::KEYWORD_DISCOVERY => __('seo-content-ai::filament.keyword.source_keyword_discovery'),
            self::CONTENT_PROJECT => __('seo-content-ai::filament.keyword.source_content_project'),
            self::IMPORT => __('seo-content-ai::filament.keyword.source_import'),
            self::AI_GENERATED => __('seo-content-ai::filament.keyword.source_ai_generated'),
            self::OTHER => __('seo-content-ai::filament.keyword.source_other'),
            default => $source,
        };
    }

    /**
     * @return array<string, string>
     */
    public static function filterOptions(): array
    {
        $options = [];
        foreach (self::all() as $source) {
            $options[$source] = self::label($source);
        }

        return $options;
    }

    public function normalize(?string $raw): string
    {
        $value = mb_strtolower(trim((string) $raw));

        return match (true) {
            $value === '' => self::OTHER,
            in_array($value, ['manual', 'user', 'cms'], true) => self::MANUAL,
            in_array($value, ['provider', 'wordpress', 'site_sync', 'wp'], true) => self::SITE_SYNC,
            in_array($value, ['anchor', 'anchor_text', 'link', 'internal_link'], true) => self::ANCHOR_TEXT,
            in_array($value, ['gsc', 'search_console', 'google_search_console'], true) => self::SEARCH_CONSOLE,
            in_array($value, ['workspace', 'discovery', 'keyword_discovery'], true) => self::KEYWORD_DISCOVERY,
            in_array($value, ['content_project', 'project', 'seo_project'], true) => self::CONTENT_PROJECT,
            in_array($value, ['import', 'csv', 'xlsx'], true) => self::IMPORT,
            in_array($value, ['ai', 'ai_generated', 'gemini'], true) => self::AI_GENERATED,
            default => self::OTHER,
        };
    }
}
