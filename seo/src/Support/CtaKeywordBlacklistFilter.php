<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Support;

use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\AiPrompt\Services\OutlineSkipListMatcher;
use Omnichannel\Addons\Seo\Services\SeoKeywordSettingsService;

final class CtaKeywordBlacklistFilter
{
    public function __construct(
        private readonly SeoKeywordSettingsService $settings,
        private readonly OutlineSkipListMatcher $skipListMatcher,
    ) {}

    public function isBlocked(string $phrase): bool
    {
        return self::matchesPhrase(
            $phrase,
            $this->settings->getCtaBlacklist(),
            $this->skipListMatcher,
        );
    }

    /**
     * @param  list<string>  $blacklist
     */
    public static function matchesPhrase(
        string $phrase,
        array $blacklist,
        ?OutlineSkipListMatcher $skipListMatcher = null,
    ): bool {
        return self::matchingBlacklistEntries($phrase, $blacklist, $skipListMatcher) !== [];
    }

    /**
     * @param  list<string>  $blacklist
     * @return list<string> Các mục blacklist (nguyên bản trên form) khớp phrase.
     */
    public static function matchingBlacklistEntries(
        string $phrase,
        array $blacklist,
        ?OutlineSkipListMatcher $skipListMatcher = null,
    ): array {
        if ($blacklist === []) {
            return [];
        }

        $skipListMatcher ??= app(OutlineSkipListMatcher::class);
        $decoded = Keyword::decodePhrase($phrase);
        if ($decoded === '') {
            return [];
        }

        $matches = [];

        foreach ($blacklist as $entry) {
            $entry = trim((string) $entry);
            if ($entry === '') {
                continue;
            }

            $patterns = $skipListMatcher->normalizeSqlPatterns([$entry]);
            if ($skipListMatcher->isSkipped($decoded, $patterns)) {
                $matches[] = $entry;
            }
        }

        return $matches;
    }
}
