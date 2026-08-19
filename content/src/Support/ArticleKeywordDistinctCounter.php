<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Support;

/**
 * Distinct keyword count from canonical article vocabulary meta (`seo_article_keywords`).
 *
 * Same stored source as Article editor Vocabulary / Keyword Intelligence persist.
 * Does not scan article body HTML/content.
 */
final class ArticleKeywordDistinctCounter
{
    public const META_KEY = 'seo_article_keywords';

    /**
     * @param  mixed  $raw  article_meta.meta_value for seo_article_keywords
     */
    public static function count(mixed $raw): int
    {
        return count(self::distinctPhrases($raw));
    }

    /**
     * @return list<string>
     */
    public static function distinctPhrases(mixed $raw): array
    {
        $groups = self::normalizeGroups($raw);
        $seen = [];
        $phrases = [];

        foreach ($groups as $items) {
            foreach ($items as $phrase) {
                $normalized = mb_strtolower(trim($phrase));
                if ($normalized === '' || isset($seen[$normalized])) {
                    continue;
                }
                $seen[$normalized] = true;
                $phrases[] = $phrase;
            }
        }

        return $phrases;
    }

    /**
     * @return array<string, list<string>>
     */
    private static function normalizeGroups(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }

        if (is_array($raw)) {
            return self::groupsFromArray($raw);
        }

        $string = trim((string) $raw);
        if ($string === '') {
            return [];
        }

        $decoded = json_decode($string, true);
        if (is_array($decoded)) {
            return self::groupsFromArray($decoded);
        }

        return [];
    }

    /**
     * @param  array<mixed, mixed>  $data
     * @return array<string, list<string>>
     */
    private static function groupsFromArray(array $data): array
    {
        if ($data === []) {
            return [];
        }

        $isList = array_is_list($data);
        $result = [];

        if ($isList) {
            $list = [];
            foreach ($data as $item) {
                $phrase = self::phraseFromItem($item);
                if ($phrase !== '') {
                    $list[] = $phrase;
                }
            }
            if ($list !== []) {
                $result['_'] = $list;
            }

            return $result;
        }

        foreach ($data as $group => $items) {
            $list = [];
            foreach (is_array($items) ? $items : [] as $item) {
                $phrase = self::phraseFromItem($item);
                if ($phrase !== '') {
                    $list[] = $phrase;
                }
            }
            if ($list !== []) {
                $result[(string) $group] = $list;
            }
        }

        return $result;
    }

    private static function phraseFromItem(mixed $item): string
    {
        if (is_string($item) || is_numeric($item)) {
            return trim((string) $item);
        }

        if (! is_array($item)) {
            return '';
        }

        foreach (['keyword', 'phrase', 'title'] as $key) {
            if (! isset($item[$key])) {
                continue;
            }
            $phrase = trim((string) $item[$key]);
            if ($phrase !== '') {
                return $phrase;
            }
        }

        return '';
    }
}
